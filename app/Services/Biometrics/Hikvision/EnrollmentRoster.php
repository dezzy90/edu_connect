<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Hikvision;

use App\Services\Biometrics\Devices\DeviceScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EnrollmentRoster
{
    private const ELIGIBLE_STATUSES = ['active', 'enrolled'];

    public function reconcile(int $studentId): void
    {
        if (! config('hikvision_push.enrollment_enabled')) {
            return;
        }
        DB::transaction(function () use ($studentId): void {
            $student = DB::table('ec_students')->where('id', $studentId)->lockForUpdate()->first();
            if (! $student) {
                return;
            }
            $eligible = self::eligible($student);
            $photo = DB::table('ec_hikvision_enrollment_photos')->where('student_id', $studentId)->first();
            if ($eligible) {
                $devices = DB::table('ec_hikvision_push_devices')->where('tenant_id', $student->tenant_id)
                    ->where('enabled', true)->get()->filter(fn ($d) => DeviceScope::allows($d, (int) $student->tenant_id, (int) $student->school_id));
                foreach ($devices as $device) {
                    $physical = DB::table('ec_biometric_devices')->where('id', $device->device_id)->first();
                    $settings = json_decode($physical->settings ?? '{}', true) ?: [];
                    if (($settings['hikvision_enrollment']['enabled'] ?? false) !== true
                        || ! self::profileIncludes($studentId, $settings['hikvision_enrollment'])) {
                        continue;
                    }
                    $exists = DB::table('ec_hikvision_enrollments')->where('push_device_id', $device->id)->where('student_id', $studentId)->exists();
                    if (! $exists) {
                        DB::table('ec_hikvision_enrollments')->insert([
                            'push_device_id' => $device->id, 'student_id' => $studentId,
                            'tenant_id' => $student->tenant_id, 'school_id' => $student->school_id,
                            'person_identifier' => 'EC'.$studentId, 'state' => 'missing_photo', 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }
            foreach (DB::table('ec_hikvision_enrollments')->where('student_id', $studentId)->lockForUpdate()->get() as $target) {
                $device = DB::table('ec_hikvision_push_devices')->where('id', $target->push_device_id)->first();
                $physical = $device ? DB::table('ec_biometric_devices')->where('id', $device->device_id)->first() : null;
                $settings = json_decode($physical->settings ?? '{}', true) ?: [];
                $present = $eligible && $device && $physical && ! $physical->deleted_at
                    && (int) $student->tenant_id === (int) $target->tenant_id && (int) $student->school_id === (int) $target->school_id
                    && DeviceScope::allows($device, (int) $student->tenant_id, (int) $student->school_id)
                    && ($settings['hikvision_enrollment']['enabled'] ?? false) === true
                    && self::profileIncludes($studentId, $settings['hikvision_enrollment']);
                // An explicit deletion of the authoritative portrait revokes a previously
                // provisioned identity. A temporary download failure does not erase it.
                if ($photo && $photo->source_revision === null && $target->person_owned) {
                    $present = false;
                }
                $ready = $photo && $photo->state === 'photo_ready' && $photo->source_revision === $photo->prepared_revision;
                if ($present && ! $ready) {
                    $this->supersede($target->id);
                    DB::table('ec_hikvision_enrollments')->where('id', $target->id)->update([
                        'desired_present' => true, 'desired_revision' => null, 'state' => $photo->state ?? 'missing_photo',
                        'error_code' => $photo->error_code ?? null, 'updated_at' => now(),
                    ]);

                    continue;
                }
                $revision = self::revision($present, $target, $student, $photo);
                if ($target->desired_revision === $revision && (bool) $target->desired_present === $present) {
                    continue;
                }
                $this->supersede($target->id);
                DB::table('ec_hikvision_enrollments')->where('id', $target->id)->update([
                    'desired_present' => $present, 'desired_revision' => $revision,
                    'state' => $present ? 'queued' : ($target->person_owned ? 'removal_pending' : 'removed'),
                    'error_code' => null, 'recognition_confirmed_at' => null, 'updated_at' => now(),
                ]);
                if ($present || $target->person_owned) {
                    self::queue($target->id, $revision, $present ? ($target->person_owned ? 'person' : 'preflight') : 'remove');
                }
            }
        }, 3);
    }

    public static function eligible(object $student): bool
    {
        return ! $student->deleted_at && in_array($student->status, self::ELIGIBLE_STATUSES, true) && (bool) $student->device_sync_enabled
            && $student->source_system === 'edu_admin' && ! empty($student->source_id)
            && DB::table('ec_schools')->where('id', $student->school_id)->where('tenant_id', $student->tenant_id)
                ->where('status', 'active')->whereNull('deleted_at')->exists()
            && DB::table('ec_tenants')->where('id', $student->tenant_id)->where('status', 'active')->whereNull('deleted_at')->exists();
    }

    /** Dynamic imported roster: no manual student approval, photo or terminal is required for eligibility. */
    public static function eligibleStudents(int $tenantId, int $schoolId): \Illuminate\Database\Query\Builder
    {
        return DB::table('ec_students')->where('ec_students.tenant_id', $tenantId)->where('ec_students.school_id', $schoolId)
            ->whereIn('ec_students.status', self::ELIGIBLE_STATUSES)->whereNull('ec_students.deleted_at')
            ->where('ec_students.device_sync_enabled', true)->where('ec_students.source_system', 'edu_admin')
            ->whereNotNull('ec_students.source_id')->where('ec_students.source_id', '!=', '')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('ec_schools')->whereColumn('ec_schools.id', 'ec_students.school_id')
                ->whereColumn('ec_schools.tenant_id', 'ec_students.tenant_id')->where('ec_schools.status', 'active')->whereNull('ec_schools.deleted_at'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('ec_tenants')->whereColumn('ec_tenants.id', 'ec_students.tenant_id')
                ->where('ec_tenants.status', 'active')->whereNull('ec_tenants.deleted_at'));
    }

    public static function name(object $student): string
    {
        // Names are device display data, never identity keys; truncate by bytes without splitting UTF-8.
        return mb_strcut(trim(preg_replace('/\s+/u', ' ', $student->first_name.' '.($student->middle_name ?? '').' '.$student->last_name)), 0, 64, 'UTF-8');
    }

    /** Attendance-only is desired device state, not a one-time manual terminal setting. */
    public static function revision(bool $present, object $target, ?object $student, ?object $photo): string
    {
        $desired = [$present, $target->tenant_id, $target->school_id, $target->person_identifier,
            $present ? self::name($student) : null, $present ? $photo->photo_hash : null];
        if ($present) {
            $desired[] = ['onlyVerify' => true];
        }

        return hash('sha256', json_encode($desired, JSON_THROW_ON_ERROR));
    }

    /** An optional per-terminal pilot roster; malformed/empty explicit lists allow nobody. */
    public static function profileIncludes(int $studentId, array $profile): bool
    {
        if (! array_key_exists('student_ids', $profile)) {
            return true;
        }
        $ids = $profile['student_ids'];

        return is_array($ids) && array_is_list($ids) && count($ids) <= 100000
            && count(array_filter($ids, static fn ($id): bool => is_int($id) && $id > 0)) === count($ids)
            && count(array_unique($ids)) === count($ids) && in_array($studentId, $ids, true);
    }

    public static function queue(int $enrollmentId, string $revision, string $operation): void
    {
        DB::table('ec_hikvision_enrollment_commands')->insert([
            'enrollment_id' => $enrollmentId, 'command_uuid' => (string) Str::uuid(), 'revision' => $revision,
            'operation' => $operation, 'state' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function supersede(int $enrollmentId): void
    {
        DB::table('ec_hikvision_enrollment_commands')->where('enrollment_id', $enrollmentId)->where('state', 'queued')
            ->whereNotIn('operation', ['remove_status', 'verify_removed'])
            ->update(['state' => 'superseded', 'payload_encrypted' => null, 'updated_at' => now()]);
        // Do not race a newer image against an already-issued old one. Wait for its result.
        DB::table('ec_hikvision_enrollment_commands')->where('enrollment_id', $enrollmentId)->where('state', 'sent')
            ->whereNotIn('operation', ['remove_status', 'verify_removed'])
            ->update(['state' => 'obsolete_sent', 'updated_at' => now()]);
    }
}
