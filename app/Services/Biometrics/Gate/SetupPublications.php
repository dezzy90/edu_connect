<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use App\Models\AdminUser;
use App\Services\Biometrics\Devices\DeviceScope;
use App\Services\Biometrics\Hikvision\EnrollmentRoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Publication authority is explicitly separate from draft preparation and student enrollment. */
final class SetupPublications
{
    public function status(AdminUser $actor, object $school, ?object $draft, array $c, array $baseChecks): array
    {
        if (! Schema::hasTable('ec_gate_publications')) {
            return ['available' => false, 'can_activate' => false, 'can_pause' => false, 'current' => null,
                'checks' => [['key' => 'schema', 'ok' => false, 'message' => 'Publication release has not been installed.']]];
        }
        $grant = $this->granted($actor, $school);
        $current = DB::table('ec_gate_publications')->where('tenant_id', $school->tenant_id)->where('school_id', $school->school_id)->orderByDesc('id')->first();
        $scope = config('gate.school_ids');
        $validScope = is_array($scope) && array_is_list($scope) && $scope !== []
            && ! array_filter($scope, static fn ($id) => ! is_int($id) || $id < 1)
            && count($scope) === count(array_unique($scope)) && in_array((int) $school->school_id, $scope, true);
        $checks = [
            ['key' => 'authority', 'ok' => $grant, 'message' => 'An explicit school activation grant is required; saving drafts or being an administrator is not that grant.'],
            ['key' => 'release', 'ok' => config('gate.activation_enabled') === true && config('gate.activation_release_ready') === true,
                'message' => 'The platform launch release must be approved. This switch does not fix or attest certificate trust.'],
            ['key' => 'runtime', 'ok' => $validScope && config('gate.enabled') === true && config('gate.operator_enabled') === true
                && config('gate.mirror_delivery_enabled') === true && config('hikvision_push.enabled') === true,
                'message' => 'Explicit school processing scope, staff screens, PushSDK receiver and EduAdmin gate delivery must be configured.'],
            ['key' => 'enrollment_pipeline', 'ok' => config('hikvision_push.enrollment_enabled') === true
                && config('hikvision_push.enrollment_work_enabled') === true && config('hikvision_push.enrollment_device_writes_enabled') === true
                && Schema::hasTable('ec_hikvision_enrollment_work'),
                'message' => 'The separately controlled portrait/enrollment pipeline and durable worker must be configured. Publishing never enables them; deployment must also verify scheduler execution.'],
            ['key' => 'saved', 'ok' => $draft !== null && SetupRules::missing($c) === [], 'message' => 'Save and confirm a complete school timetable and rule set first. No school times are guessed.'],
            ['key' => 'active_publication', 'ok' => ! $current || $current->state !== 'active', 'message' => 'Pause the active publication before scheduling a replacement. Replacements begin on a later local day.'],
        ];
        // Count staff independently of the bounded UI list.
        $roles = StaffingPolicy::staff((int) $school->tenant_id, (int) $school->school_id)->distinct()->pluck('a.role')->all();
        $staffing = StaffingPolicy::requirement((int) $school->tenant_id, (int) $school->school_id);
        foreach ($baseChecks as &$check) {
            if ($check['key'] === 'staff') {
                $check['ok'] = StaffingPolicy::satisfied($staffing, $roles);
                $check['message'] = $staffing['message'];
                if (in_array((int) $school->school_id, config('gate.recording_only_school_ids', []), true)) {
                    $check['blocking'] = false;
                    $check['message'] = 'Automatic recording is approved independently of staff sign-in. Existing secretaries may operate the gate; DM-only corrections remain disabled.';
                }
            }
        }
        unset($check);
        $checks = array_merge($checks, $baseChecks, $this->deviceChecks($school, $c));
        $classIds = array_values(array_unique(array_merge([], ...array_column($c['class_days'], 'class_ids'))));
        $activeClasses = DB::table('ec_classes')->where('tenant_id', $school->tenant_id)->where('school_id', $school->school_id)
            ->where('status', 'active')->whereNull('deleted_at')->where('source_system', 'edu_admin')->whereNotNull('source_id')
            ->where('source_id', '!=', '')->whereIn('id', $classIds)->count();
        $checks[] = ['key' => 'class_days', 'ok' => $activeClasses === count($classIds),
            'message' => 'Additional class dates apply only to explicitly selected active EduAdmin classes in this school. No selection means no extra Saturday attendance.'];

        return ['available' => true, 'can_activate' => ! array_filter($checks, static fn ($check) => ! $check['ok'] && ($check['blocking'] ?? true)),
            'dm_corrections_enabled' => ! in_array((int) $school->school_id, config('gate.recording_only_school_ids', []), true),
            'can_pause' => $grant && $current?->state === 'active',
            'current' => $current ? $this->summary($current) : null, 'checks' => $checks];
    }

