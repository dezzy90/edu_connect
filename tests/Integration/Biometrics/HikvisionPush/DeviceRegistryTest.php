<?php

use App\Models\AdminUser;
use App\Models\V2\School;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use App\Services\Biometrics\Devices\DeviceScope;
use App\Services\Biometrics\Gate\DayProjector;
use App\Services\Biometrics\Gate\OutboxDispatcher;
use App\Services\Biometrics\Hikvision\EnrollmentPhoto;
use App\Services\Biometrics\Hikvision\EnrollmentRoster;
use App\Services\Biometrics\Hikvision\PushInbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Biometrics\GateFixture;
use Tests\Support\Biometrics\Hikvision\PushTerminalSimulator;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->fixture = GateFixture::boot();
    foreach (['2026_10_19_000021_create_hikvision_enrollments', '2026_10_20_000023_add_gate_only_account_restriction',
        '2026_10_21_000024_create_gate_setup_drafts', '2026_10_21_000025_create_device_registry'] as $migration) {
        (require base_path('database/migrations/'.$migration.'.php'))->up();
    }
    Carbon::setTestNow('2026-10-20T09:00:00Z');
    Http::preventStrayRequests();
    $this->fixture['tenant']->update(['source_system' => 'edu_admin', 'source_id' => '10']);
    $this->fixture['school']->update(['source_system' => 'edu_admin', 'source_id' => '26']);
    $this->schools = [$this->fixture['school']];
    foreach ([2, 3] as $number) {
        $this->schools[] = School::create(['tenant_id' => $this->fixture['tenant']->id, 'name' => 'SYNTHETIC School '.$number,
            'slug' => 'registry-school-'.$number, 'type' => 'secondary', 'status' => 'active', 'source_system' => 'edu_admin', 'source_id' => (string) (25 + $number)]);
    }
    config(['device_registry.enabled' => true, 'device_registry.read_only' => false, 'device_registry.registration_enabled' => true, 'device_registry.shared_gate_enabled' => true,
        'device_registry.tenant_ids' => [$this->fixture['tenant']->id], 'device_registry.school_ids' => array_map(fn ($s) => $s->id, $this->schools),
        'device_registry.receiver_mode' => 'unconfigured', 'device_registry.server_host' => '', 'device_registry.connection_enabled' => false]);
    $this->admin = AdminUser::create(['name' => 'SYNTHETIC device administrator', 'email' => 'device-admin@registry.test',
        'password' => 'SYNTHETIC-password', 'role' => 'school_admin', 'is_active' => true]);
    $this->admin->forceFill(['gate_only' => true])->save();
    DB::table('ec_device_admin_assignments')->insert(['tenant_id' => $this->fixture['tenant']->id, 'admin_id' => $this->admin->id, 'active' => true]);
    Sanctum::actingAs($this->admin, ['admin:*']);
});

afterEach(fn () => Carbon::setTestNow());

function registryBody(object $test, array $override = []): array
{
    return array_replace(['tenant_id' => $test->fixture['tenant']->id, 'school_ids' => array_map(fn ($s) => $s->id, $test->schools),
        'name' => 'SYNTHETIC Shared Main Gate', 'location' => 'Shared main entrance', 'serial' => 'SYNTHETIC-SHARED-TERMINAL',
        'firmware' => 'V4.48.0_build260417', 'expected_revision' => 0, 'idempotency_key' => 'SYNTHETIC-register-1', 'note' => 'SYNTHETIC installation acceptance'], $override);
}

function registryCreate(object $test): int
{
    return $test->postJson('/api/admin/v2/gate/devices', registryBody($test))->assertOk()->json('data.device_id');
}

function registryAction(object $test, int $id, string $operation, array $override = [])
{
    return $test->postJson('/api/admin/v2/gate/devices/'.$id.'/actions', array_replace(['operation' => $operation,
        'expected_revision' => (int) DB::table('ec_device_registry')->where('device_id', $id)->value('revision'),
        'idempotency_key' => 'SYNTHETIC-'.$operation.'-1', 'note' => 'SYNTHETIC '.$operation.' acceptance',
        ...($operation === 'verify' || $operation === 'edit' ? [] : ['confirmation' => 'SYNTHETIC-SHARED-TERMINAL'])], $override));
}

it('registers one shared gate for three schools without enabling transport or distributing students', function (): void {
    $before = DB::table('ec_gate_policies')->get()->toJson();
    $response = $this->postJson('/api/admin/v2/gate/devices', registryBody($this))->assertOk()
        ->assertJsonPath('data.student_sync_started', false)->assertJsonPath('data.gate_policy_activated', false);
    $id = $response->json('data.device_id');
    $secret = $response->json('data.connection_secret.password');
    expect(strlen($secret))->toBe(32);
    expect($secret)->toMatch('/^[a-f0-9]{32}$/D');
    $push = DB::table('ec_hikvision_push_devices')->where('device_id', $id)->sole();
    expect((bool) $push->enabled)->toBeFalse();
    expect($push->credential_encrypted)->not->toContain($secret);
    expect(DeviceScope::schools($push))->toBe(array_map(fn ($s) => $s->id, $this->schools));
    expect(DB::table('ec_device_registry_audits')->count())->toBe(1);
    expect(DB::table('ec_hikvision_enrollment_commands')->count())->toBe(0);
    expect(DB::table('ec_gate_policies')->get()->toJson())->toBe($before);
    $view = $this->getJson('/api/admin/v2/gate/devices/'.$id)->assertOk()->assertJsonPath('data.detail.scope_mode', 'shared_gate')
        ->assertJsonCount(3, 'data.detail.schools')->assertJsonPath('data.detail.capacity.verified_limit', null)
        ->assertJsonPath('data.detail.student_sync_enabled', false);
    foreach ([$secret, 'credential_encrypted', 'request_hash', 'request_id', 'challenge', 'salt', 'photo_encrypted'] as $private) {
        expect($view->getContent())->not->toContain($private);
    }
    expect(DB::table('ec_device_registry_audits')->get()->toJson())->not->toContain($secret, 'credential_encrypted', 'photo_encrypted');
    Http::assertNothingSent();
});

