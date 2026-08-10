<?php

namespace App\Http\Controllers\Api\V2\Mobile;

use App\Http\Controllers\Controller;
use App\Models\V2\AttendanceEvent;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\Student;
use App\Models\V2\StudentMobileProfile;
use App\Services\Conversations\ConversationService;
use App\Services\Integration\EduAdminConnectorFactory;
use App\Services\Integration\SyncCoordinator;
use App\Services\Realtime\MobileRealtimeBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class StudentController extends Controller
{
    private const MAX_PARENT_ACCOUNTS_PER_STUDENT = 2;

    public function index(Request $request): JsonResponse
    {
        $links = $this->activeLinksForParent($this->parent($request))
            ->with(['student.school', 'student.class'])
            ->orderByDesc('is_primary_contact')
            ->latest('linked_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $links->map(fn (ParentStudentLink $link) => $this->linkPayload($link))->values(),
        ]);
    }

    public function link(
        Request $request,
        ConversationService $conversations,
        MobileRealtimeBroadcaster $realtime
    ): JsonResponse {
        $parent = $this->parent($request);

        $validated = $request->validate([
            'linking_code' => ['required', 'string', 'max:512'],
            'student_number' => ['nullable', 'string', 'max:120'],
        ]);

        $linkPayload = $this->parseLinkingPayload((string) $validated['linking_code']);
        $code = $linkPayload['code'];
        $studentNumber = trim((string) ($validated['student_number'] ?? $linkPayload['student_number'] ?? ''));

        if ($code === '') {
            throw ValidationException::withMessages([
                'linking_code' => 'The linking code is invalid.',
            ]);
        }

        $query = ParentStudentLink::query()
            ->where(function ($query) use ($code): void {
                $query
                    ->where('linking_code', $code)
                    ->orWhereRaw(
                        "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(`linking_code`, '-', ''), ' ', ''), '_', ''), '.', '')) = ?",
                        [$code]
                    );
            })
            ->whereIn('status', ['pending', 'verified', 'active'])
            ->with(['student.school', 'student.class']);

        if ($studentNumber !== '') {
            $query->whereHas(
                'student',
                fn ($studentQuery) => $studentQuery->whereRaw('UPPER(`student_number`) = ?', [strtoupper($studentNumber)])
            );
        }

        $matches = $query->get();

        if ($matches->isEmpty()) {
            throw ValidationException::withMessages([
                'linking_code' => 'The linking code is invalid.',
            ]);
        }

        $availableMatches = $matches
            ->filter(fn (ParentStudentLink $link) => $link->student && $link->student->isAvailableInMobile())
            ->values();

        if ($availableMatches->isEmpty()) {
            throw ValidationException::withMessages([
                'linking_code' => 'This student is not available in the mobile app.',
            ]);
        }

        if ($availableMatches->pluck('student_id')->unique()->count() > 1) {
            throw ValidationException::withMessages([
                'student_number' => 'Student number is required because this code matches more than one child.',
            ]);
        }

        $sourceLink = $this->bestLinkCandidateForParent($availableMatches, $parent);

        $link = DB::transaction(function () use ($parent, $sourceLink): ParentStudentLink {
            $existingLink = ParentStudentLink::query()
                ->where('student_id', $sourceLink->student_id)
                ->where('parent_account_id', $parent->id)
                ->lockForUpdate()
                ->first();

            if ($existingLink) {
                return $this->activateLinkForParent($existingLink, $parent);
            }

            $activeParentCount = ParentStudentLink::query()
                ->where('student_id', $sourceLink->student_id)
                ->where('status', 'active')
                ->whereNotNull('parent_account_id')
                ->distinct('parent_account_id')
                ->count('parent_account_id');

            if ($activeParentCount >= self::MAX_PARENT_ACCOUNTS_PER_STUDENT) {
                throw ValidationException::withMessages([
                    'linking_code' => 'This child already has the maximum of two parent accounts linked.',
                ]);
            }

            $link = $this->linkRecordForParent($sourceLink, $parent);

            return $this->activateLinkForParent($link, $parent);
        });

        if (! $parent->phone_verified_at) {
            $parent->forceFill(['phone_verified_at' => now()])->save();
        }

        $link = $link->refresh()->load(['parentAccount', 'student.school', 'student.class']);
        $threads = collect();

        try {
            $threads = $conversations->ensureDefaultThreadsForLink($link);
            $realtime->childLinked($link, $threads);
        } catch (Throwable $exception) {
            Log::warning('Mobile child link post-processing failed after successful link.', [
                'parent_account_id' => $parent->id,
                'parent_student_link_id' => $link->id,
                'student_id' => $link->student_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Student linked.',
            'data' => $this->linkPayload($link->refresh()->load(['student.school', 'student.class'])),
        ], 201);
    }

    private function activateLinkForParent(ParentStudentLink $link, ParentAccount $parent): ParentStudentLink
    {
        $link->forceFill([
            'parent_account_id' => $parent->id,
            'parent_phone' => $parent->phone,
            'status' => 'active',
            'requested_at' => $link->requested_at ?? now(),
            'verified_at' => $link->verified_at ?? now(),
            'linked_at' => $link->linked_at ?? now(),
        ])->save();

        return $link;
    }

    private function bestLinkCandidateForParent($matches, ParentAccount $parent): ParentStudentLink
    {
        $alreadyLinked = $matches->first(fn (ParentStudentLink $link) => (int) $link->parent_account_id === (int) $parent->id);

        if ($alreadyLinked) {
            return $alreadyLinked;
        }

        $unclaimed = $matches->first(fn (ParentStudentLink $link) => $link->parent_account_id === null);

        return $unclaimed ?: $matches->first();
    }

    private function linkRecordForParent(ParentStudentLink $sourceLink, ParentAccount $parent): ParentStudentLink
    {
        $sourceLink = ParentStudentLink::query()
            ->lockForUpdate()
            ->findOrFail($sourceLink->id);

        if ($sourceLink->parent_account_id === null || (int) $sourceLink->parent_account_id === (int) $parent->id) {
            return $sourceLink;
        }

        return new ParentStudentLink([
            'tenant_id' => $sourceLink->tenant_id,
            'school_id' => $sourceLink->school_id,
            'student_id' => $sourceLink->student_id,
            'parent_phone' => $parent->phone,
            'linking_code' => $sourceLink->linking_code,
            'relationship' => $sourceLink->relationship,
            'relationship_description' => $sourceLink->relationship_description,
            'is_primary_contact' => false,
            'can_pick_up' => $sourceLink->can_pick_up,
            'emergency_contact' => false,
            'communication_preferences' => $sourceLink->communication_preferences,
            'source_system' => 'local',
        ]);
    }

    public function show(Request $request, Student $student): JsonResponse
    {
        $this->authorizeLinkedStudent($this->parent($request), $student);

        $student->load(['school', 'class']);

        return response()->json([
            'status' => 'success',
            'data' => [
                'student' => $this->studentPayload($student),
                'recent_attendance' => AttendanceEvent::query()
                    ->with('device')
                    ->where('student_id', $student->id)
                    ->latest('event_time')
                    ->limit(10)
                    ->get()
                    ->map(fn (AttendanceEvent $event) => $this->attendancePayload($event))
                    ->values(),
            ],
        ]);
    }

    public function profile(Request $request, Student $student): JsonResponse
    {
        $this->authorizeLinkedStudent($this->parent($request), $student);
        $this->refreshLinkedStudentSnapshot($student);

        $student->load(['school', 'class']);

        $snapshot = StudentMobileProfile::query()
            ->where('tenant_id', $student->tenant_id)
            ->where('student_id', $student->id)
            ->first();

        return response()->json([
            'status' => 'success',
            'data' => [
                'student' => $this->studentPayload($student),
                'profile' => $snapshot?->profile ?: $this->standaloneProfilePayload($student),
                'source_system' => $snapshot?->source_system ?? 'standalone',
                'synced_at' => $snapshot?->updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function attendance(Request $request, Student $student): JsonResponse
    {
        $this->authorizeLinkedStudent($this->parent($request), $student);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'event_type' => ['nullable', 'string', 'max:60'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AttendanceEvent::query()
            ->with('device')
            ->where('student_id', $student->id)
            ->latest('event_time');

        if (! empty($validated['date_from'])) {
            $query->where('event_time', '>=', Carbon::parse($validated['date_from'])->startOfDay());
        }

        if (! empty($validated['date_to'])) {
            $query->where('event_time', '<=', Carbon::parse($validated['date_to'])->endOfDay());
        }

        if (! empty($validated['event_type'])) {
            $query->where('event_type', $validated['event_type']);
        }

        return response()->json([
            'status' => 'success',
            'data' => $query
                ->limit((int) ($validated['limit'] ?? 30))
                ->get()
                ->map(fn (AttendanceEvent $event) => $this->attendancePayload($event))
                ->values(),
        ]);
    }

    private function parent(Request $request): ParentAccount
    {
        /** @var ParentAccount $parent */
        $parent = $request->user();

        return $parent;
    }

    private function activeLinksForParent(ParentAccount $parent)
    {
        return ParentStudentLink::query()
            ->where('parent_account_id', $parent->id)
            ->where('status', 'active')
            ->whereHas('student', fn ($query) => $query
                ->where('mobile_visible', true)
                ->whereIn('status', Student::MOBILE_VISIBLE_STATUSES));
    }

    private function parseLinkingPayload(string $value): array
    {
        $raw = trim($value);
        $codeCandidate = $raw;
        $studentNumber = null;

        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            $codeCandidate = (string) ($json['code']
                ?? $json['linking_code']
                ?? $json['link_code']
                ?? $json['linkCode']
                ?? '');
            $studentNumber = $json['student_number']
                ?? $json['studentNumber']
                ?? $json['student']
                ?? null;
        } else {
            $parts = parse_url($raw);

            if (is_array($parts)) {
                $queryParams = [];

                if (! empty($parts['query'])) {
                    parse_str($parts['query'], $queryParams);
                }

                if (! empty($parts['fragment']) && str_contains($parts['fragment'], '=')) {
                    $fragmentParams = [];
                    parse_str($parts['fragment'], $fragmentParams);
                    $queryParams = array_merge($queryParams, $fragmentParams);
                }

                if ($queryParams !== []) {
                    $codeCandidate = (string) ($queryParams['code']
                        ?? $queryParams['linking_code']
                        ?? $queryParams['link_code']
                        ?? $queryParams['linkCode']
                        ?? $codeCandidate);
                    $studentNumber = $queryParams['student_number']
                        ?? $queryParams['studentNumber']
                        ?? $queryParams['student']
                        ?? $studentNumber;
                }

                if ($this->normalizedLinkingCode($codeCandidate) === '' && ! empty($parts['path'])) {
                    $segments = array_values(array_filter(explode('/', trim($parts['path'], '/'))));
                    $codeCandidate = (string) end($segments);
                }
            }
        }

        return [
            'code' => $this->normalizedLinkingCode($codeCandidate),
            'student_number' => is_string($studentNumber) ? trim($studentNumber) : null,
        ];
    }

    private function normalizedLinkingCode(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');
    }

    private function authorizeLinkedStudent(ParentAccount $parent, Student $student): void
    {
        if (! $student->isAvailableInMobile()) {
            abort(404);
        }

        $allowed = $this->activeLinksForParent($parent)
            ->where('student_id', $student->id)
            ->exists();

        if (! $allowed) {
            abort(404);
        }
    }

    private function linkPayload(ParentStudentLink $link): array
    {
        return [
            'id' => $link->id,
            'relationship' => $link->relationship,
            'relationship_description' => $link->relationship_description,
            'is_primary_contact' => $link->is_primary_contact,
            'can_pick_up' => $link->can_pick_up,
            'emergency_contact' => $link->emergency_contact,
            'status' => $link->status,
            'linked_at' => $link->linked_at?->toIso8601String(),
            'student' => $this->studentPayload($link->student),
        ];
    }

    private function studentPayload(?Student $student): ?array
    {
        if (! $student) {
            return null;
        }

        $school = $student->relationLoaded('school') ? $student->getRelation('school') : null;
        $class = $student->relationLoaded('class') ? $student->getRelation('class') : null;

        return [
            'id' => $student->id,
            'tenant_id' => $student->tenant_id,
            'school_id' => $student->school_id,
            'class_id' => $student->class_id,
            'student_number' => $student->student_number,
            'first_name' => $student->first_name,
            'middle_name' => $student->middle_name,
            'last_name' => $student->last_name,
            'full_name' => $student->full_name,
            'gender' => $student->gender,
            'date_of_birth' => $student->date_of_birth?->toDateString(),
            'photo_path' => $student->photo_path,
            'photo_url' => $this->studentPhotoUrl($student->photo_path),
            'status' => $student->status,
            'school' => $school ? [
                'id' => $school->id,
                'name' => $school->name,
                'code' => $school->code,
                'timezone' => $school->timezone,
            ] : null,
            'class' => $class ? [
                'id' => $class->id,
                'name' => $class->name,
                'full_name' => $class->full_name,
            ] : null,
        ];
    }

    private function studentPhotoUrl(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, 'data:image/')) {
            return $path;
        }

        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            return url($path);
        }

        return url('storage/' . $path);
    }

    private function refreshLinkedStudentSnapshot(Student $student): void
    {
        if ($student->source_system !== 'edu_admin' || blank($student->source_id)) {
            return;
        }

        $snapshot = StudentMobileProfile::query()
            ->where('tenant_id', $student->tenant_id)
            ->where('student_id', $student->id)
            ->first();
        $ttl = max(0, (int) config('educonnect.mobile.profile_refresh_ttl_seconds', 60));

        if ($ttl > 0 && $snapshot?->updated_at?->gt(now()->subSeconds($ttl))) {
            return;
        }

        $connection = IntegrationConnection::query()
            ->where('tenant_id', $student->tenant_id)
            ->where('status', 'active')
            ->first();

        if (! $connection) {
            return;
        }

        try {
            $connector = app(EduAdminConnectorFactory::class)->make($connection);
            $sync = app(SyncCoordinator::class);
            $filters = ['ids' => [$student->source_id]];

            foreach (['students', 'student_mobile_profiles'] as $resource) {
                $page = $connector->resource($resource, null, $filters);
                $sync->importResourceRecords($connection, $resource, $page['data'] ?? []);
            }
        } catch (Throwable $exception) {
            Log::warning('EduConnect on-demand student profile refresh failed.', [
                'student_id' => $student->id,
                'source_id' => $student->source_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function standaloneProfilePayload(Student $student): array
    {
        return [
            'id' => $student->id,
            'student_id' => $student->id,
            'school_id' => $student->school_id,
            'class_id' => $student->class_id,
            'academic_year_id' => null,
            'academic_year_name' => null,
            'term_number' => null,
            'generated_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'fees' => [
                'currency' => 'XAF',
                'status' => 'not_configured',
                'total_due' => 0.0,
                'total_paid' => 0.0,
                'balance' => 0.0,
                'registration_due' => 0.0,
                'school_fee_due' => 0.0,
                'schedule' => null,
                'installments' => [],
                'payments' => [],
            ],
            'academics' => [
                'current_average' => null,
                'current_percentage' => null,
                'class_position' => null,
                'total_students' => null,
                'class_average' => null,
                'term_number' => null,
                'promotion' => null,
                'comment' => null,
                'recommendations' => null,
                'subjects' => [],
                'latest_report' => null,
            ],
            'report_cards' => [],
            'timetable' => [],
            'discipline' => [
                'today' => null,
                'month_summary' => [
                    'present' => 0,
                    'late' => 0,
                    'absent' => 0,
                    'excused' => 0,
                    'sick' => 0,
                    'permission' => 0,
                ],
                'recent_attendance' => [],
                'incidents' => [],
            ],
        ];
    }

    private function attendancePayload(AttendanceEvent $event): array
    {
        return [
            'id' => $event->id,
            'event_key' => $event->event_key,
            'event_type' => $event->event_type,
            'event_time' => $event->event_time?->toIso8601String(),
            'confidence_score' => $event->confidence_score !== null ? (float) $event->confidence_score : null,
            'verify_status' => $event->verify_status,
            'processing_status' => $event->processing_status,
            'device' => $event->device ? [
                'id' => $event->device->id,
                'name' => $event->device->name,
                'device_uid' => $event->device->device_uid,
                'location' => $event->device->location,
            ] : null,
        ];
    }
}