    private function deviceChecks(object $school, array $c): array
    {
        $checks = [];
        foreach ($c['device_ids'] as $id) {
            $device = DeviceScope::schoolDevices((int) $school->tenant_id, (int) $school->school_id)->where('id', $id)->first();
            $push = $device ? DB::table('ec_hikvision_push_devices')->where('device_id', $id)->where('tenant_id', $school->tenant_id)->where('enabled', true)->first() : null;
            $profile = json_decode($device?->settings ?? '{}', true)['hikvision_enrollment'] ?? [];
            $verified = is_array($profile) && ($profile['enabled'] ?? null) === true && ($profile['verified'] ?? null) === true
                && $push && DeviceScope::allows($push, (int) $school->tenant_id, (int) $school->school_id)
                && ($profile['model'] ?? null) === $push->model && ($profile['firmware'] ?? null) === $push->firmware
                && is_int($profile['capacity'] ?? null) && $profile['capacity'] > 0
                && is_int($profile['unmanaged_count'] ?? null) && $profile['unmanaged_count'] >= 0
                && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', (string) ($profile['fdid'] ?? '')) === 1;
            $rosterMatches = $verified && ($c['roster_mode'] === 'all_active' ? ! array_key_exists('student_ids', $profile)
                : ! array_filter($c['student_ids'], static fn ($sid) => ! EnrollmentRoster::profileIncludes($sid, $profile)));
            $total = 0;
            if ($verified && Schema::hasTable('ec_hikvision_enrollments')) {
                $ids = [];
                foreach (DeviceScope::schools($push) as $schoolId) {
                    foreach (EnrollmentRoster::eligibleStudents((int) $school->tenant_id, $schoolId)->pluck('id') as $sid) {
                        if (EnrollmentRoster::profileIncludes((int) $sid, $profile)) {
                            $ids[(int) $sid] = true;
                        }
                    }
                }
                // People awaiting confirmed removal still occupy the physical device.
                foreach (DB::table('ec_hikvision_enrollments')->where('push_device_id', $push->id)->where('person_owned', true)->pluck('student_id') as $sid) {
                    $ids[(int) $sid] = true;
                }
                $total = count($ids) + $profile['unmanaged_count'];
            } else {
                $verified = false;
            }
            $session = $push ? DB::table('ec_hikvision_push_sessions')->where('push_device_id', $push->id)->first() : null;
            $contact = $session && $session->phase === 'online' && $session->expires_at > now()->timestamp
                && $session->last_seen_at && CarbonImmutable::parse($session->last_seen_at, 'UTC')->betweenIncluded(now()->utc()->subSeconds(60), now()->utc()->addSeconds(5));
            $checks[] = ['key' => 'device_'.$id, 'ok' => (bool) ($verified && $rosterMatches && $total <= $profile['capacity'] && $contact),
                'message' => 'Terminal '.$id.': require fresh authenticated contact, an enabled exact-firmware enrollment profile, matching automatic/pilot scope and combined capacity for every assigned school plus unmanaged/pending-removal people. Activation never changes that profile.'];
        }

        return $checks;
    }

