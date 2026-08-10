<?php

namespace App\Services\Integration;

use App\Contracts\EduAdminConnector;
use App\Models\V2\AcademicClass;
use App\Models\V2\AcademicYear;
use App\Models\V2\EducationOption;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\IntegrationSyncItem;
use App\Models\V2\IntegrationSyncRun;
use App\Models\V2\MobileMessage;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\School;
use App\Models\V2\Section;
use App\Models\V2\Stream;
use App\Models\V2\Student;
use App\Models\V2\StudentMobileProfile;
use App\Models\V2\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncCoordinator
{
    private const SOURCE = 'edu_admin';

    private const INITIAL_RESOURCES = [
        'schools',
        'academic_years',
        'sections',
        'education_options',
        'streams',
        'classes',
        'students',
        'parent_links',
        'mobile_messages',
        'student_mobile_profiles',
    ];

    private const INCREMENTAL_RESOURCES = self::INITIAL_RESOURCES;

    public function __construct(private readonly MappingService $mappings) {}

    public function runInitialSync(
        IntegrationConnection $connection,
        EduAdminConnector $connector,
        array $context = []
    ): IntegrationSyncRun {
        $run = IntegrationSyncRun::query()->create([
            'connection_id' => $connection->id,
            'sync_type' => 'initial',
            'direction' => 'pull',
            'status' => 'running',
            'started_at' => now(),
            'triggered_by_type' => $context['triggered_by_type'] ?? null,
            'triggered_by_id' => $context['triggered_by_id'] ?? null,
            'metadata' => $context['metadata'] ?? null,
        ]);

        try {
            DB::transaction(function () use ($connection, $connector, $run): void {
                $this->syncTenant($connection, $connector->bootstrap());

                foreach (self::INITIAL_RESOURCES as $resource) {
                    $this->syncResource($connection, $connector, $run, $resource);
                }
            });

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
            ]);

            $connection->forceFill([
                'last_successful_sync_at' => now(),
                'last_error' => null,
            ])->save();

            app(IntegrationAuditLogger::class)->syncCompleted($run->refresh());
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            $connection->forceFill([
                'last_failed_sync_at' => now(),
                'last_error' => $exception->getMessage(),
            ])->save();

            app(IntegrationAuditLogger::class)->syncFailed($run->refresh(), $exception->getMessage());

            throw $exception;
        }

        return $run->refresh();
    }

    public function runIncrementalSync(
        IntegrationConnection $connection,
        EduAdminConnector $connector,
        array $context = []
    ): IntegrationSyncRun {
        $updatedAfter = ($context['updated_after'] ?? null) ?: $connection->last_successful_sync_at?->toIso8601String();
        $resources = ($context['resources'] ?? null) ?: self::INCREMENTAL_RESOURCES;
        $cursorBefore = [
            'updated_after' => $updatedAfter,
            'cursor' => $context['cursor'] ?? null,
            'resources' => array_values($resources),
        ];

        $run = IntegrationSyncRun::query()->create([
            'connection_id' => $connection->id,
            'sync_type' => 'incremental',
            'direction' => 'pull',
            'status' => 'running',
            'started_at' => now(),
            'cursor_before' => $this->json($cursorBefore),
            'triggered_by_type' => $context['triggered_by_type'] ?? null,
            'triggered_by_id' => $context['triggered_by_id'] ?? null,
            'metadata' => $context['metadata'] ?? null,
        ]);

        try {
            $resourceCursors = [];

            DB::transaction(function () use ($connection, $connector, $run, $resources, $updatedAfter, $context, &$resourceCursors): void {
                $filters = array_filter([
                    'updated_after' => $updatedAfter,
                ], fn ($value) => $value !== null && $value !== '');

                foreach ($resources as $resource) {
                    $resourceCursors[$resource] = $this->syncResource(
                        $connection,
                        $connector,
                        $run,
                        $resource,
                        $context['cursor'] ?? null,
                        $filters
                    );
                }
            });

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'cursor_after' => $this->json($resourceCursors),
                'metadata' => array_merge($run->metadata ?? [], [
                    'resource_cursors' => $resourceCursors,
                    'updated_after' => $updatedAfter,
                ]),
            ]);

            $connection->forceFill([
                'last_successful_sync_at' => now(),
                'last_error' => null,
            ])->save();

            app(IntegrationAuditLogger::class)->syncCompleted($run->refresh());
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);

            $connection->forceFill([
                'last_failed_sync_at' => now(),
                'last_error' => $exception->getMessage(),
            ])->save();

            app(IntegrationAuditLogger::class)->syncFailed($run->refresh(), $exception->getMessage());

            throw $exception;
        }

        return $run->refresh();
    }

    public function importResourceRecords(IntegrationConnection $connection, string $resource, iterable $records): array
    {
        $stats = [
            'read' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
        ];

        foreach ($records as $record) {
            if (! is_array($record)) {
                $stats['failed']++;
                continue;
            }

            $stats['read']++;
            $model = $this->upsertResourceRecord($connection, $resource, $record);

            if ($model instanceof Model) {
                $stats[$model->wasRecentlyCreated ? 'created' : 'updated']++;
            } else {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    private function syncTenant(IntegrationConnection $connection, array $bootstrap): void
    {
        $complex = $bootstrap['complex'] ?? [];
        $tenant = $connection->tenant;

        if (! $tenant instanceof Tenant || empty($complex['id'])) {
            return;
        }

        $tenant->fill([
            'name' => $complex['name'] ?? $tenant->name,
            'slug' => $complex['slug'] ?? $tenant->slug,
            'code' => $complex['code'] ?? $tenant->code,
            'status' => $complex['status'] ?? $tenant->status,
            'source_system' => self::SOURCE,
            'source_id' => (string) $complex['id'],
        ])->save();

        $this->mappings->upsert($connection, 'tenant', $tenant->id, 'tenant', $complex['id'], $this->checksum($complex));
    }

    private function syncResource(
        IntegrationConnection $connection,
        EduAdminConnector $connector,
        IntegrationSyncRun $run,
        string $resource,
        ?string $initialCursor = null,
        array $filters = []
    ): ?string {
        $cursor = $initialCursor;
        $lastCursor = $cursor;

        do {
            $page = $connector->resource($resource, $cursor, $filters);
            $records = $page['data'] ?? [];

            foreach ($records as $record) {
                $run->increment('records_read');
                $model = $this->upsertResourceRecord($connection, $resource, $record);

                if ($model instanceof Model) {
                    $action = $model->wasRecentlyCreated ? 'created' : 'updated';
                    $run->increment($model->wasRecentlyCreated ? 'records_created' : 'records_updated');
                    $this->recordSyncItem($run, $resource, $record, $model, $action, 'success');
                } else {
                    $run->increment('records_failed');
                    $this->recordSyncItem($run, $resource, $record, null, 'skipped', 'failed');
                }
            }

            $cursor = $page['next_cursor'] ?? null;
            $lastCursor = $cursor
                ?? collect($records)->pluck('id')->filter(fn ($id) => $id !== null && $id !== '')->last()
                ?? $lastCursor;
        } while (($page['has_more'] ?? false) && $cursor);

        return $lastCursor ? (string) $lastCursor : null;
    }

    private function upsertResourceRecord(IntegrationConnection $connection, string $resource, array $record): ?Model
    {
        return match ($resource) {
            'schools' => $this->upsertSchool($connection, $record),
            'academic_years' => $this->upsertAcademicYear($connection, $record),
            'sections' => $this->upsertSection($connection, $record),
            'education_options' => $this->upsertEducationOption($connection, $record),
            'streams' => $this->upsertStream($connection, $record),
            'classes' => $this->upsertClass($connection, $record),
            'students' => $this->upsertStudent($connection, $record),
            'parent_links' => $this->upsertParentLink($connection, $record),
            'mobile_messages' => $this->upsertMobileMessage($connection, $record),
            'student_mobile_profiles' => $this->upsertStudentMobileProfile($connection, $record),
            default => null,
        };
    }

    private function upsertSchool(IntegrationConnection $connection, array $record): School
    {
        $school = School::query()->updateOrCreate(
            $this->sourceKey($record),
            [
                'tenant_id' => $connection->tenant_id,
                'name' => $record['name'],
                'slug' => $record['slug'] ?? str($record['name'])->slug()->toString(),
                'code' => $record['code'] ?? null,
                'type' => $record['type'] ?? null,
                'phone' => $record['phone'] ?? null,
                'email' => $record['email'] ?? null,
                'address' => $record['address'] ?? null,
                'city' => $record['city'] ?? null,
                'timezone' => $record['timezone'] ?? 'Africa/Douala',
                'status' => $record['status'] ?? 'active',
                'source_updated_at' => $this->date($record['updated_at'] ?? null),
                'settings' => $record['settings'] ?? null,
            ]
        );

        $this->map($connection, 'school', $school, $record);

        return $school;
    }

    private function upsertAcademicYear(IntegrationConnection $connection, array $record): ?AcademicYear
    {
        $schoolId = $this->localId($connection, 'school', $record['school_id'] ?? null);

        $academicYear = AcademicYear::query()->updateOrCreate(
            $this->sourceKey($record),
            [
                'tenant_id' => $connection->tenant_id,
                'school_id' => $schoolId,
                'name' => $record['name'],
                'start_date' => $record['start_date'] ?? null,
                'end_date' => $record['end_date'] ?? null,
                'is_current' => (bool) ($record['is_current'] ?? false),
                'status' => $record['status'] ?? 'active',
                'source_updated_at' => $this->date($record['updated_at'] ?? null),
            ]
        );

        $this->map($connection, 'academic_year', $academicYear, $record);

        return $academicYear;
    }

    private function upsertSection(IntegrationConnection $connection, array $record): ?Section
    {
        $schoolId = $this->localId($connection, 'school', $record['school_id'] ?? null);

        if (! $schoolId) {
            return null;
        }

        $section = Section::query()->updateOrCreate(
            $this->sourceKey($record),
            [
                'tenant_id' => $connection->tenant_id,
                'school_id' => $schoolId,
                'name' => $record['name'],
                'code' => $record['code'] ?? null,
                'sort_order' => (int) ($record['sort_order'] ?? 0),
                'status' => $record['status'] ?? 'active',
                'source_updated_at' => $this->date($record['updated_at'] ?? null),
            ]
        );

        $this->map($connection, 'section', $section, $record);

        return $section;
    }

    private function upsertEducationOption(IntegrationConnection $connection, array $record): ?EducationOption
    {
        $schoolId = $this->localId($connection, 'school', $record['school_id'] ?? null);
        $sectionId = $this->localId($connection, 'section', $record['section_id'] ?? null);

        if (! $schoolId || ! $sectionId) {
            return null;
        }

        $option = EducationOption::query()->updateOrCreate(
            $this->sourceKey($record),
            [
                'tenant_id' => $connection->tenant_id,
                'school_id' => $schoolId,
                'section_id' => $sectionId,
                'name' => $record['name'],
                'code' => $record['code'] ?? null,
                'sort_order' => (int) ($record['sort_order'] ?? 0),
                'status' => $record['status'] ?? 'active',
                'source_updated_at' => $this->date($record['updated_at'] ?? null),
            ]
        );

        $this->map($connection, 'education_option', $option, $record);

        return $option;
    }

    private function upsertStream(IntegrationConnection $connection, array $record): ?Stream
    {
        $schoolId = $this->localId($connection, 'school', $record['school_id'] ?? null);
        $sectionId = $this->localId($connection, 'section', $record['section_id'] ?? null);
        $educationOptionId = $this->localId($connection, 'education_option', $record['education_option_id'] ?? null);

        if (! $schoolId || ! $sectionId) {
            return null;
        }

        $stream = Stream::query()->updateOrCreate(
            $this->sourceKey($record),
            [
                'tenant_id' => $connection->tenant_id,
                'school_id' => $schoolId,
                'section_id' => $sectionId,
                'education_option_id' => $educationOptionId,
                'name' => $record['name'],
                'display_name' => $record['display_name'] ?? null,
                'grade_level' => $record['grade_level'] ?? null,
                'sort_order' => (int) ($record['sort_order'] ?? 0),
                'status' => $record['status'] ?? 'active',
                'source_updated_at' => $this->date($record['updated_at'] ?? null),
            ]
        );

        $this->map($connection, 'stream', $stream, $record);

        return $stream;
    }

    private function upsertClass(IntegrationConnection $connection, array $record): ?AcademicClass
    {
        $schoolId = $this->localId($connection, 'school', $record['school_id'] ?? null);
        $streamId = $this->localId($connection, 'stream', $record['stream_id'] ?? null);

        if (! $schoolId || ! $streamId) {
            return null;
        }

        $class = AcademicClass::query()->updateOrCreate(
            $this->sourceKey($record),
            [
                'tenant_id' => $connection->tenant_id,
                'school_id' => $schoolId,
                'stream_id' => $streamId,
                'name' => $record['name'],
                'full_name' => $record['full_name'] ?? $record['name'],
                'capacity' => (int) ($record['capacity'] ?? 0),
                'current_enrollment' => (int) ($record['current_enrollment'] ?? 0),
                'class_teacher_name' => $record['class_teacher_name'] ?? null,
                'class_teacher_external_id' => $record['class_teacher_external_id'] ?? null,
                'status' => $record['status'] ?? 'active',
                'source_updated_at' => $this->date($record['updated_at'] ?? null),
            ]
        );

        $this->map($connection, 'class', $class, $record);

        return $class;
    }

    private function upsertStudent(IntegrationConnection $connection, array $record): ?Student
    {
        $schoolId = $this->localId($connection, 'school', $record['school_id'] ?? null);
        $classId = $this->localId($connection, 'class', $record['class_id'] ?? null);

        if (! $schoolId) {
            return null;
        }

        $student = Student::query()->updateOrCreate(
            $this->sourceKey($record),
            [
                'tenant_id' => $connection->tenant_id,
                'school_id' => $schoolId,
                'class_id' => $classId,
                'student_number' => $record['student_number'],
                'first_name' => $record['first_name'],
                'last_name' => $record['last_name'],
                'middle_name' => $record['middle_name'] ?? null,
                'date_of_birth' => $record['date_of_birth'] ?? null,
                'gender' => $record['gender'] ?? null,
                'photo_path' => $record['photo_url'] ?? $record['photo_path'] ?? null,
                'photo_hash' => $record['photo_hash'] ?? null,
                'biometric_identifier' => $record['biometric_identifier'] ?? $record['biometric_id'] ?? null,
                'status' => $record['status'] ?? 'active',
                'parent_name' => $record['parent_name'] ?? null,
                'parent_phone' => $record['parent_phone'] ?? null,
                'parent_email' => $record['parent_email'] ?? null,
                'emergency_contact_name' => $record['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $record['emergency_contact_phone'] ?? null,
                'source_updated_at' => $this->date($record['updated_at'] ?? null),
                'device_sync_enabled' => (bool) ($record['device_sync_enabled'] ?? true),
                'mobile_visible' => (bool) ($record['mobile_visible'] ?? true),
            ]
        );

        $this->map($connection, 'student', $student, $record);

        return $student;
    }

    private function upsertParentLink(IntegrationConnection $connection, array $record): ?ParentStudentLink
    {
        $schoolId = $this->localId($connection, 'school', $record['school_id'] ?? null);
        $studentId = $this->localId($connection, 'student', $record['student_id'] ?? null);

        if (! $schoolId || ! $studentId) {
            return null;
        }

        $sourceKey = $this->sourceKey($record);
        $existingLink = ParentStudentLink::query()->where($sourceKey)->first();
        $incomingStatus = $record['status'] ?? 'verified';
        $status = $existingLink?->parent_account_id
            && $existingLink->status === 'active'
            && in_array($incomingStatus, ['pending', 'verified'], true)
                ? 'active'
                : $incomingStatus;

        $link = ParentStudentLink::query()->updateOrCreate(
            $sourceKey,
            [
                'tenant_id' => $connection->tenant_id,
                'school_id' => $schoolId,
                'student_id' => $studentId,
                'parent_phone' => $existingLink?->parent_account_id ? $existingLink->parent_phone : $record['parent_phone'],
                'linking_code' => $record['linking_code'] ?? null,
                'relationship' => $record['relationship'] ?? 'parent',
                'relationship_description' => $record['relationship_description'] ?? null,
                'is_primary_contact' => (bool) ($record['is_primary_contact'] ?? false),
                'can_pick_up' => (bool) ($record['can_pick_up'] ?? true),
                'emergency_contact' => (bool) ($record['emergency_contact'] ?? false),
                'communication_preferences' => $record['communication_preferences'] ?? null,
                'status' => $status,
                'requested_at' => $existingLink?->parent_account_id ? $existingLink->requested_at : $this->date($record['requested_at'] ?? null),
                'verified_at' => $existingLink?->parent_account_id ? $existingLink->verified_at : $this->date($record['verified_at'] ?? null),
                'linked_at' => $existingLink?->parent_account_id ? $existingLink->linked_at : $this->date($record['linked_at'] ?? null),
                'source_updated_at' => $this->date($record['updated_at'] ?? null),
            ]
        );

        $this->map($connection, 'parent_student_link', $link, $record);

        return $link;
    }

    private function upsertMobileMessage(IntegrationConnection $connection, array $record): ?MobileMessage
    {
        $schoolId = $this->localId($connection, 'school', $record['school_id'] ?? null);
        $academicYearId = $this->localId($connection, 'academic_year', $record['academic_year_id'] ?? null);

        if (! $schoolId) {
            return null;
        }

        $message = MobileMessage::withTrashed()->updateOrCreate(
            $this->sourceKey($record),
            [
                'tenant_id' => $connection->tenant_id,
                'school_id' => $schoolId,
                'academic_year_id' => $academicYearId,
                'sender_type' => $record['sender_type'] ?? 'edu_admin',
                'sender_name' => $record['sender_name'] ?? null,
                'category' => $record['category'] ?? 'general',
                'priority' => $record['priority'] ?? 'normal',
                'title' => $record['title'],
                'body' => $record['body'],
                'audience_type' => $this->mobileMessageAudienceType((string) ($record['audience_type'] ?? 'parents')),
                'audience_filters' => $this->localAudienceFilters($connection, $record),
                'status' => $this->mobileMessageStatus((string) ($record['status'] ?? 'draft')),
                'published_at' => $this->date($record['published_at'] ?? $record['sent_at'] ?? null),
                'expires_at' => $this->date($record['expires_at'] ?? null),
            ]
        );

        if ($message->trashed() && empty($record['deleted_at'])) {
            $message->restore();
        }

        if (! empty($record['deleted_at'])) {
            $message->delete();
        }

        $this->map($connection, 'mobile_message', $message, $record);

        return $message;
    }

    private function upsertStudentMobileProfile(IntegrationConnection $connection, array $record): ?StudentMobileProfile
    {
        $studentId = $this->localId($connection, 'student', $record['student_id'] ?? $record['id'] ?? null);

        if (! $studentId) {
            return null;
        }

        $student = Student::query()->find($studentId);
        $schoolId = $this->localId($connection, 'school', $record['school_id'] ?? null) ?: $student?->school_id;

        if (! $schoolId || ! $student) {
            return null;
        }

        $profile = StudentMobileProfile::query()->updateOrCreate(
            [
                'tenant_id' => $connection->tenant_id,
                'student_id' => $studentId,
            ],
            [
                'school_id' => $schoolId,
                'profile' => $record,
                'source_system' => self::SOURCE,
                'source_id' => (string) ($record['id'] ?? $record['student_id']),
                'source_updated_at' => $this->date($record['updated_at'] ?? $record['generated_at'] ?? null),
            ]
        );

        $this->map($connection, 'student_mobile_profile', $profile, $record);

        return $profile;
    }

    private function sourceKey(array $record): array
    {
        return [
            'source_system' => self::SOURCE,
            'source_id' => (string) $record['id'],
        ];
    }

    private function map(IntegrationConnection $connection, string $type, Model $model, array $record): void
    {
        $this->mappings->upsert(
            $connection,
            $type,
            (int) $model->getKey(),
            $type,
            $record['id'],
            $this->checksum($record),
            $this->date($record['updated_at'] ?? null)
        );
    }

    private function localId(IntegrationConnection $connection, string $type, mixed $externalId): ?int
    {
        if ($externalId === null || $externalId === '') {
            return null;
        }

        return $this->mappings->findLocalId($connection, $type, $externalId);
    }

    private function checksum(array $record): string
    {
        return hash('sha256', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function localAudienceFilters(IntegrationConnection $connection, array $record): ?array
    {
        $filters = $record['audience_filters'] ?? [];

        if (! is_array($filters)) {
            return null;
        }

        if (isset($filters['class_id'])) {
            $filters['class_ids'] = [$filters['class_id']];
            unset($filters['class_id']);
        }

        if (isset($filters['student_id'])) {
            $filters['student_ids'] = [$filters['student_id']];
            unset($filters['student_id']);
        }

        foreach (['class_ids' => 'class', 'student_ids' => 'student'] as $filterKey => $externalType) {
            if (! array_key_exists($filterKey, $filters)) {
                continue;
            }

            $filters[$filterKey] = collect((array) $filters[$filterKey])
                ->map(fn ($externalId) => $this->localId($connection, $externalType, $externalId))
                ->filter()
                ->values()
                ->all();
        }

        return empty($filters) ? null : $filters;
    }

    private function mobileMessageAudienceType(string $audienceType): string
    {
        return match ($audienceType) {
            'class_parents', 'classes', 'class' => 'classes',
            'students', 'linked_students' => 'students',
            'phones', 'parent_phones' => 'phones',
            default => 'parents',
        };
    }

    private function mobileMessageStatus(string $status): string
    {
        return match ($status) {
            'sent', 'published' => 'published',
            'scheduled' => 'scheduled',
            'cancelled', 'archived' => 'archived',
            default => 'draft',
        };
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function date(mixed $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }

    private function recordSyncItem(
        IntegrationSyncRun $run,
        string $resource,
        array $record,
        ?Model $model,
        string $action,
        string $status
    ): void {
        IntegrationSyncItem::query()->create([
            'sync_run_id' => $run->id,
            'local_type' => $model ? $this->resourceToType($resource) : null,
            'local_id' => $model?->getKey(),
            'external_type' => $this->resourceToType($resource),
            'external_id' => isset($record['id']) ? (string) $record['id'] : null,
            'action' => $action,
            'status' => $status,
        ]);
    }

    private function resourceToType(string $resource): string
    {
        return match ($resource) {
            'schools' => 'school',
            'academic_years' => 'academic_year',
            'sections' => 'section',
            'education_options' => 'education_option',
            'streams' => 'stream',
            'classes' => 'class',
            'students' => 'student',
            'parent_links' => 'parent_student_link',
            'mobile_messages' => 'mobile_message',
            'student_mobile_profiles' => 'student_mobile_profile',
            default => $resource,
        };
    }
}
