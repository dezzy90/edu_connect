<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Devices;

use App\Models\AdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Device administration only. Never prepares portraits, queues terminal commands or publishes gate policies. */
final class DeviceRegistry
{
    public function schools(AdminUser $actor): array
    {
        return app(RegistryAccess::class)->schools($actor)->orderBy('a.tenant_id')->orderBy('s.name')->limit(201)
            ->select(['s.id as school_id', 'a.tenant_id', 's.name', 't.name as complex_name', 's.source_id as eduadmin_school_id'])
            ->selectSub(DB::table('ec_students as roster')->selectRaw('count(*)')->whereColumn('roster.tenant_id', 's.tenant_id')
                ->whereColumn('roster.school_id', 's.id')->whereIn('roster.status', ['active', 'enrolled'])->whereNull('roster.deleted_at'), 'roster_total')
            ->get()->map(fn ($school) => (object) array_replace((array) $school, ['school_id' => (int) $school->school_id,
                'tenant_id' => (int) $school->tenant_id, 'roster_total' => (int) $school->roster_total]))->all();
    }

    public function index(AdminUser $actor, array $filters): array
    {
        $query = app(RegistryAccess::class)->devices($actor);
        if (isset($filters['tenant_id'])) {
            $query->where('d.tenant_id', $filters['tenant_id']);
        }
        if (($filters['q'] ?? '') !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->where(fn ($q) => $q->whereRaw("d.name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("d.serial_number LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("d.location LIKE ? ESCAPE '!'", [$pattern]));
        }
        $stats = ['total' => (clone $query)->count(), 'active' => (clone $query)->where('d.status', 'active')->count(),
            'paused' => (clone $query)->whereExists(fn ($q) => $q->selectRaw('1')->from('ec_hikvision_push_devices as p')->whereColumn('p.device_id', 'd.id')->where('p.enabled', false))->count(),
            'shared' => (clone $query)->whereExists(fn ($q) => $q->selectRaw('1')->from('ec_device_registry as r')->whereColumn('r.device_id', 'd.id')->where('r.scope_mode', 'shared_gate'))->count()];
        if (($filters['state'] ?? 'all') === 'retired') {
            $query->where('d.status', '!=', 'active');
        } elseif (($filters['state'] ?? '') === 'active') {
            $query->where('d.status', 'active');
        } elseif (($filters['state'] ?? '') === 'shared') {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('ec_device_registry as r')->whereColumn('r.device_id', 'd.id')->where('r.scope_mode', 'shared_gate'));
        } elseif (($filters['state'] ?? '') === 'paused') {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('ec_hikvision_push_devices as p')->whereColumn('p.device_id', 'd.id')->where('p.enabled', false));
        }
        $devices = $query->orderByDesc('d.id')->paginate(20, ['d.*'])->withQueryString()->through(fn ($row) => $this->describe($actor, $row, false));