    public function publish(AdminUser $actor, int $schoolId, int $revision, string $startsOn, string $key): array
    {
        $before = app(SetupAccess::class)->school($actor, $schoolId);
        abort_unless(Schema::hasTable('ec_gate_publications'), 409, 'Publication release is not installed.');
        $hash = hash('sha256', json_encode([$revision, $startsOn], JSON_THROW_ON_ERROR));
        $requestId = $this->requestId($before, $actor, 'activate', $key);

        return DB::transaction(function () use ($before, $actor, $schoolId, $revision, $startsOn, $hash, $requestId): array {
            DB::table('ec_schools')->where('id', $schoolId)->where('tenant_id', $before->tenant_id)->lockForUpdate()->sole();
            $school = app(SetupAccess::class)->school($actor, $schoolId, true);
            abort_unless($this->granted($actor, $school, true), 403, 'An explicit school activation assignment is required.');
            if ($replay = $this->replay($requestId, $hash, $school)) {
                return $replay;
            }
            $draft = DB::table('ec_gate_setup_drafts')->where('tenant_id', $school->tenant_id)->where('school_id', $schoolId)->lockForUpdate()->first();
            abort_unless($draft && (int) $draft->revision === $revision, 409, 'The saved draft changed. Reload before activation.');
            $data = app(SetupDrafts::class)->read($actor, $schoolId);
            abort_unless($data['activation']['can_activate'], 409, 'Activation readiness checks are not complete. Reload the setup checklist.');
            $c = $data['draft']['configuration'];
            $today = CarbonImmutable::now($c['timezone'])->format('Y-m-d');
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $startsOn, $c['timezone']);
            abort_unless($date->format('Y-m-d') === $startsOn && $startsOn >= $today && $startsOn <= CarbonImmutable::now($c['timezone'])->addYear()->format('Y-m-d'), 422, 'Choose today or a future date within one year, in the confirmed school timezone.');
            $previous = DB::table('ec_gate_publications')->where('tenant_id', $school->tenant_id)->where('school_id', $schoolId)->orderByDesc('id')->lockForUpdate()->first();
            abort_if($previous && $startsOn <= max($previous->starts_on, CarbonImmutable::parse($previous->paused_at, 'UTC')->setTimezone($c['timezone'])->format('Y-m-d')), 409, 'A replacement must begin on a later local day than the paused publication.');
            $from = $date->utc()->max(now()->utc())->format('Y-m-d H:i:s');
            abort_if(DB::table('ec_gate_policies')->where('tenant_id', $school->tenant_id)->where('school_id', $schoolId)->where('enabled', true)
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $from))->exists(), 409, 'Existing school policies require reconciliation before publication.');
            $id = DB::table('ec_gate_publications')->insertGetId(['tenant_id' => $school->tenant_id, 'school_id' => $schoolId,
                'draft_id' => $draft->id, 'draft_revision' => $revision, 'configuration' => json_encode($c, JSON_THROW_ON_ERROR),
                'starts_on' => $startsOn, 'activated_at' => now()->utc(), 'activated_by' => $actor->id, 'state' => 'active']);
            $this->audit($id, $actor, 'activate', 'confirmed_setup', $requestId, $hash);
            $p = DB::table('ec_gate_publications')->where('id', $id)->sole();
            app(PublicationPolicies::class)->materialize($p);