it('enforces a read-only deployment at the service boundary even when individual write flags remain enabled', function (string $operation): void {
    $id = registryCreate($this);
    $before = DB::table('ec_device_registry_audits')->get()->toJson();
    config(['device_registry.read_only' => true]);
    $this->getJson('/api/admin/v2/gate/devices/'.$id)->assertOk()->assertJsonPath('data.readOnly', true)->assertJsonPath('data.registrationEnabled', false);
    if ($operation === 'register') {
        $this->postJson('/api/admin/v2/gate/devices', registryBody($this, ['serial' => 'SYNTHETIC-DISABLED', 'idempotency_key' => 'SYNTHETIC-readonly-new']))->assertStatus(409);
    } else {
        registryAction($this, $id, $operation, match ($operation) {
            'edit' => ['name' => 'Blocked edit', 'location' => 'Unchanged location'],
            'reassign' => ['school_ids' => [$this->schools[0]->id]],
            default => [],
        })->assertStatus(409);
    }
    expect(DB::table('ec_device_registry_audits')->get()->toJson())->toBe($before);
    expect(DB::table('ec_device_registry')->count())->toBe(1);
    Http::assertNothingSent();
})->with(['register', 'edit', 'reassign', 'enable', 'pause', 'rotate', 'retire', 'verify']);

it('keeps ordinary administrators and school staff out without a specific complex grant', function (string $role): void {
    DB::table('ec_device_admin_assignments')->delete();
    $this->admin->update(['role' => $role]);
    $this->getJson('/api/admin/v2/gate/devices')->assertForbidden();
    $this->postJson('/api/admin/v2/gate/devices', registryBody($this))->assertNotFound();
})->with(['super_admin', 'school_admin']);

it('requires every participating school to belong to the same authorised complex', function (): void {
    $other = Tenant::create(['name' => 'SYNTHETIC other complex', 'slug' => 'registry-other', 'status' => 'active']);
    $school = School::create(['tenant_id' => $other->id, 'name' => 'SYNTHETIC other school', 'slug' => 'other-school', 'type' => 'secondary', 'status' => 'active']);
    config(['device_registry.school_ids' => [...config('device_registry.school_ids'), $school->id]]);
    $this->postJson('/api/admin/v2/gate/devices', registryBody($this, ['school_ids' => [$this->schools[0]->id, $school->id]]))->assertNotFound();
    expect(DB::table('ec_device_registry')->count())->toBe(0);
});

it('rejects malformed identity scope and forged privileged fields', function (array $override): void {
    $this->postJson('/api/admin/v2/gate/devices', registryBody($this, $override))->assertUnprocessable();
    expect(DB::table('ec_device_registry')->count())->toBe(0);
})->with([
    [['school_ids' => []]], [['school_ids' => [1, 1]]], [['school_ids' => ['1']]], [['school_ids' => ['x' => 1]]],
    [['tenant_id' => '1']], [['expected_revision' => '0']], [['serial' => '../serial']], [['firmware' => 'Not verified']],
    [['password' => 'forged']], [['enabled' => true]], [['settings' => ['all_schools' => true]]], [['operation' => 'retire']],
    [['note' => '         x']], [['name' => '        ']],
]);

it('does not register a shared gate without the separate shared-runtime opt-in', function (): void {
    config(['device_registry.shared_gate_enabled' => false]);
    $this->postJson('/api/admin/v2/gate/devices', registryBody($this))->assertStatus(409);
    $this->postJson('/api/admin/v2/gate/devices', registryBody($this, ['school_ids' => [$this->schools[0]->id]]))->assertOk();
});

it('requires distinct authoritative school identities before sharing hardware', function (): void {
    $this->schools[1]->update(['source_id' => '']);
    $this->postJson('/api/admin/v2/gate/devices', registryBody($this))->assertStatus(409);
    expect(DB::table('ec_device_registry')->count())->toBe(0);
});

it('deduplicates registration retries and never exposes credentials through GET or after expiry', function (): void {
    $body = registryBody($this);
    $first = $this->postJson('/api/admin/v2/gate/devices', $body)->assertOk()->json('data');
    $this->postJson('/api/admin/v2/gate/devices', $body)->assertOk()->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.connection_secret.password', $first['connection_secret']['password']);
    $this->postJson('/api/admin/v2/gate/devices', registryBody($this, ['name' => 'Different']))->assertStatus(409);
    Carbon::setTestNow(now()->addMinutes(11));
    $this->postJson('/api/admin/v2/gate/devices', $body)->assertOk()->assertJsonPath('data.connection_secret', null);
    expect(DB::table('ec_device_registry_audits')->count())->toBe(1);
    $this->getJson('/api/admin/v2/gate/devices/'.$first['device_id'])->assertOk()->assertDontSee($first['connection_secret']['password']);
    DB::table('ec_device_admin_assignments')->update(['active' => false]);
    $this->postJson('/api/admin/v2/gate/devices', $body)->assertNotFound();
});

it('blocks duplicate hardware serials even when spelling changes case', function (): void {
    registryCreate($this);
    $this->postJson('/api/admin/v2/gate/devices', registryBody($this, ['serial' => 'synthetic-shared-terminal', 'idempotency_key' => 'SYNTHETIC-duplicate']))->assertUnprocessable();
    expect(DB::table('ec_device_registry')->count())->toBe(1);
});

it('will not allow a connection on an unconfigured or isolated receiver', function (): void {
    $id = registryCreate($this);
    registryAction($this, $id, 'enable')->assertStatus(409);
    registryAction($this, $id, 'verify')->assertOk()->assertJsonPath('data.authenticated_contact_observed', false);
    expect(DB::table('ec_device_registry')->where('device_id', $id)->value('last_verified_at'))->toBeNull();
    Http::assertNothingSent();
});

