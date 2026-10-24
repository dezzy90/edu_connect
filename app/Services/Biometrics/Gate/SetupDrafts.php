<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use App\Models\AdminUser;
use App\Services\Biometrics\Hikvision\EnrollmentRoster;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Writes only draft heads and their append-only revisions. Never publishes policy or enrollment. */
final class SetupDrafts
{
    public function read(AdminUser $actor, int $schoolId, string $search = ''): array
    {
        $school = app(SetupAccess::class)->school($actor, $schoolId);
        $row = DB::table('ec_gate_setup_drafts')->where('tenant_id', $school->tenant_id)->where('school_id', $schoolId)->first();
        $c = $row ? SetupRules::normalize(json_decode($row->configuration, true, 16, JSON_THROW_ON_ERROR), stored: true) : SetupRules::blank();
        $staff = StaffingPolicy::staff((int) $school->tenant_id, $schoolId)->orderBy('a.id')->limit(201)->get(['u.id', 'u.name', 'a.role']);
        $staffing = StaffingPolicy::requirement((int) $school->tenant_id, $schoolId);
        $devices = \App\Services\Biometrics\Devices\DeviceScope::schoolDevices((int) $school->tenant_id, $schoolId)
            ->orderBy('id')->limit(201)->get(['id', 'name', 'device_uid']);
        $devices->each(function ($device): void {
            $device->shared_gate = \App\Services\Biometrics\Devices\DeviceScope::shared((object) ['device_id' => $device->id]);
        });
        $students = EnrollmentRoster::eligibleStudents((int) $school->tenant_id, $schoolId);
        $eligibleTotal = (clone $students)->count();
        if ($search !== '') {
            // Literal bounded search; wildcard characters do not broaden a requested name.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $students->where(fn ($q) => $q->whereRaw("first_name LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("last_name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("student_number LIKE ? ESCAPE '!'", [$pattern]));
        }
        $binding = DB::table('ec_gate_mirror_bindings')->where('tenant_id', $school->tenant_id)->where('school_id', $schoolId)->first();
        $connection = $binding ? DB::table('ec_integration_connections')->where('id', $binding->connection_id)->where('tenant_id', $school->tenant_id)
            ->where('provider', 'edu_admin')->where('mode', 'connected')->where('status', 'active')->first(['id', 'enabled_school_ids', 'base_url']) : null;
        $mapping = $connection ? DB::table('ec_integration_mappings')->where('connection_id', $connection->id)->where('local_type', 'school')
            ->where('local_id', $schoolId)->where('external_type', 'school')->where('external_id', (string) $binding->remote_school_id)->count() : 0;
        $allowed = $connection ? json_decode($connection->enabled_school_ids ?? '[]', true) : [];
        $connected = $binding && $binding->enabled && $connection && $mapping === 1 && is_array($allowed)
            && in_array($schoolId, $allowed, true) && str_starts_with($connection->base_url, 'https://');
        $selectedStudents = EnrollmentRoster::eligibleStudents((int) $school->tenant_id, $schoolId)->whereIn('id', $c['student_ids'])
            ->orderBy('id')->get(['id', 'first_name', 'last_name', 'student_number']);
        $activeDeviceIds = \App\Services\Biometrics\Devices\DeviceScope::schoolDevices((int) $school->tenant_id, $schoolId)
            ->whereIn('id', $c['device_ids'])->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $checks = [
            ['key' => 'eduadmin', 'ok' => (bool) $connected, 'message' => $connected ? 'EduAdmin school mapping and mirror binding match.' : 'EduAdmin mapping/binding needs a separate authorized configuration step.'],
            ['key' => 'students', 'ok' => $c['roster_mode'] === 'all_active' || ($c['student_ids'] !== [] && $selectedStudents->count() === count($c['student_ids'])),
                'message' => $c['roster_mode'] === 'all_active'
                    ? 'Automatic eligibility: all current and future active/enrolled EduAdmin students in this school, without individual selection. Photos, assigned devices and capacity are separate readiness checks.'
                    : 'Restricted pilot: every selected student must remain eligible in this school. An empty pilot includes nobody.'],
            ['key' => 'devices', 'ok' => $c['device_ids'] !== [] && count($activeDeviceIds) === count($c['device_ids']), 'message' => 'Select active terminals assigned to this school, including a participating shared gate. Saving does not enroll anyone or contact a terminal.'],
            ['key' => 'staff', 'ok' => StaffingPolicy::satisfied($staffing, $staff->pluck('role')->all()), 'message' => $staffing['message'].' This screen cannot grant them.'],
        ];
        $history = $row ? DB::table('ec_gate_setup_revisions as r')->leftJoin('admin_users as u', 'u.id', '=', 'r.actor_id')
            ->where('r.draft_id', $row->id)->orderByDesc('r.revision')->limit(20)
            ->get(['r.revision', 'r.basis_revision', 'r.actor_id', 'u.name as actor_name', 'r.created_at']) : collect();
        $activation = app(SetupPublications::class)->status($actor, $school, $row, $c, $checks);
        $classes = DB::table('ec_classes')->where('tenant_id', $school->tenant_id)->where('school_id', $schoolId)
            ->where('status', 'active')->whereNull('deleted_at')->where('source_system', 'edu_admin')
            ->whereNotNull('source_id')->where('source_id', '!=', '')->orderBy('full_name')->limit(201)->get(['id', 'full_name']);

        return ['selectedSchool' => $school, 'draft' => ['revision' => $row ? (int) $row->revision : 0,
            'configuration' => $c, 'updated_at' => $row?->updated_at, 'state' => 'draft'],
            'readiness' => ['missing' => SetupRules::missing($c), 'configuration_complete' => SetupRules::missing($c) === [],
                'checks' => $checks, 'activation_available' => $activation['can_activate']],
            'activation' => $activation, 'parentAlerts' => app(ParentAlertSettings::class)->read($school),
            'classes' => $classes->take(200)->values(), 'classes_truncated' => $classes->count() > 200,
            'connection' => ['matched' => (bool) $connected, 'connection_id' => $binding ? (int) $binding->connection_id : null,
                'eduadmin_school_id' => $binding ? (int) $binding->remote_school_id : null],
            'rosterEligibility' => ['mode' => $c['roster_mode'], 'eligible_total' => $eligibleTotal,
                'configured_total' => $c['roster_mode'] === 'all_active' ? $eligibleTotal : count($c['student_ids']),
                'includes_future_students' => $c['roster_mode'] === 'all_active'],
            'students' => $students->orderBy('last_name')->orderBy('id')->paginate(50, ['id', 'first_name', 'last_name', 'student_number'])->withQueryString(),
            'selectedStudents' => $selectedStudents, 'devices' => $devices->take(200)->values(), 'devices_truncated' => $devices->count() > 200,
            'staff' => $staff->take(200)->values(), 'staff_truncated' => $staff->count() > 200, 'history' => $history];
    }