            return $this->summary($p) + ['replayed' => false];
        }, 3);
    }

    public function pause(AdminUser $actor, int $schoolId, int $publicationId, string $reason, string $key): array
    {
        $before = app(SetupAccess::class)->school($actor, $schoolId);
        abort_unless(Schema::hasTable('ec_gate_publications'), 409);
        abort_unless(in_array($reason, ['planned_pause', 'configuration_change', 'incident'], true), 422);
        $hash = hash('sha256', json_encode([$publicationId, $reason], JSON_THROW_ON_ERROR));
        $requestId = $this->requestId($before, $actor, 'pause', $key);

        return DB::transaction(function () use ($before, $actor, $schoolId, $publicationId, $reason, $hash, $requestId): array {
            DB::table('ec_schools')->where('id', $schoolId)->where('tenant_id', $before->tenant_id)->lockForUpdate()->sole();
            $school = app(SetupAccess::class)->school($actor, $schoolId, true);
            abort_unless($this->granted($actor, $school, true), 403);
            if ($replay = $this->replay($requestId, $hash, $school)) {
                return $replay;
            }
            $p = DB::table('ec_gate_publications')->where('id', $publicationId)->where('tenant_id', $school->tenant_id)->where('school_id', $schoolId)->lockForUpdate()->first();
            abort_unless($p, 404);
            abort_unless($p->state === 'active', 409, 'This publication is already paused.');
            $now = now()->utc()->format('Y-m-d H:i:s');
            $policyIds = DB::table('ec_gate_policy_publications')->where('publication_id', $p->id)->pluck('policy_id');
            DB::table('ec_gate_policies')->whereIn('id', $policyIds)->where('tenant_id', $school->tenant_id)->where('school_id', $schoolId)
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $now))->update(['effective_until' => $now]);
            DB::table('ec_gate_publications')->where('id', $p->id)->update(['state' => 'paused', 'paused_at' => $now, 'paused_by' => $actor->id]);
            $this->audit($p->id, $actor, 'pause', $reason, $requestId, $hash);

            return $this->summary(DB::table('ec_gate_publications')->where('id', $p->id)->sole()) + ['replayed' => false];
        }, 3);
    }

    private function granted(AdminUser $actor, object $school, bool $locked = false): bool
    {
        if ($actor->gate_source_only && ! app(SourceWorkspaceContext::class)->allows($actor, (int) $school->school_id, 'activate')) return false;
        $query = DB::table('ec_gate_activation_assignments')->where('tenant_id', $school->tenant_id)->where('school_id', $school->school_id)->where('admin_id', $actor->id)->where('active', true);

        return ($locked ? $query->lockForUpdate() : $query)->first() !== null;
    }

    private function requestId(object $school, AdminUser $actor, string $operation, string $key): string
    {
        abort_unless(preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $key) === 1, 422);

        return hash('sha256', 'gate-publication|'.$school->tenant_id.'|'.$school->school_id.'|'.$actor->id.'|'.$operation.'|'.$key);
    }

    private function replay(string $requestId, string $hash, object $school): ?array
    {
        $action = DB::table('ec_gate_publication_actions')->where('request_id', $requestId)->first();
        if (! $action) {
            return null;
        }
        abort_unless(hash_equals($action->request_hash, $hash), 409, 'Retry key already used for different inputs.');
        $p = DB::table('ec_gate_publications')->where('id', $action->publication_id)->where('tenant_id', $school->tenant_id)->where('school_id', $school->school_id)->sole();

        // Retry reports current state; it never re-enables an subsequently paused plan.
        return $this->summary($p) + ['replayed' => true];
    }

    private function audit(int $id, AdminUser $actor, string $operation, string $reason, string $requestId, string $hash): void
    {
        DB::table('ec_gate_publication_actions')->insert(['publication_id' => $id, 'actor_id' => $actor->id,
            'operation' => $operation, 'reason_code' => $reason, 'request_id' => $requestId, 'request_hash' => $hash, 'created_at' => now()->utc()]);
    }

    private function summary(object $p): array
    {
        return ['id' => (int) $p->id, 'draft_revision' => (int) $p->draft_revision, 'starts_on' => $p->starts_on,
            'state' => $p->state, 'activated_at' => $p->activated_at, 'paused_at' => $p->paused_at,
            'last_materialized_at' => $p->last_materialized_at, 'materialization_error' => $p->materialization_error];
    }
}