it('uses authenticated receiver evidence rather than an administrator claim or network probe', function (): void {
    $id = registryCreate($this);
    config(['device_registry.server_host' => 'collector.example.test', 'device_registry.receiver_mode' => 'application',
        'device_registry.connection_enabled' => true, 'hikvision_push.enabled' => true]);
    registryAction($this, $id, 'enable')->assertOk();
    $push = DB::table('ec_hikvision_push_devices')->where('device_id', $id)->sole();
    DB::table('ec_hikvision_push_sessions')->insert(['push_device_id' => $push->id, 'phase' => 'online', 'challenge' => str_repeat('a', 64),
        'salt' => str_repeat('b', 64), 'iterations' => 5000, 'expires_at' => now()->timestamp + 15, 'last_seen_at' => now()->utc()]);
    registryAction($this, $id, 'verify')->assertOk()->assertJsonPath('data.authenticated_contact_observed', true);
    Carbon::setTestNow(now()->addSeconds(16));
    $this->getJson('/api/admin/v2/gate/devices/'.$id)->assertOk()->assertJsonPath('data.detail.status.key', 'awaiting');
    Http::assertNothingSent();
});

it('keeps revision conflicts and incomplete audit writes atomic', function (): void {
    $id = registryCreate($this);
    registryAction($this, $id, 'edit', ['name' => 'Updated gate', 'location' => 'East entrance'])->assertOk();
    registryAction($this, $id, 'edit', ['name' => 'Stale name', 'location' => 'East entrance', 'expected_revision' => 1, 'idempotency_key' => 'SYNTHETIC-stale'])->assertStatus(409);
    DB::unprepared("CREATE TRIGGER registry_audit_fail BEFORE INSERT ON ec_device_registry_audits BEGIN SELECT RAISE(ABORT, 'SYNTHETIC failure'); END");
    registryAction($this, $id, 'edit', ['name' => 'Must roll back', 'location' => 'East entrance', 'idempotency_key' => 'SYNTHETIC-rollback'])->assertStatus(409);
    expect(DB::table('ec_biometric_devices')->where('id', $id)->value('name'))->toBe('Updated gate');
    expect(DB::table('ec_device_registry')->where('device_id', $id)->value('revision'))->toBe(2);
});

it('protects the existing working terminal from reassignment and credential rotation', function (): void {
    $id = $this->fixture['device']->id;
    $before = DB::table('ec_hikvision_push_devices')->get()->toJson();
    registryAction($this, $id, 'rotate')->assertStatus(409);
    registryAction($this, $id, 'reassign', ['school_ids' => array_map(fn ($s) => $s->id, $this->schools)])->assertStatus(409);
    expect(DB::table('ec_hikvision_push_devices')->get()->toJson())->toBe($before);
});

it('routes a restricted device administrator to the isolated inventory entry', function (): void {
    $this->withHeader('X-Inertia', 'true')->post('/admin/login', ['email' => $this->admin->email, 'password' => 'SYNTHETIC-password'])
        ->assertStatus(409)->assertHeader('X-Inertia-Location', '/admin/gate/devices');
    $this->getJson('/admin/students')->assertForbidden();
    $this->getJson('/api/admin/v2/gate/setup')->assertStatus(409);
});