    public function save(AdminUser $actor, int $schoolId, int $expected, string $key, mixed $configuration): array
    {
        $before = app(SetupAccess::class)->school($actor, $schoolId);
        $c = SetupRules::normalize($configuration);
        $hash = hash('sha256', json_encode(['expected_revision' => $expected, 'configuration' => $c], JSON_THROW_ON_ERROR));
        $legacy = $c;
        unset($legacy['roster_mode']);
        $legacyHash = hash('sha256', json_encode(['expected_revision' => $expected, 'configuration' => $legacy], JSON_THROW_ON_ERROR));
        $requestId = hash('sha256', 'gate-setup|'.$before->tenant_id.'|'.$schoolId.'|'.$actor->id.'|'.$key);

        return DB::transaction(function () use ($actor, $schoolId, $before, $expected, $c, $hash, $legacyHash, $requestId): array {
            // Serialize even the first draft save by locking the existing school before any draft read.
            DB::table('ec_schools')->where('id', $schoolId)->where('tenant_id', $before->tenant_id)->lockForUpdate()->sole();
            $school = app(SetupAccess::class)->school($actor, $schoolId, true);
            $draft = DB::table('ec_gate_setup_drafts')->where('tenant_id', $school->tenant_id)->where('school_id', $schoolId)->lockForUpdate()->first();
            $old = DB::table('ec_gate_setup_revisions')->where('request_id', $requestId)->first();
            if ($old) {
                $semanticReplay = (int) $old->basis_revision === $expected
                    && SetupRules::normalize(json_decode($old->configuration, true, 16, JSON_THROW_ON_ERROR), stored: true) === $c;
                $legacyReplay = $c['roster_mode'] === 'selected'
                    && ! array_key_exists('roster_mode', json_decode($old->configuration, true, 16, JSON_THROW_ON_ERROR))
                    && hash_equals($old->request_hash, $legacyHash);
                abort_unless($draft && (int) $old->draft_id === (int) $draft->id && (hash_equals($old->request_hash, $hash) || $legacyReplay || $semanticReplay), 409, 'This retry key was used for a different draft.');

                return ['saved_revision' => (int) $old->revision, 'current_revision' => (int) $draft->revision, 'replayed' => true, 'state' => 'draft', 'activated' => false];
            }
            $revision = $draft ? (int) $draft->revision : 0;
            abort_unless($revision === $expected, 409, 'The school draft changed. Reload before saving; your changes were not applied.');
            abort_if($revision >= 1000, 409, 'Draft revision limit requires review.');
            $classIds = array_values(array_unique(array_merge([], ...array_column($c['class_days'], 'class_ids'))));
            if (DB::table('ec_classes')->where('tenant_id', $school->tenant_id)->where('school_id', $schoolId)->where('status', 'active')
                ->whereNull('deleted_at')->where('source_system', 'edu_admin')->whereNotNull('source_id')->where('source_id', '!=', '')
                ->whereIn('id', $classIds)->lockForUpdate()->pluck('id')->count() !== count($classIds)) {
                throw ValidationException::withMessages(['configuration.class_days' => 'Choose active EduAdmin classes in this assigned school only.']);
            }
            foreach (['student_ids' => 'ec_students', 'device_ids' => 'ec_biometric_devices'] as $field => $table) {
                $query = $field === 'device_ids' ? \App\Services\Biometrics\Devices\DeviceScope::schoolDevices((int) $school->tenant_id, $schoolId)
                    : EnrollmentRoster::eligibleStudents((int) $school->tenant_id, $schoolId);
                $query->whereIn('id', $c[$field]);
                $field === 'student_ids' ? $query->whereIn('status', ['active', 'enrolled']) : $query->where('status', 'active');
                if ($query->lockForUpdate()->pluck('id')->count() !== count($c[$field])) {
                    throw ValidationException::withMessages(['configuration.'.$field => 'Select only current active records in this assigned school.']);
                }
            }
            $bytes = json_encode($c, JSON_THROW_ON_ERROR);
            $values = ['revision' => $revision + 1, 'configuration' => $bytes, 'updated_by' => $actor->id, 'updated_at' => now()->utc()];
            if ($draft) {
                DB::table('ec_gate_setup_drafts')->where('id', $draft->id)->update($values);
                $id = $draft->id;
            } else {
                $id = DB::table('ec_gate_setup_drafts')->insertGetId(['tenant_id' => $school->tenant_id, 'school_id' => $schoolId] + $values);
            }
            DB::table('ec_gate_setup_revisions')->insert(['draft_id' => $id, 'revision' => $revision + 1, 'basis_revision' => $revision,
                'actor_id' => $actor->id, 'request_id' => $requestId, 'request_hash' => $hash, 'configuration' => $bytes, 'created_at' => now()->utc()]);

            return ['saved_revision' => $revision + 1, 'current_revision' => $revision + 1, 'replayed' => false, 'state' => 'draft', 'activated' => false];
        }, 3);
    }
}