        return ['devices' => $devices, 'stats' => $stats, 'filters' => $filters, 'detail' => null];
    }

    public function detail(AdminUser $actor, int $id): array
    {
        return $this->describe($actor, app(RegistryAccess::class)->device($actor, $id), true);
    }

    private function memberships(object $device, ?object $meta): array
    {
        if ($meta?->scope_mode !== 'shared_gate') {
            return [(int) $device->school_id];
        }

        return DB::table('ec_device_school_assignments')->where('device_id', $device->id)->where('tenant_id', $device->tenant_id)
            ->where('active', true)->whereNull('revoked_at')->orderBy('school_id')->pluck('school_id')->map(fn ($id) => (int) $id)->all();
    }

    private function describe(AdminUser $actor, object $device, bool $full): array
    {
        $access = app(RegistryAccess::class);
        $meta = DB::table('ec_device_registry')->where('device_id', $device->id)->first();
        $push = DB::table('ec_hikvision_push_devices')->where('device_id', $device->id)->first();
        $schoolIds = $this->memberships($device, $meta);
        $schools = [];
        foreach ($schoolIds as $id) {
            $s = $access->school($actor, $id);
            abort_unless((int) $s->tenant_id === (int) $device->tenant_id, 404);
            $setup = app(\App\Services\Biometrics\Gate\SetupAccess::class);
            $schools[] = ['school_id' => $id, 'name' => $s->name, 'eduadmin_school_id' => $s->source_id,
                'setup_url' => $setup->enabled() && $setup->schools($actor)->where('a.school_id', $id)->exists() ? '/admin/gate/setup?school_id='.$id : null];
        }
        $connection = RegistryRules::connection();
        $session = $push ? DB::table('ec_hikvision_push_sessions')->where('push_device_id', $push->id)->first() : null;
        $online = $push && $push->enabled && $device->status === 'active' && config('hikvision_push.enabled') === true
            && $connection['receiver_mode'] === 'application' && $session && $session->phase === 'online' && $session->expires_at > now()->timestamp
            && (! DeviceScope::shared($push) || DeviceScope::schools($push) !== [])
            && $session->last_seen_at && CarbonImmutable::parse($session->last_seen_at, 'UTC')->betweenIncluded(now()->utc()->subSeconds(60), now()->utc()->addSeconds(5));
        $status = match (true) {
            $device->status !== 'active' => ['key' => 'retired', 'label' => 'Retired', 'tone' => 'slate'],
            ! $push => ['key' => 'unregistered', 'label' => 'Registration needed', 'tone' => 'amber'],
            ! $push->enabled => ['key' => 'paused', 'label' => 'Connection paused', 'tone' => 'slate'],
            $online => ['key' => 'online', 'label' => 'Authenticated contact', 'tone' => 'green'],
            $connection['receiver_mode'] !== 'application' || config('hikvision_push.enabled') !== true => ['key' => 'unmonitored', 'label' => 'External / unconfigured receiver', 'tone' => 'amber'],
            default => ['key' => 'awaiting', 'label' => 'Awaiting authenticated contact', 'tone' => 'amber'],
        };
        $result = ['id' => (int) $device->id, 'tenant_id' => (int) $device->tenant_id,
            'complex_name' => $schools === [] ? 'Historical complex installation' : $access->school($actor, $schoolIds[0])->complex_name,
            'name' => $device->name, 'location' => $device->location, 'serial' => $device->serial_number, 'device_uid' => $device->device_uid,
            'model' => $push?->model ?? 'Not registered', 'firmware' => $push?->firmware ?? $device->firmware_version,
            'school_ids' => $schoolIds, 'schools' => $schools, 'scope_mode' => $meta?->scope_mode ?? 'single_school',
            'revision' => (int) ($meta?->revision ?? 0), 'managed_registration' => (bool) ($meta?->managed_registration ?? false),
            'status' => $status, 'connection_allowed' => (bool) ($push?->enabled ?? false), 'last_contact_at' => $session?->last_seen_at,
            'created_at' => $device->created_at, 'last_checked_at' => $meta?->last_checked_at, 'last_verified_at' => $meta?->last_verified_at];
        if (! $full) {
            return $result;
        }
        $settings = json_decode($device->settings ?? '{}', true, 32, JSON_THROW_ON_ERROR);
        $profile = $settings['hikvision_enrollment'] ?? [];
        $capacity = ($profile['verified'] ?? false) === true && ($profile['model'] ?? null) === $push?->model
            && ($profile['firmware'] ?? null) === $push?->firmware && is_int($profile['capacity'] ?? null) && $profile['capacity'] > 0 ? $profile['capacity'] : null;
        $roster = DB::table('ec_students')->where('tenant_id', $device->tenant_id)->whereIn('school_id', $schoolIds)
            ->whereIn('status', ['active', 'enrolled'])->whereNull('deleted_at')->count();
        $enrolled = $push ? DB::table('ec_hikvision_enrollments')->where('push_device_id', $push->id)->where('desired_present', true)->count() : 0;
        $blockers = $this->changeBlockers($device, $push, $meta);
        $history = DB::table('ec_device_registry_audits as a')->leftJoin('admin_users as u', 'u.id', '=', 'a.actor_id')
            ->where('a.device_id', $device->id)->orderByDesc('a.id')->paginate(20, ['a.operation', 'a.revision', 'a.created_at', 'a.details', 'u.name as actor_name'], 'audit_page')
            ->through(fn ($row) => ['operation' => $row->operation, 'revision' => (int) $row->revision, 'created_at' => $row->created_at,
                'actor_name' => $row->actor_name, 'details' => json_decode($row->details, true, 16, JSON_THROW_ON_ERROR)])->withQueryString();

        return $result + ['connection' => $connection + ['username' => $push?->username, 'credential_version' => (int) ($meta?->credential_version ?? 0)],
            'capacity' => ['verified_limit' => $capacity, 'school_roster_total' => $roster, 'managed_enrollments' => $enrolled,
                'unmanaged_count' => $capacity !== null && is_int($profile['unmanaged_count'] ?? null) ? $profile['unmanaged_count'] : null],
            'student_sync_enabled' => ($profile['enabled'] ?? false) === true, 'change_blockers' => $blockers,
            'audit' => $history, 'checks' => [
                ['key' => 'ownership', 'ok' => $schools !== [], 'title' => 'Complex and participating schools', 'message' => 'This serial belongs to one complex installation. Every selected school remains a separate data boundary.'],
                ['key' => 'registration', 'ok' => $push !== null, 'title' => 'Dedicated device identity', 'message' => 'The serial and dedicated PushSDK credentials identify the terminal; Wi-Fi and IP do not choose its school.'],
                ['key' => 'contact', 'ok' => (bool) $online, 'title' => 'Authenticated server contact', 'message' => $connection['message']],
                ['key' => 'capacity', 'ok' => $capacity !== null, 'title' => 'Combined face capacity', 'message' => 'Check all participating schools plus existing unmanaged people against verified firmware capacity. A school roster total is not a synchronisation command.'],
                ['key' => 'enrollment', 'ok' => ($profile['enabled'] ?? false) === true, 'title' => 'Student synchronisation — separate control', 'message' => 'Registration and connection verification never enable student/portrait distribution or gate attendance.'],
            ]];
    }

    private function changeBlockers(object $device, ?object $push, ?object $meta): array
    {
        $reasons = [];
        if (! $meta?->managed_registration) {
            $reasons[] = 'This existing installation is externally managed. Its connection and school scope need a separately reviewed migration.';
        }
        if ($push?->enabled) {
            $reasons[] = 'Pause the connection before changing credentials or participating schools.';
        }
        if (DB::table('ec_device_personnel')->where('device_id', $device->id)->exists()
            || ($push && DB::table('ec_hikvision_enrollments')->where('push_device_id', $push->id)->exists())) {
            $reasons[] = 'Student enrolment records exist. Complete scoped identity cleanup and migration before reassignment.';
        }
        if ($push && (DB::table('ec_hikvision_observations')->where('push_device_id', $push->id)->exists()
            || DB::table('ec_hikvision_push_commands')->where('push_device_id', $push->id)->exists())) {
            $reasons[] = 'This installation has device/event history. It cannot be moved by this unused-device workflow.';
        }
        if (DB::table('ec_gate_policies')->where('tenant_id', $device->tenant_id)->whereJsonContains('device_ids', (int) $device->id)->exists()
            || DB::table('ec_gate_setup_drafts')->where('tenant_id', $device->tenant_id)->whereJsonContains('configuration->device_ids', (int) $device->id)->exists()) {
            $reasons[] = 'School gate policies or setup drafts reference this terminal. Review those school configurations before changing its identity or scope.';
        }
        $profile = json_decode($device->settings ?? '{}', true)['hikvision_enrollment'] ?? [];
        if (($profile['enabled'] ?? false) === true) {
            $reasons[] = 'Student synchronisation is enabled. This screen cannot disable it or remove people.';
        }
        if ($device->status !== 'active') {
            $reasons[] = 'The registration is retired. Its identity and audit history are retained.';
        }

        return $reasons;
    }

    public function save(AdminUser $actor, ?int $id, array $input): array
    {
        $access = app(RegistryAccess::class);
        $access->actor($actor);
        abort_if(config('device_registry.read_only') !== false, 409, 'Device administration is read-only on this host. No installation changes were made.');
        $before = $id === null ? null : $access->device($actor, $id);
        $schoolIds = $input['school_ids'] ?? ($before ? [(int) $before->school_id] : []);
        $schools = array_map(fn ($school) => $access->school($actor, $school), $schoolIds);
        $tenant = $before ? (int) $before->tenant_id : ($input['tenant_id'] ?? 0);
        abort_unless($schools !== [] && ! array_filter($schools, fn ($s) => (int) $s->tenant_id !== $tenant), 422, 'Choose schools belonging to one authorised complex.');
        $requestId = hash('sha256', 'device-registry|'.$actor->id.'|'.$input['idempotency_key']);
        $hash = hash('sha256', json_encode(['device_id' => $id, 'input' => $input], JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($actor, $id, $input, $access, $before, $tenant, $schoolIds, $requestId, $hash): array {
                DB::table('ec_tenants')->where('id', $tenant)->lockForUpdate()->sole();
                foreach ($schoolIds as $school) {
                    $access->school($actor, $school, true);
                }
                $old = DB::table('ec_device_registry_audits')->where('request_id', $requestId)->first();
                if ($old) {
                    abort_unless(hash_equals($old->request_hash, $hash), 409, 'This retry key was used for a different device operation.');
                    $current = $access->device($actor, (int) $old->device_id);
                    $meta = DB::table('ec_device_registry')->where('device_id', $current->id)->first();

                    return $this->reply($actor, $current, $meta, $old, true);
                }
                $operation = $input['operation'];
                $details = ['note' => $input['note']];
                if ($id === null) {
                    abort_unless(config('device_registry.registration_enabled') === true && $input['expected_revision'] === 0, 409, 'New device registration is paused on this host.');
                    $this->validateSchools($tenant, $schoolIds);
                    $serial = $input['serial'];
                    abort_if(DB::table('ec_biometric_devices')->whereRaw('UPPER(serial_number) = ?', [$serial])->exists()
                        || DB::table('ec_hikvision_push_devices')->whereRaw('UPPER(serial) = ?', [$serial])->exists(), 422, 'This serial is already registered. Do not create another identity for the same terminal.');
                    $id = DB::table('ec_biometric_devices')->insertGetId(['tenant_id' => $tenant, 'school_id' => $schoolIds[0],
                        'name' => $input['name'], 'location' => $input['location'], 'serial_number' => $serial,
                        'device_uid' => 'HIK-'.Str::uuid(), 'device_type' => 'hikvision', 'firmware_version' => $input['firmware'],
                        'status' => 'active', 'settings' => '{}', 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
                    DB::table('ec_hikvision_push_devices')->insert(['device_id' => $id, 'tenant_id' => $tenant, 'school_id' => $schoolIds[0],
                        'serial' => $serial, 'deployment_epoch' => (string) Str::uuid(), 'model' => 'DS-K1T342MFWX-E1', 'firmware' => $input['firmware'],
                        'auth_profile' => 'sha256', 'username' => 'ec-'.bin2hex(random_bytes(12)), 'credential_encrypted' => Crypt::encryptString(bin2hex(random_bytes(24))),
                        'enabled' => false, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
                    DB::table('ec_device_registry')->insert(['device_id' => $id, 'serial_key' => $serial, 'revision' => 1,
                        'managed_registration' => true, 'scope_mode' => count($schoolIds) > 1 ? 'shared_gate' : 'single_school',
                        'credential_version' => 1, 'credentials_issued_at' => now()->utc(), 'updated_by' => $actor->id, 'updated_at' => now()->utc()]);
                    $this->setMemberships($id, $tenant, $schoolIds, (int) $actor->id);
                    $details += ['school_ids' => $schoolIds, 'connection_enabled' => false, 'credential_version' => 1];
                    $revision = 1;
                } else {
                    $push = DB::table('ec_hikvision_push_devices')->where('device_id', $id)->lockForUpdate()->first();
                    $device = DB::table('ec_biometric_devices')->where('id', $id)->lockForUpdate()->sole();
                    abort_unless((int) $device->tenant_id === $tenant && (int) $device->school_id === (int) $before->school_id, 409, 'The installation changed. Reload before continuing.');
                    $meta = DB::table('ec_device_registry')->where('device_id', $id)->lockForUpdate()->first();
                    foreach ($this->memberships($device, $meta) as $school) {
                        $access->school($actor, $school, true);
                    }
                    abort_unless($input['expected_revision'] === (int) ($meta?->revision ?? 0), 409, 'This device changed. Reload before applying another operation.');
                    abort_unless($device->status === 'active', 409, 'This registration is retired.');
                    if ($meta === null) {
                        DB::table('ec_device_registry')->insert(['device_id' => $id, 'serial_key' => strtoupper($device->serial_number ?? 'UNREGISTERED-'.$id),
                            'managed_registration' => false, 'updated_by' => $actor->id]);
                        $meta = DB::table('ec_device_registry')->where('device_id', $id)->sole();
                    }
                    if (in_array($operation, ['enable', 'pause', 'rotate', 'retire', 'reassign'], true)) {
                        abort_unless($push && $meta->managed_registration && hash_equals($push->serial, $input['confirmation']), 409, 'Confirm the exact serial of an application-managed registration. Existing installations require a reviewed migration.');
                    }
                    if (in_array($operation, ['rotate', 'retire', 'reassign'], true)) {
                        abort_unless($this->changeBlockers($device, $push, $meta) === [], 409, 'This installation cannot be reassigned or reset while connection, enrolment or historical records remain.');
                    }
                    if ($operation === 'edit') {
                        $details += ['from_name' => $device->name, 'from_location' => $device->location, 'to_name' => $input['name'], 'to_location' => $input['location']];
                        DB::table('ec_biometric_devices')->where('id', $id)->update(['name' => $input['name'], 'location' => $input['location'], 'updated_at' => now()->utc()]);
                    } elseif ($operation === 'enable' || $operation === 'pause') {
                        abort_unless($operation === 'pause' || RegistryRules::connection()['may_enable'], 409, 'An approved HTTPS application receiver must be configured before allowing a connection.');
                        $profile = json_decode($device->settings ?? '{}', true)['hikvision_enrollment'] ?? [];
                        abort_if(($profile['enabled'] ?? false) === true || DB::table('ec_hikvision_enrollments')->where('push_device_id', $push->id)->exists(), 409, 'Live student transport is managed outside this installation-only screen.');
                        DB::table('ec_hikvision_push_devices')->where('id', $push->id)->update(['enabled' => $operation === 'enable', 'updated_at' => now()->utc()]);
                        DB::table('ec_hikvision_push_sessions')->where('push_device_id', $push->id)->delete();
                        $details['connection_enabled'] = $operation === 'enable';
                    } elseif ($operation === 'rotate' || $operation === 'reassign') {
                        if ($operation === 'reassign') {
                            $this->validateSchools($tenant, $schoolIds);
                            $details['from_school_ids'] = $this->memberships($device, $meta);
                            $details['school_ids'] = $schoolIds;
                            $this->setMemberships($id, $tenant, $schoolIds, (int) $actor->id);
                            DB::table('ec_biometric_devices')->where('id', $id)->update(['school_id' => $schoolIds[0], 'updated_at' => now()->utc()]);
                            DB::table('ec_hikvision_push_devices')->where('id', $push->id)->update(['school_id' => $schoolIds[0], 'deployment_epoch' => (string) Str::uuid()]);
                            DB::table('ec_device_registry')->where('device_id', $id)->update(['scope_mode' => count($schoolIds) > 1 ? 'shared_gate' : 'single_school']);
                        }
                        DB::table('ec_hikvision_push_devices')->where('id', $push->id)->update(['username' => 'ec-'.bin2hex(random_bytes(12)),
                            'credential_encrypted' => Crypt::encryptString(bin2hex(random_bytes(24))), 'login_failures' => 0, 'locked_until' => 0, 'updated_at' => now()->utc()]);
                        DB::table('ec_hikvision_push_sessions')->where('push_device_id', $push->id)->delete();
                        DB::table('ec_device_registry')->where('device_id', $id)->update(['credential_version' => (int) $meta->credential_version + 1,
                            'credentials_issued_at' => now()->utc(), 'last_verified_at' => null]);
                        $details['credential_version'] = (int) $meta->credential_version + 1;
                    } elseif ($operation === 'retire') {
                        DB::table('ec_biometric_devices')->where('id', $id)->update(['status' => 'inactive', 'updated_at' => now()->utc()]);
                        DB::table('ec_device_registry')->where('device_id', $id)->update(['retired_at' => now()->utc()]);
                        DB::table('ec_device_school_assignments')->where('device_id', $id)->where('active', true)->update(['active' => false, 'revoked_at' => now()->utc()]);
                        $details['retired'] = true;
                    } elseif ($operation === 'verify') {
                        $view = $this->describe($actor, $device, false);
                        $observed = $view['status']['key'] === 'online';
                        DB::table('ec_device_registry')->where('device_id', $id)->update(['last_checked_at' => now()->utc()] + ($observed ? ['last_verified_at' => now()->utc()] : []));
                        $details['authenticated_contact_observed'] = $observed;
                    }
                    $revision = (int) $meta->revision + 1;
                    DB::table('ec_device_registry')->where('device_id', $id)->update(['revision' => $revision, 'updated_by' => $actor->id, 'updated_at' => now()->utc()]);
                }
                $device = $access->device($actor, $id);
                $auditId = DB::table('ec_device_registry_audits')->insertGetId(['device_id' => $id, 'tenant_id' => $tenant, 'school_id' => $device->school_id,
                    'actor_id' => $actor->id, 'operation' => $operation, 'revision' => $revision, 'request_id' => $requestId,
                    'request_hash' => $hash, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now()->utc()]);

                return $this->reply($actor, $device, DB::table('ec_device_registry')->where('device_id', $id)->sole(),
                    DB::table('ec_device_registry_audits')->where('id', $auditId)->sole(), false);
            }, 3);
        } catch (QueryException $error) {
            if (in_array($error->getCode(), ['23000', '23505'], true)) {
                abort(409, 'The device identity or retry was registered concurrently. Reload before continuing.');
            }
            throw $error;
        }
    }

    private function validateSchools(int $tenant, array $schoolIds): void
    {
        abort_if(count($schoolIds) > 1 && config('device_registry.shared_gate_enabled') !== true, 409, 'Shared-gate operation must be explicitly enabled and tested before registration.');
        if (count($schoolIds) > 1) {
            $complex = DB::table('ec_tenants')->where('id', $tenant)->where('source_system', 'edu_admin')->first();
            $sources = DB::table('ec_schools')->where('tenant_id', $tenant)->whereIn('id', $schoolIds)->where('source_system', 'edu_admin')->pluck('source_id')->all();
            abort_unless($complex && preg_match('/^[1-9]\d{0,17}$/D', (string) $complex->source_id)
                && count($sources) === count($schoolIds) && count(array_unique($sources)) === count($sources)
                && ! array_filter($sources, fn ($id) => ! preg_match('/^[1-9]\d{0,17}$/D', (string) $id)),
                409, 'Shared schools must have authoritative EduAdmin identities under the same complex.');
        }
    }

    private function setMemberships(int $id, int $tenant, array $schools, int $actor): void
    {
        DB::table('ec_device_school_assignments')->where('device_id', $id)->where('active', true)->update(['active' => false, 'revoked_at' => now()->utc()]);
        foreach ($schools as $school) {
            DB::table('ec_device_school_assignments')->insert(['epoch' => (string) Str::uuid(), 'device_id' => $id, 'tenant_id' => $tenant,
                'school_id' => $school, 'active' => true, 'authorized_from' => now()->utc(), 'assigned_by' => $actor]);
        }
    }

    private function reply(AdminUser $actor, object $device, object $meta, object $audit, bool $replayed): array
    {
        // Recheck every participating school even for a lost-response retry after a scope change.
        foreach ($this->memberships($device, $meta) as $school) {
            app(RegistryAccess::class)->school($actor, $school, true);
        }
        $details = json_decode($audit->details, true, 16, JSON_THROW_ON_ERROR);
        $secret = null;
        if ($device->status === 'active' && in_array($audit->operation, ['register', 'rotate', 'reassign'], true) && (int) $audit->actor_id === (int) $actor->id
            && ($details['credential_version'] ?? null) === (int) $meta->credential_version
            && $meta->credentials_issued_at && CarbonImmutable::parse($meta->credentials_issued_at, 'UTC')->gt(now()->utc()->subMinutes(10))) {
            $push = DB::table('ec_hikvision_push_devices')->where('device_id', $device->id)->sole();
            $secret = ['username' => $push->username, 'password' => Crypt::decryptString($push->credential_encrypted),
                'expires_at' => CarbonImmutable::parse($meta->credentials_issued_at, 'UTC')->addMinutes(10)->toIso8601String()];
        }

        return ['device_id' => (int) $device->id, 'saved_revision' => (int) $audit->revision, 'current_revision' => (int) $meta->revision,
            'operation' => $audit->operation, 'replayed' => $replayed, 'connection_secret' => $secret,
            'authenticated_contact_observed' => $details['authenticated_contact_observed'] ?? null,
            'student_sync_started' => false, 'gate_policy_activated' => false];
    }
}
