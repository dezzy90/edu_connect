<?php

namespace App\Services\Auth;

use App\Models\AdminUser;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ReviewerDemoProvisioner
{
    public function __construct(private ReviewerDemoAccess $access, private MobileOtpPhone $phones) {}

    /** Preview is read-only. Re-running never adopts or repairs an existing identity. */
    public function plan(string $marker): array
    {
        if (! Str::isUuid($marker)) {
            throw new RuntimeException('A stable UUID marker is required.');
        }
        $parents = $this->phones->matching(ParentAccount::withTrashed(), 'phone', ReviewerDemoAccess::PHONE)->get();
        $tenants = Tenant::withTrashed()->where('slug', ReviewerDemoAccess::SLUG)
            ->orWhere('source_system', ReviewerDemoAccess::SOURCE)->orWhere('source_id', $marker)->get();
        if ($parents->isNotEmpty() || $tenants->isNotEmpty()) {
            if ($parents->count() !== 1 || $tenants->count() !== 1
                || ! $this->access->isolated($parents->first(), $tenants->first(), $marker)) {
                throw new RuntimeException('Identity collision or demo drift; no records were changed.');
            }

            return ['action' => 'unchanged', 'tenant_id' => $tenants->first()->id, 'parent_id' => $parents->first()->id];
        }

        foreach ([[Student::withTrashed(), 'parent_phone'], [Student::withTrashed(), 'emergency_contact_phone'],
            [ParentStudentLink::withTrashed(), 'parent_phone'], [AdminUser::query(), 'phone']] as [$query, $column]) {
            if ($this->phones->matching($query, $column, ReviewerDemoAccess::PHONE)->exists()) {
                throw new RuntimeException('Reserved phone collision; no records were changed.');
            }
        }
        if (DB::table('ec_schools')->where('slug', 'like', ReviewerDemoAccess::SLUG.'%')->exists()) {
            throw new RuntimeException('Reserved school slug collision; no records were changed.');
        }

        return ['action' => 'create', 'tenants' => 1, 'parents' => 1, 'schools' => 3, 'students' => 3];
    }

    /** All records are invented here. Query-builder inserts deliberately emit no model events/jobs. */
    public function provision(string $marker, bool $rollback): array
    {
        return DB::transaction(function () use ($marker, $rollback): array {
            $plan = $this->plan($marker);
            if ($plan['action'] === 'unchanged') {
                return $plan;
            }

            $tenantId = $this->insert('ec_tenants', [
                'name' => 'Google Play Demo - Synthetic School Complex', 'slug' => ReviewerDemoAccess::SLUG,
                'source_system' => ReviewerDemoAccess::SOURCE, 'source_id' => $marker, 'status' => 'active',
                'settings' => $this->json([ReviewerDemoAccess::SETTING => ['marker' => $marker, 'synthetic' => true]]),
            ]);
            $parentId = $this->insert('ec_parent_accounts', [
                'phone' => ReviewerDemoAccess::PHONE, 'first_name' => 'Demo', 'last_name' => 'Parent',
                'status' => 'active', 'preferred_language' => 'en',
                'settings' => $this->json([ReviewerDemoAccess::SETTING => ['marker' => $marker, 'tenant_id' => $tenantId]]),
            ]);

            foreach (['secondary', 'primary', 'nursery'] as $index => $level) {
                $identity = ['source_system' => ReviewerDemoAccess::SOURCE, 'source_id' => $marker.':'.$level];
                $schoolId = $this->insert('ec_schools', $identity + [
                    'tenant_id' => $tenantId, 'name' => 'Google Play Demo - '.ucfirst($level),
                    'slug' => ReviewerDemoAccess::SLUG.'-'.$level, 'type' => $level, 'status' => 'active',
                    'settings' => $this->json([ReviewerDemoAccess::SETTING => $marker, 'school_level' => $level]),
                ]);
                $scope = ['tenant_id' => $tenantId, 'school_id' => $schoolId];
                $sectionId = $this->insert('ec_sections', $identity + $scope + ['name' => 'Demo section', 'status' => 'active']);
                $streamId = $this->insert('ec_streams', $identity + $scope + [
                    'section_id' => $sectionId, 'name' => 'Demo stream', 'status' => 'active',
                ]);
                $classId = $this->insert('ec_classes', $identity + $scope + [
                    'stream_id' => $streamId, 'name' => 'Demo class', 'full_name' => ucfirst($level).' Demo Class',
                    'status' => 'active', 'capacity' => 20, 'current_enrollment' => 1,
                ]);
                $studentId = $this->insert('ec_students', $identity + $scope + [
                    'class_id' => $classId, 'student_number' => 'DEMO-'.strtoupper($level),
                    'first_name' => ['Alex', 'Sam', 'Robin'][$index], 'last_name' => 'Demo',
                    'status' => 'enrolled', 'parent_name' => 'Demo Parent', 'parent_phone' => ReviewerDemoAccess::PHONE,
                    'mobile_visible' => true, 'device_sync_enabled' => false,
                ]);
                $this->insert('ec_parent_student_links', $identity + $scope + [
                    'student_id' => $studentId, 'parent_account_id' => $parentId,
                    'parent_phone' => ReviewerDemoAccess::PHONE, 'relationship' => 'parent',
                    'status' => 'active', 'linked_at' => now(), 'verified_at' => now(), 'can_pick_up' => false,
                    'linking_code' => null,
                ]);
                $this->insert('ec_student_mobile_profiles', $identity + $scope + [
                    'student_id' => $studentId, 'profile' => $this->json($this->profile($studentId, $schoolId, $classId)),
                ]);
                $threadId = $this->insert('ec_conversation_threads', $identity + $scope + [
                    'student_id' => $studentId, 'class_id' => $classId, 'type' => 'direct',
                    'title' => 'Demo school office - '.ucfirst($level), 'status' => 'open',
                    'created_by_type' => 'system', 'last_message_at' => now(),
                    'metadata' => $this->json(['mode' => 'parent_admin', 'desk' => 'general', 'desk_label' => 'General office']),
                ]);
                $this->insert('ec_conversation_participants', [
                    'thread_id' => $threadId, 'participant_type' => 'parent_account', 'participant_id' => $parentId,
                    'display_name' => 'Demo Parent', 'role' => 'parent', 'joined_at' => now(),
                ]);
                $this->insert('ec_conversation_messages', [
                    'thread_id' => $threadId, 'sender_type' => 'system', 'sender_display_name' => 'Demo school office',
                    'message_type' => 'text', 'body' => 'Welcome to the synthetic demo school. The school trip is on Friday.',
                    'status' => 'sent', 'sent_at' => now(),
                ]);
            }

            if (! $this->access->isolated(ParentAccount::findOrFail($parentId), Tenant::findOrFail($tenantId), $marker, true)) {
                throw new RuntimeException('Synthetic fixture validation failed.');
            }
            $result = ['action' => $rollback ? 'rolled-back' : 'created', 'tenant_id' => $tenantId, 'parent_id' => $parentId];
            if ($rollback) {
                // Throwing a typed result lets the transaction manager perform its normal rollback.
                throw new ReviewerDemoPreview($result);
            }

            return $result;
        });
    }

    private function insert(string $table, array $values): int
    {
        return DB::table($table)->insertGetId($values + ['created_at' => now(), 'updated_at' => now()]);
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function profile(int $studentId, int $schoolId, int $classId): array
    {
        return [
            'id' => $studentId, 'student_id' => $studentId, 'school_id' => $schoolId, 'class_id' => $classId,
            'academic_year_name' => '2026-2027 Demo', 'term_number' => 1, 'generated_at' => now()->toIso8601String(),
            'fees' => ['currency' => 'XAF', 'status' => 'partial', 'total_due' => 50000, 'total_paid' => 30000,
                'balance' => 20000, 'registration_due' => 0, 'school_fee_due' => 20000, 'installments' => [],
                'payments' => [['id' => 1, 'amount' => 30000, 'status' => 'posted', 'payment_date' => '2026-10-01', 'reference' => 'DEMO-PAYMENT']]],
            'academics' => ['current_average' => 16, 'current_percentage' => 80, 'class_position' => 1,
                'total_students' => 1, 'class_average' => 16, 'term_number' => 1, 'comment' => 'Synthetic example report.',
                'subjects' => [['name' => 'Mathematics', 'average' => 16, 'coefficient' => 1]], 'latest_report' => null],
            'report_cards' => [],
            'timetable' => [['day' => 'Monday', 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '09:00', 'subject' => 'Mathematics', 'teacher' => 'Demo Teacher']],
            'discipline' => ['today' => null, 'month_summary' => ['present' => 12, 'late' => 0, 'absent' => 0, 'excused' => 0, 'sick' => 0, 'permission' => 0],
                'recent_attendance' => [], 'incidents' => []],
        ];
    }
}