function registrySharedFixture(object $test): array
{
    $id = registryCreate($test);
    $device = DB::table('ec_biometric_devices')->where('id', $id)->sole();
    $push = DB::table('ec_hikvision_push_devices')->where('device_id', $id)->sole();
    DB::table('ec_device_school_assignments')->where('device_id', $id)->update(['authorized_from' => '2026-10-19 00:00:00']);
    $students = [];
    foreach ($test->schools as $i => $school) {
        $student = Student::create(['tenant_id' => $school->tenant_id, 'school_id' => $school->id, 'student_number' => 'SHARED-'.$i,
            'first_name' => 'SYNTHETIC', 'last_name' => 'School '.$i, 'status' => 'enrolled', 'device_sync_enabled' => true,
            'source_system' => 'edu_admin', 'source_id' => (string) (3000 + $i)]);
        DB::table('ec_device_personnel')->insert(['tenant_id' => $school->tenant_id, 'school_id' => $school->id,
            'device_id' => $id, 'student_id' => $student->id, 'person_identifier' => 'EC'.$student->id,
            'sync_status' => 'acknowledged', 'school_bound_at' => '2026-10-19 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $policy = array_replace($test->fixture['policy'], ['school_id' => $school->id, 'version' => 'SYNTHETIC-shared-'.$i, 'late_cutoff' => $i === 0 ? '07:15:00' : '08:00:00']);
        DB::table('ec_gate_policies')->insert(['id' => hash('sha256', $policy['version']), 'tenant_id' => $school->tenant_id,
            'school_id' => $school->id, 'day' => $policy['day'], 'timezone' => $policy['timezone'], 'version' => $policy['version'],
            'snapshot' => json_encode($policy), 'student_ids' => json_encode([$student->id]), 'device_ids' => json_encode([$id]),
            'effective_from' => '2026-10-20 00:00:00', 'enabled' => true, 'confirmed_by' => 1]);
        $students[] = $student;
    }
    config(['gate.school_ids' => array_map(fn ($s) => $s->id, $test->schools)]);

    return compact('device', 'push', 'students');
}

function registryScan(array $shared, int $index, int $serial = 1, string $uuid = 'SYNTHETIC-shared-event', string $occurred = '2026-10-20T07:30:00+01:00'): array
{
    $entry = ['UUID' => $uuid, 'dataFormat' => 'jsonData', 'data' => base64_encode(json_encode([
        'eventType' => 'AccessControllerEvent', 'dateTime' => $occurred,
        'AccessControllerEvent' => ['majorEventType' => 5, 'subEventType' => 75, 'serialNo' => $serial,
            'employeeNoString' => 'EC'.$shared['students'][$index]->id],
    ]))];

    if (isset($shared['terminal'])) {
        return $shared['terminal']->send('Event', ['eventNum' => 1, 'eventList' => [$entry]])->assertOk()->json();
    }

    return DB::transaction(fn () => app(PushInbox::class)->capture($shared['push'], $entry));
}

it('routes three schools through one terminal while retaining separate rules and outbox destinations', function (): void {
    $shared = registrySharedFixture($this);
    foreach ([0, 1, 2] as $i) {
        registryScan($shared, $i, $i + 1, 'SYNTHETIC-event-'.$i);
    }
    app(DayProjector::class)->work();
    expect(DB::table('ec_gate_days')->count())->toBe(3);
    $heads = DB::table('ec_gate_movements')->get()->map(fn ($m) => json_decode($m->payload, true))->sortBy('school_id')->values();
    expect($heads)->toHaveCount(3);
    expect($heads->pluck('school_id')->all())->toBe(array_map(fn ($s) => $s->id, $this->schools));
    expect($heads->pluck('late')->all())->toBe([true, false, false]);
    expect($heads->pluck('device_uid')->unique()->all())->toBe([$shared['device']->device_uid]);
    expect(DB::table('ec_attendance_events')->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps physical event deduplication stable after school assignment revocation', function (): void {
    $shared = registrySharedFixture($this);
    registryScan($shared, 1);
    $first = DB::table('ec_hikvision_observations')->sole();
    Carbon::setTestNow(now()->addSecond());
    DB::table('ec_device_school_assignments')->where('device_id', $shared['device']->id)->where('school_id', $this->schools[1]->id)
        ->update(['active' => false, 'revoked_at' => now()->utc()]);
    registryScan($shared, 1, 1, 'SYNTHETIC-retry-another-uuid');
    expect(DB::table('ec_hikvision_observations')->count())->toBe(1);
    expect(DB::table('ec_hikvision_observations')->sole())->toEqual($first);
    app(DayProjector::class)->work();
    expect(DB::table('ec_gate_days')->sole()->school_id)->toBe($this->schools[1]->id);
});

it('holds unknown, disabled or stale shared-school mappings instead of routing them to the owner school', function (string $case): void {
    $shared = registrySharedFixture($this);
    match ($case) {
        'disabled' => config(['device_registry.shared_gate_enabled' => false]),
        'stale' => DB::table('ec_device_personnel')->where('device_id', $shared['device']->id)->update(['school_bound_at' => '2026-10-20 08:00:00']),
        'missing' => DB::table('ec_device_personnel')->where('device_id', $shared['device']->id)->delete(),
        'inactive' => $this->schools[1]->update(['status' => 'inactive']),
    };
    registryScan($shared, 1);
    expect(DB::table('ec_hikvision_observations')->sole()->status)->toBe('held_person_mapping');
    app(DayProjector::class)->work();
    expect(DB::table('ec_gate_movements')->count())->toBe(0);
})->with(['disabled', 'stale', 'missing', 'inactive']);

it('offers the same shared gate in each participating school setup without sharing student lists', function (): void {
    $shared = registrySharedFixture($this);
    config(['gate.setup_enabled' => true, 'gate.setup_school_ids' => array_map(fn ($s) => $s->id, $this->schools)]);
    foreach ($this->schools as $school) {
        DB::table('ec_gate_setup_assignments')->insert(['tenant_id' => $school->tenant_id, 'school_id' => $school->id, 'admin_id' => $this->admin->id, 'active' => true]);
        $reply = $this->getJson('/api/admin/v2/gate/setup?school_id='.$school->id)->assertOk()->assertJsonPath('data.deviceRegistryUrl', '/admin/gate/devices');
        expect(collect($reply->json('data.devices'))->pluck('id')->all())->toContain($shared['device']->id);
        expect(collect($reply->json('data.students.data'))->pluck('id')->all())->toContain($shared['students'][array_search($school, $this->schools, true)]->id);
    }
});

it('hides indivisible shared installations when even one participating school leaves the administrators configured scope', function (): void {
    $id = registryCreate($this);
    config(['device_registry.school_ids' => [$this->schools[0]->id]]);
    $this->getJson('/api/admin/v2/gate/devices')->assertOk()->assertJsonPath('data.devices.total', 1);
    $this->getJson('/api/admin/v2/gate/devices/'.$id)->assertNotFound();
    registryAction($this, $id, 'edit', ['name' => 'Forbidden partial edit', 'location' => 'Unchanged'])->assertNotFound();
});

it('refuses an unused-device scope change when a school draft references the terminal', function (): void {
    $id = registryCreate($this);
    DB::table('ec_gate_setup_drafts')->insert(['tenant_id' => $this->fixture['tenant']->id, 'school_id' => $this->schools[1]->id,
        'revision' => 1, 'configuration' => json_encode(['device_ids' => [$id]]), 'updated_by' => $this->admin->id]);
    registryAction($this, $id, 'reassign', ['school_ids' => [$this->schools[0]->id]])->assertStatus(409);
    expect(DB::table('ec_device_school_assignments')->where('device_id', $id)->where('active', true)->count())->toBe(3);
});

it('provides school setup links only for explicitly granted schools', function (): void {
    $id = registryCreate($this);
    config(['gate.setup_enabled' => true, 'gate.setup_school_ids' => array_map(fn ($s) => $s->id, $this->schools)]);
    DB::table('ec_gate_setup_assignments')->insert(['tenant_id' => $this->fixture['tenant']->id, 'school_id' => $this->schools[1]->id,
        'admin_id' => $this->admin->id, 'active' => true]);
    $response = $this->getJson('/api/admin/v2/gate/devices/'.$id)->assertOk();
    $links = collect($response->json('data.detail.schools'))->pluck('setup_url')->filter()->values()->all();
    expect($links)->toBe(['/admin/gate/setup?school_id='.$this->schools[1]->id]);
});

it('reassigns only unused registrations within one complex and retains the previous school epochs', function (): void {
    $id = registryCreate($this);
    $old = DB::table('ec_hikvision_push_devices')->where('device_id', $id)->sole();
    registryAction($this, $id, 'reassign', ['school_ids' => [$this->schools[1]->id, $this->schools[2]->id]])->assertOk()
        ->assertJsonPath('data.current_revision', 2);
    $new = DB::table('ec_hikvision_push_devices')->where('device_id', $id)->sole();
    expect($new->school_id)->toBe($this->schools[1]->id);
    expect($new->credential_encrypted)->not->toBe($old->credential_encrypted);
    expect(Crypt::decryptString($new->credential_encrypted))->toMatch('/^[a-f0-9]{32}$/D');
    expect($new->deployment_epoch)->not->toBe($old->deployment_epoch);
    expect(DeviceScope::schools($new))->toBe([$this->schools[1]->id, $this->schools[2]->id]);
    expect(DB::table('ec_device_school_assignments')->where('device_id', $id)->where('active', false)->count())->toBe(3);
    expect(DB::table('ec_device_school_assignments')->where('device_id', $id)->where('active', true)->count())->toBe(2);
    expect(DB::table('ec_device_personnel')->where('device_id', $id)->count())->toBe(0);
});

it('rotates an unused registrations dedicated credentials without disclosing them to another complex administrator', function (): void {
    $id = registryCreate($this);
    $first = registryAction($this, $id, 'rotate')->assertOk()->json('data.connection_secret.password');
    expect(strlen($first))->toBe(32);
    expect($first)->toMatch('/^[a-f0-9]{32}$/D');
    registryAction($this, $id, 'rotate', ['expected_revision' => 1])->assertOk()->assertJsonPath('data.connection_secret.password', $first);
    $another = AdminUser::create(['name' => 'SYNTHETIC second technician', 'email' => 'other@registry.test', 'password' => 'SYNTHETIC-password', 'role' => 'school_admin', 'is_active' => true]);
    DB::table('ec_device_admin_assignments')->insert(['tenant_id' => $this->fixture['tenant']->id, 'admin_id' => $another->id, 'active' => true]);
    Sanctum::actingAs($another, ['admin:*']);
    $this->getJson('/api/admin/v2/gate/devices/'.$id)->assertOk()->assertDontSee($first);
    registryAction($this, $id, 'rotate', ['expected_revision' => 1])->assertStatus(409);
});

it('retires without erasing history or reissuing credentials from a former registration request', function (): void {
    $id = registryCreate($this);
    registryAction($this, $id, 'retire')->assertOk();
    $this->getJson('/api/admin/v2/gate/devices/'.$id)->assertOk()->assertJsonPath('data.detail.status.key', 'retired');
    $this->postJson('/api/admin/v2/gate/devices', registryBody($this))->assertOk()->assertJsonPath('data.connection_secret', null);
    expect(DB::table('ec_device_registry_audits')->where('device_id', $id)->count())->toBe(2);
    expect(DB::table('ec_device_school_assignments')->where('device_id', $id)->count())->toBe(3);
    expect(DB::table('ec_hikvision_push_devices')->where('device_id', $id)->exists())->toBeTrue();
});

function registryEnrollmentFixture(object $test, int $capacity = 3): array
{
    $shared = registrySharedFixture($test);
    Carbon::setTestNow('2026-10-20T06:00:00Z');
    DB::table('ec_device_personnel')->where('device_id', $shared['device']->id)->delete();
    DB::table('ec_hikvision_push_devices')->where('id', $shared['push']->id)->update(['enabled' => true]);
    $shared['push'] = DB::table('ec_hikvision_push_devices')->where('id', $shared['push']->id)->sole();
    DB::table('ec_biometric_devices')->where('id', $shared['device']->id)->update(['settings' => json_encode(['hikvision_enrollment' => [
        'enabled' => true, 'verified' => true, 'capacity' => $capacity, 'unmanaged_count' => 0, 'fdid' => '1',
        'model' => $shared['push']->model, 'firmware' => $shared['push']->firmware,
    ]])]);
    config(['hikvision_push.enabled' => true, 'hikvision_push.allow_loopback_http' => true, 'hikvision_push.enrollment_enabled' => true,
        'hikvision_push.enrollment_device_writes_enabled' => true, 'hikvision_push.enrollment_connection_ids' => []]);
    $test->fixture['connection']->update(['enabled_school_ids' => array_map(fn ($s) => $s->id, $test->schools)]);
    foreach ($shared['students'] as $student) {
        DB::table('ec_integration_mappings')->insert(['connection_id' => $test->fixture['connection']->id, 'local_type' => 'student',
            'local_id' => $student->id, 'external_type' => 'student', 'external_id' => $student->source_id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $terminal = new PushTerminalSimulator(fn ($op, $body, $headers) => $test->postJson(
        '/iot/'.$shared['push']->serial.'/global/0-global/model/service/operate/PUSH/'.$op, $body, $headers),
        $shared['push']->username, Crypt::decryptString($shared['push']->credential_encrypted));
    $terminal->connect()->assertOk();
    $shared['terminal'] = $terminal;

    return $shared;
}

function registryPreparePortrait(object $test, Student $student, string $revision = 'a'): void
{
    $image = imagecreatetruecolor(320, 400);
    imagefill($image, 0, 0, imagecolorallocate($image, 50, 100, 140));
    ob_start();
    imagejpeg($image, null, 90);
    $bytes = ob_get_clean();
    imagedestroy($image);
    $hash = str_repeat($revision, 64);
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(['https://eduadmin.test/*' => Http::response($bytes, 200,
        ['X-Enrollment-Photo-Revision' => $hash, 'X-Content-SHA256' => hash('sha256', $bytes)])]);
    app(EnrollmentPhoto::class)->stage($student->refresh(), $test->fixture['connection'], ['revision' => $hash]);
    app(EnrollmentPhoto::class)->prepare($student->id);
}

function registryPoll(array $shared): array
{
    return $shared['terminal']->send('CommandRequest')->assertOk()->assertJsonPath('commandNum', 1)->json('commandList.0');
}

function registryReply(array $shared, array $command, array $body): void
{
    $shared['terminal']->send('CommandResult', ['commandNum' => 1, 'commandList' => [
        ['UUID' => $command['UUID'], 'data' => base64_encode(json_encode($body, JSON_THROW_ON_ERROR))],
    ]])->assertOk();
}

function registryCompleteEnrollment(array $shared, Student $student, bool $update = false): void
{
    if (! $update) {
        registryReply($shared, registryPoll($shared), ['UserInfoSearch' => ['numOfMatches' => 0, 'UserInfo' => []]]);
    }
    $person = registryPoll($shared);
    $body = json_decode(base64_decode($person['data']), true)['UserInfo'];
    expect($body['employeeNo'])->toBe('EC'.$student->id);
    expect($body['onlyVerify'])->toBeTrue();
    expect($body['localUIRight'])->toBeFalse();
    registryReply($shared, $person, ['statusCode' => 1]);
    registryReply($shared, registryPoll($shared), ['statusCode' => 1]);
    registryReply($shared, registryPoll($shared), ['UserInfoSearch' => ['numOfMatches' => 1,
        'UserInfo' => [['employeeNo' => 'EC'.$student->id, 'numOfFace' => 1, 'onlyVerify' => true]]]]);
}

it('synchronises three school portraits through one authenticated terminal and preserves the original school binding on updates', function (): void {
    $shared = registryEnrollmentFixture($this);
    foreach ($shared['students'] as $student) {
        registryPreparePortrait($this, $student);
        registryCompleteEnrollment($shared, $student);
    }
    expect(DB::table('ec_hikvision_enrollments')->where('state', 'device_ready')->count())->toBe(3);
    expect(DB::table('ec_device_personnel')->where('device_id', $shared['device']->id)->pluck('school_id')->all())->toBe(array_map(fn ($s) => $s->id, $this->schools));
    $before = DB::table('ec_device_personnel')->where('student_id', $shared['students'][1]->id)->value('school_bound_at');
    Carbon::setTestNow(now()->addMinutes(5));
    $shared['terminal']->connect()->assertOk();
    registryPreparePortrait($this, $shared['students'][1], 'b');
    registryCompleteEnrollment($shared, $shared['students'][1], true);
    expect(DB::table('ec_device_personnel')->where('student_id', $shared['students'][1]->id)->value('school_bound_at'))->toBe($before);
    Carbon::setTestNow('2026-10-20T06:31:00Z');
    $shared['terminal']->connect()->assertOk();
    foreach ([0, 1, 2] as $i) {
        registryScan($shared, $i, $i + 1, 'SYNTHETIC-enrolled-'.$i);
    }
    app(DayProjector::class)->work();
    expect(DB::table('ec_gate_movements')->count())->toBe(3);
});

it('enforces one combined capacity rather than granting a fresh capacity allowance to each school', function (): void {
    $shared = registryEnrollmentFixture($this, 2);
    registryPreparePortrait($this, $shared['students'][0]);
    registryCompleteEnrollment($shared, $shared['students'][0]);
    registryPreparePortrait($this, $shared['students'][1]);
    registryCompleteEnrollment($shared, $shared['students'][1]);
    registryPreparePortrait($this, $shared['students'][2]);
    $shared['terminal']->send('CommandRequest')->assertOk()->assertJsonPath('commandNum', 0);
    expect(DB::table('ec_hikvision_enrollments')->where('student_id', $shared['students'][2]->id)->sole()->error_code)->toBe('device_capacity_exceeded');
    expect(DB::table('ec_device_personnel')->where('device_id', $shared['device']->id)->count())->toBe(2);
});

it('revokes only the removed schools owned identities without blocking the other two schools', function (): void {
    $shared = registryEnrollmentFixture($this);
    foreach ($shared['students'] as $student) {
        registryPreparePortrait($this, $student);
        registryCompleteEnrollment($shared, $student);
    }
    Carbon::setTestNow(now()->addSecond());
    DB::table('ec_device_school_assignments')->where('device_id', $shared['device']->id)->where('school_id', $this->schools[1]->id)
        ->update(['active' => false, 'revoked_at' => now()]);
    app(EnrollmentRoster::class)->reconcile($shared['students'][1]->id);
    $remove = registryPoll($shared);
    expect(json_decode(base64_decode($remove['data']), true)['UserInfoDetail']['EmployeeNoList'])->toBe([['employeeNo' => 'EC'.$shared['students'][1]->id]]);
    registryReply($shared, $remove, ['statusCode' => 1]);
    registryReply($shared, registryPoll($shared), ['UserInfoDetailDeleteProcess' => ['status' => 'success']]);
    registryReply($shared, registryPoll($shared), ['UserInfoSearch' => ['numOfMatches' => 0, 'UserInfo' => []]]);
    expect(DB::table('ec_device_personnel')->where('device_id', $shared['device']->id)->pluck('school_id')->all())->toBe([$this->schools[0]->id, $this->schools[2]->id]);
    expect(DB::table('ec_hikvision_enrollments')->where('state', 'device_ready')->count())->toBe(2);
});

it('freezes the correct EduAdmin school and parent recipients for every school sharing one terminal', function (): void {
    $shared = registrySharedFixture($this);
    $connection = $this->fixture['connection'];
    $connection->update(['enabled_school_ids' => array_map(fn ($s) => $s->id, $this->schools)]);
    $parents = [];
    foreach ($this->schools as $i => $school) {
        if ($i > 0) {
            DB::table('ec_integration_mappings')->insert(['connection_id' => $connection->id, 'local_type' => 'school', 'local_id' => $school->id,
                'external_type' => 'school', 'external_id' => $school->source_id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ec_gate_mirror_bindings')->insert(['tenant_id' => $school->tenant_id, 'school_id' => $school->id,
                'connection_id' => $connection->id, 'source_id' => '71b8a962-1020-4026-a000-'.str_pad((string) (4 + $i), 12, '0', STR_PAD_LEFT), 'remote_school_id' => (int) $school->source_id, 'enabled' => true]);
        }
        $student = $shared['students'][$i];
        DB::table('ec_integration_mappings')->insert(['connection_id' => $connection->id, 'local_type' => 'student', 'local_id' => $student->id,
            'external_type' => 'student', 'external_id' => $student->source_id, 'created_at' => now(), 'updated_at' => now()]);
        $parents[] = GateFixture::parent(array_replace($this->fixture, ['school' => $school, 'student' => $student]), 'shared-'.$i);
        registryScan($shared, $i, $i + 1, 'SYNTHETIC-destination-'.$i);
    }
    app(DayProjector::class)->work();
    foreach ($this->schools as $i => $school) {
        $outbox = DB::table('ec_gate_outbox as o')->join('ec_gate_days as d', 'd.id', '=', 'o.day_id')->where('d.school_id', $school->id)->get(['o.*']);
        expect($outbox)->toHaveCount(2);
        $mirror = json_decode($outbox->firstWhere('destination', 'edu_admin')->delivery_snapshot, true);
        expect($mirror['school_id'])->toBe((int) $school->source_id);
        expect($mirror['student_id'])->toBe((int) $shared['students'][$i]->source_id);
        expect($mirror['source_id'])->toBe('71b8a962-1020-4026-a000-'.str_pad((string) (4 + $i), 12, '0', STR_PAD_LEFT));
        expect(json_decode($outbox->firstWhere('destination', 'parents')->delivery_snapshot, true)['parent_ids'])->toBe([$parents[$i]->id]);
        expect(json_decode($outbox->first()->payload, true)['school_id'])->toBe($school->id);
    }
    expect(DB::table('ec_mobile_notifications')->count())->toBe(0);
    Http::assertNothingSent();
});

it('holds an accidentally reused EduAdmin source instead of treating a shared terminal as a shared school identity', function (): void {
    $shared = registrySharedFixture($this);
    DB::table('ec_gate_mirror_bindings')->insert(['tenant_id' => $this->fixture['tenant']->id, 'school_id' => $this->schools[1]->id,
        'connection_id' => $this->fixture['connection']->id, 'source_id' => '71b8a962-1020-4026-a000-000000000004',
        'remote_school_id' => 27, 'enabled' => true]);
    expect(\App\Services\Biometrics\Gate\DeliverySnapshot::mirror(['event_id' => str_repeat('c', 64), 'tenant_id' => $this->fixture['tenant']->id,
        'school_id' => $this->schools[0]->id, 'student_id' => $this->fixture['student']->id]))->toBeNull();
    Http::assertNothingSent();
});

it('rehearses three-school enrollment signed delivery parent isolation and scoped removal through one simulated terminal', function (): void {
    Queue::fake();
    config(['gate.parent_push_enabled' => false]);
    $shared = registryEnrollmentFixture($this);
    $parents = [];
    $sources = [];
    foreach ($this->schools as $i => $school) {
        $sources[$i] = '71b8a962-1020-4026-a000-'.str_pad((string) (4 + $i), 12, '0', STR_PAD_LEFT);
        if ($i > 0) {
            DB::table('ec_integration_mappings')->insert(['connection_id' => $this->fixture['connection']->id, 'local_type' => 'school',
                'local_id' => $school->id, 'external_type' => 'school', 'external_id' => $school->source_id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ec_gate_mirror_bindings')->insert(['tenant_id' => $school->tenant_id, 'school_id' => $school->id,
                'connection_id' => $this->fixture['connection']->id, 'source_id' => $sources[$i],
                'remote_school_id' => (int) $school->source_id, 'enabled' => true]);
        }
        $student = $shared['students'][$i];
        $student->update(['mobile_visible' => true]);
        $parents[$i] = GateFixture::parent(array_replace($this->fixture, ['school' => $school, 'student' => $student]), 'rehearsal-'.$i);
        registryPreparePortrait($this, $student);
        registryCompleteEnrollment($shared, $student);
    }
    expect(DB::table('ec_hikvision_enrollments')->where('state', 'device_ready')->count())->toBe(3);
    expect(DB::table('ec_device_personnel')->where('device_id', $shared['device']->id)->count())->toBe(3);

    // The mirror is memory-only. A lost acknowledgement cannot make a second remote record.
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    $mirrorRecords = [];
    $requests = [];
    $lost = false;
    Http::fake(['https://eduadmin.test/api/v1/integrations/edu-connect/gate-events' => function ($request) use (&$mirrorRecords, &$requests, &$lost, $sources) {
        $body = json_decode($request->body(), true, 16, JSON_THROW_ON_ERROR);
        $event = $body['events'][0];
        $key = $event['event_id'].':'.$event['revision'];
        $source = $body['source_id'];
        $duplicate = isset($mirrorRecords[$source][$key]);
        if ($duplicate && $mirrorRecords[$source][$key] !== $event) {
            return Http::response('', 409);
        }
        $mirrorRecords[$source][$key] = $event;
        $requests[] = ['source' => $source, 'event' => $event,
            'signed' => $request->hasHeader('X-Edu-Connect-Signature', 'sha256='.hash_hmac('sha256',
                $request->header('X-Edu-Connect-Timestamp')[0].'.'.$request->body(), 'SYNTHETIC-SECRET')),
            'authorized' => $request->hasHeader('Authorization', 'Bearer SYNTHETIC-TOKEN')];
        if ($source === $sources[1] && ! $lost) {
            $lost = true;

            return Http::response('', 503);
        }

        return Http::response(['accepted' => $duplicate ? [] : [$key], 'duplicates' => $duplicate ? [$key] : []]);
    }]);
    Carbon::setTestNow('2026-10-20T06:31:00Z');
    $shared['terminal']->connect()->assertOk();
    foreach ([0, 1, 2] as $i) {
        registryScan($shared, $i, $i + 1, 'SYNTHETIC-rehearsal-'.$i);
        registryScan($shared, $i, $i + 1, 'SYNTHETIC-replay-'.$i);
    }
    app(DayProjector::class)->work();
    expect(DB::table('ec_hikvision_observations')->count())->toBe(3);
    expect(DB::table('ec_gate_movements')->count())->toBe(3);
    expect(app(OutboxDispatcher::class)->work())->toBe(['sent' => 5, 'skipped' => 0, 'retry' => 1, 'held' => 0]);
    expect(app(OutboxDispatcher::class)->work()['sent'])->toBe(0);
    Carbon::setTestNow(now()->addSeconds(16));
    expect(app(OutboxDispatcher::class)->work())->toBe(['sent' => 1, 'skipped' => 0, 'retry' => 0, 'held' => 0]);
    expect(DB::table('ec_gate_outbox')->where('status', 'sent')->count())->toBe(6);
    expect(DB::table('ec_mobile_notifications')->count())->toBe(3);
    expect(DB::table('ec_gate_parent_deliveries')->count())->toBe(3);
    expect($requests)->toHaveCount(4);
    foreach ($requests as $request) {
        expect($request['signed'])->toBeTrue()->and($request['authorized'])->toBeTrue();
    }
    foreach ($this->schools as $i => $school) {
        expect($mirrorRecords[$sources[$i]])->toHaveCount(1);
        $event = array_values($mirrorRecords[$sources[$i]])[0];
        expect($event['school_id'])->toBe((int) $school->source_id)
            ->and($event['student_id'])->toBe((int) $shared['students'][$i]->source_id)
            ->and($event['source_school_id'])->toBe($school->id)
            ->and($event['device_uid'])->toBe($shared['device']->device_uid)
            ->and($event['late'])->toBe($i === 0);
        $notice = DB::table('ec_mobile_notifications')->where('parent_account_id', $parents[$i]->id)->sole();
        expect((int) $notice->school_id)->toBe($school->id);
        expect((int) json_decode($notice->data, true)['student_id'])->toBe($shared['students'][$i]->id);
        Sanctum::actingAs($parents[$i]);
        $history = $this->getJson('/api/mobile/v2/children/'.$shared['students'][$i]->id.'/gate-history')->assertOk()
            ->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.event.movement', 'check_in')
            ->assertJsonMissingPath('data.data.0.event.device_uid')->assertJsonMissingPath('data.data.0.event.actor_id');
        expect($history->json('data.data.0.event_id'))->toBe($event['event_id']);
        foreach ($shared['students'] as $j => $student) {
            if ($j !== $i) {
                $this->getJson('/api/mobile/v2/children/'.$student->id.'/gate-history')->assertNotFound();
            }
        }
    }

    $historyBeforeRemoval = DB::table('ec_gate_movements')->orderBy('event_id')->get()->toJson();
    Carbon::setTestNow('2026-10-20T06:35:00Z');
    $shared['terminal']->connect()->assertOk();
    $shared['students'][1]->update(['status' => 'suspended']);
    app(EnrollmentRoster::class)->reconcile($shared['students'][1]->id);
    $remove = registryPoll($shared);
    expect(json_decode(base64_decode($remove['data']), true)['UserInfoDetail']['EmployeeNoList'])
        ->toBe([['employeeNo' => 'EC'.$shared['students'][1]->id]]);
    registryReply($shared, $remove, ['statusCode' => 1]);
    registryReply($shared, registryPoll($shared), ['UserInfoDetailDeleteProcess' => ['status' => 'success']]);
    registryReply($shared, registryPoll($shared), ['UserInfoSearch' => ['numOfMatches' => 0, 'UserInfo' => []]]);
    expect(DB::table('ec_hikvision_enrollments')->where('state', 'recognition_confirmed')->count())->toBe(2);
    expect(DB::table('ec_hikvision_enrollments')->where('student_id', $shared['students'][1]->id)->value('state'))->toBe('removed');
    expect(DB::table('ec_device_personnel')->where('device_id', $shared['device']->id)->orderBy('student_id')->pluck('student_id')->all())
        ->toBe([$shared['students'][0]->id, $shared['students'][2]->id]);
    Carbon::setTestNow('2026-10-20T06:36:00Z');
    $shared['terminal']->connect()->assertOk();
    foreach ([0, 1, 2] as $i) {
        registryScan($shared, $i, 101 + $i, 'SYNTHETIC-after-removal-'.$i, '2026-10-20T07:36:00+01:00');
    }
    expect(DB::table('ec_hikvision_observations')->where('status', 'held_person_mapping')->count())->toBe(1);
    app(DayProjector::class)->work();
    expect(DB::table('ec_gate_movements')->orderBy('event_id')->get()->toJson())->toBe($historyBeforeRemoval);
    expect(app(OutboxDispatcher::class)->work()['sent'])->toBe(0);
    expect(DB::table('ec_gate_outbox')->count())->toBe(6);
    expect(DB::table('ec_mobile_notifications')->count())->toBe(3);
    expect(DB::table('ec_attendance_events')->count())->toBe(0);
    expect(DB::table('ec_notification_deliveries')->count())->toBe(0);
    Http::assertSentCount(4);
    Queue::assertNothingPushed();
});
