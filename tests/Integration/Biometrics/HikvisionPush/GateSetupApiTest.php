<?php

use App\Models\AdminUser;
use App\Models\V2\ParentAccount;
use App\Models\V2\School;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use App\Services\Biometrics\Gate\SetupRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Biometrics\GateFixture;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->fixture = GateFixture::boot();
    $this->fixture['student']->update(['source_system' => 'edu_admin', 'source_id' => '2934', 'device_sync_enabled' => true]);
    (require base_path('database/migrations/2026_10_19_000021_create_hikvision_enrollments.php'))->up();
    (require base_path('database/migrations/2026_10_20_000023_add_gate_only_account_restriction.php'))->up();
    (require base_path('database/migrations/2026_10_21_000024_create_gate_setup_drafts.php'))->up();
    config(['gate.setup_enabled' => true, 'gate.setup_school_ids' => [$this->fixture['school']->id],
        'gate.enabled' => false, 'gate.operator_enabled' => false, 'hikvision_push.enrollment_enabled' => false]);
    Http::preventStrayRequests();
    $this->coordinator = AdminUser::create(['name' => 'SYNTHETIC coordinator', 'email' => 'coordinator@gate-setup.test',
        'password' => 'SYNTHETIC-password', 'role' => 'school_admin', 'is_active' => true]);
    $this->coordinator->forceFill(['gate_only' => true])->save();
    DB::table('ec_gate_setup_assignments')->insert(['tenant_id' => $this->fixture['tenant']->id,
        'school_id' => $this->fixture['school']->id, 'admin_id' => $this->coordinator->id, 'active' => true]);
    Sanctum::actingAs($this->coordinator, ['admin:*']);
    $this->url = '/api/admin/v2/gate/setup/schools/'.$this->fixture['school']->id;
});

function gateSetupBody(array $configuration = [], int $revision = 0, string $key = 'SYNTHETIC-setup-save'): array
{
    return ['configuration' => $configuration, 'expected_revision' => $revision, 'idempotency_key' => $key];
}

function gateSetupUntouched(): array
{
    $tables = ['ec_schools', 'ec_students', 'ec_biometric_devices', 'ec_hikvision_push_devices', 'ec_device_personnel',
        'ec_hikvision_enrollment_photos', 'ec_hikvision_enrollments', 'ec_hikvision_enrollment_commands',
        'ec_integration_connections', 'ec_integration_mappings', 'ec_gate_mirror_bindings', 'ec_gate_policies',
        'ec_gate_days', 'ec_gate_movements', 'ec_gate_movement_revisions', 'ec_gate_actions', 'ec_gate_outbox',
        'ec_gate_operator_assignments', 'ec_attendance_events', 'ec_mobile_notifications'];

    return collect($tables)->mapWithKeys(fn ($table) => [$table => json_encode(DB::table($table)->get())])->all();
}

it('prepares without live gate or device features and reads without creating a draft', function (): void {
    $before = gateSetupUntouched();
    $response = $this->getJson('/api/admin/v2/gate/setup')->assertOk()
        ->assertJsonPath('data.draft.revision', 0)->assertJsonPath('data.draft.configuration.arrival_start', null)
        ->assertJsonPath('data.draft.configuration.student_ids', [])->assertJsonPath('data.readiness.activation_available', false)
        ->assertJsonPath('data.connection.matched', true)->assertJsonPath('data.connection.eduadmin_school_id', 26)
        ->assertJsonPath('data.gateUrl', null);
    foreach (['csrfToken', 'encrypted_access_token', 'webhook_secret', 'credential_encrypted', 'photo_encrypted', 'request_hash', 'base_url'] as $private) {
        expect($response->getContent())->not->toContain('"'.$private.'"');
    }
    expect(DB::table('ec_gate_setup_drafts')->count())->toBe(0);
    expect(gateSetupUntouched())->toBe($before);
    Http::assertNothingSent();
});

it('saves incomplete drafts with an append-only audit and no live side effects', function (): void {
    $before = gateSetupUntouched();
    $this->postJson($this->url, gateSetupBody(['timezone' => 'Africa/Douala', 'student_ids' => [$this->fixture['student']->id]]))
        ->assertOk()->assertJsonPath('data.saved_revision', 1)->assertJsonPath('data.activated', false);
    $this->postJson($this->url, gateSetupBody(['timezone' => 'Africa/Douala', 'weekdays' => [5, 1]], 1, 'SYNTHETIC-setup-two'))->assertOk();
    $head = DB::table('ec_gate_setup_drafts')->sole();
    expect($head->revision)->toBe(2);
    $revisions = DB::table('ec_gate_setup_revisions')->orderBy('revision')->get();
    expect($revisions)->toHaveCount(2);
    expect(json_decode($revisions[0]->configuration, true)['student_ids'])->toBe([$this->fixture['student']->id]);
    expect(json_decode($head->configuration, true))->toMatchArray(['weekdays' => [1, 5], 'arrival_start' => null, 'student_ids' => []]);
    expect($revisions[1]->actor_id)->toBe($this->coordinator->id);
    expect($revisions[1]->basis_revision)->toBe(1);
    $this->getJson('/api/admin/v2/gate/setup')->assertOk()->assertJsonPath('data.draft.state', 'draft')
        ->assertJsonPath('data.readiness.configuration_complete', false)->assertJsonCount(2, 'data.history');
    expect(gateSetupUntouched())->toBe($before);
    Http::assertNothingSent();
});

it('does not infer setup authority from an administrator or operator role', function (string $role): void {
    $admin = AdminUser::create(['name' => 'SYNTHETIC unassigned', 'email' => 'unassigned@gate-setup.test',
        'password' => 'SYNTHETIC', 'role' => $role === 'super_admin' ? $role : 'school_admin', 'is_active' => true]);
    if ($role !== 'super_admin') {
        DB::table('ec_gate_operator_assignments')->insert(['tenant_id' => $this->fixture['tenant']->id,
            'school_id' => $this->fixture['school']->id, 'admin_id' => $admin->id, 'role' => $role, 'active' => true]);
    }
    Sanctum::actingAs($admin, ['admin:*']);
    $this->getJson('/api/admin/v2/gate/setup')->assertForbidden();
    $this->postJson($this->url, gateSetupBody())->assertNotFound();
})->with(['super_admin', 'guard', 'discipline_master', 'correction_approver']);

it('denies invalid setup scopes rather than falling back to all schools', function (mixed $scope): void {
    config(['gate.setup_school_ids' => $scope]);
    $this->getJson('/api/admin/v2/gate/setup')->assertStatus(409);
    $this->postJson($this->url, gateSetupBody())->assertStatus(409);
    expect(DB::table('ec_gate_setup_revisions')->count())->toBe(0);
})->with([[null], [false], [[]], [['1']], [[0]], [[-1]], [[1, 1]], [['school' => 1]]]);

it('fails closed when setup is disabled or not a boolean', function (mixed $flag): void {
    config(['gate.setup_enabled' => $flag]);
    $this->getJson('/api/admin/v2/gate/setup')->assertStatus(409);
    $this->postJson($this->url, gateSetupBody())->assertStatus(409);
})->with([false, null, 'false', 'true', 1]);

it('rechecks school tenant and active authority on draft reads and saves', function (string $case): void {
    match ($case) {
        'tenant' => DB::table('ec_gate_setup_assignments')->update(['tenant_id' => 999]),
        'school' => DB::table('ec_gate_setup_assignments')->update(['school_id' => 999]),
        'revoked' => DB::table('ec_gate_setup_assignments')->update(['active' => false]),
        'school_inactive' => DB::table('ec_schools')->update(['status' => 'inactive']),
        'tenant_inactive' => DB::table('ec_tenants')->update(['status' => 'inactive']),
        'inactive' => $this->coordinator->forceFill(['is_active' => false])->save(),
        'recovery' => $this->coordinator->forceFill(['test_recovery_only' => true])->save(),
    };
    $this->getJson('/api/admin/v2/gate/setup')->assertForbidden();
    $this->postJson($this->url, gateSetupBody())->assertStatus(in_array($case, ['inactive', 'recovery'], true) ? 403 : 404);
    expect(DB::table('ec_gate_setup_drafts')->count())->toBe(0);
})->with(['tenant', 'school', 'revoked', 'school_inactive', 'tenant_inactive', 'inactive', 'recovery']);

it('rejects unrelated school and resource selections without disclosing their records', function (string $field): void {
    $this->getJson('/api/admin/v2/gate/setup?school_id=999')->assertNotFound();
    $this->postJson($this->url, gateSetupBody([$field => [999]]))->assertUnprocessable()
        ->assertJsonValidationErrors('configuration.'.$field);
    expect(DB::table('ec_gate_setup_drafts')->count())->toBe(0);
})->with(['student_ids', 'device_ids']);

it('rejects a selected record after it leaves the active school scope', function (string $case): void {
    $otherTenant = Tenant::create(['name' => 'SYNTHETIC other', 'slug' => 'setup-other', 'status' => 'active']);
    $otherSchool = School::create(['tenant_id' => $otherTenant->id, 'name' => 'SYNTHETIC other school', 'slug' => 'setup-other-school', 'type' => 'secondary', 'status' => 'active']);
    [$field, $table, $id] = str_starts_with($case, 'student')
        ? ['student_ids', 'ec_students', $this->fixture['student']->id] : ['device_ids', 'ec_biometric_devices', $this->fixture['device']->id];
    DB::table($table)->where('id', $id)->update(str_ends_with($case, 'tenant') ? ['tenant_id' => $otherTenant->id]
        : (str_ends_with($case, 'school') ? ['school_id' => $otherSchool->id] : ['status' => 'inactive']));
    $this->postJson($this->url, gateSetupBody([$field => [$id]]))->assertUnprocessable();
})->with(['student_tenant', 'student_school', 'student_inactive', 'device_tenant', 'device_school', 'device_inactive']);

it('rejects malformed and unbounded draft values', function (array $configuration): void {
    $this->postJson($this->url, gateSetupBody($configuration))->assertUnprocessable();
    expect(DB::table('ec_gate_setup_drafts')->count())->toBe(0);
})->with([
    'guessed activation' => [['enabled' => true]], 'invalid timezone' => [['timezone' => 'Wrong/Zone']],
    'numeric string' => [['repeat_seconds' => '30']], 'float' => [['repeat_seconds' => 1.5]],
    'large bound' => [['repeat_seconds' => 86401]], 'zero delay' => [['repeat_seconds' => 0]],
    'boolean string' => [['early_exit_enabled' => 'false']], 'null enabled' => [['return_enabled' => null]],
    'numeric boolean' => [['late_at_cutoff' => 1]], 'invalid time' => [['arrival_start' => '25:00:00']],
    'reversed windows' => [['arrival_start' => '08:00:00', 'arrival_end' => '07:00:00']],
    'outside cutoff' => [['arrival_start' => '08:00:00', 'late_cutoff' => '07:00:00']],
    'overlap' => [['arrival_end' => '10:00:00', 'departure_start' => '09:00:00']],
    'bad calendar date' => [['closed_dates' => ['2026-02-30']]], 'bad weekday' => [['weekdays' => [0]]],
    'duplicate weekday' => [['weekdays' => [1, 1]]], 'object not list' => [['student_ids' => ['x' => 1]]],
    'string id' => [['student_ids' => ['1']]], 'large pilot' => [['student_ids' => range(1, 201)]],
    'bad reason' => [['correction_reason_codes' => ['Not a code']]],
    'invented role' => [['early_exit_roles' => ['super_admin']]],
    'return order' => [['return_min_seconds' => 60, 'return_max_seconds' => 30]],
    'return reversal' => [['reversal_seconds' => 60, 'return_min_seconds' => 30]],
    'checkout reversal' => [['reversal_seconds' => 60, 'return_checkout_delay_seconds' => 30]],
]);

it('rejects forged top-level authority or publication fields', function (string $field): void {
    $this->postJson($this->url, gateSetupBody() + [$field => true])->assertUnprocessable();
})->with(['activated', 'tenant_id', 'school_id', 'actor_id', 'role', 'permissions', 'confirmed_by']);

it('deduplicates semantic retries and refuses stale writes and retry key reuse', function (): void {
    $body = gateSetupBody(['weekdays' => [5, 1]]);
    $this->postJson($this->url, $body)->assertOk()->assertJsonPath('data.replayed', false);
    $this->postJson($this->url, gateSetupBody(['weekdays' => [1, 5]]))->assertOk()->assertJsonPath('data.replayed', true);
    $this->postJson($this->url, gateSetupBody(['weekdays' => [2]]))->assertStatus(409);
    $this->postJson($this->url, gateSetupBody([], 0, 'SYNTHETIC-stale'))->assertStatus(409);
    $this->postJson($this->url, gateSetupBody([], 1, 'SYNTHETIC-next'))->assertOk();
    $this->postJson($this->url, $body)->assertOk()->assertJsonPath('data.saved_revision', 1)->assertJsonPath('data.current_revision', 2);
    expect(DB::table('ec_gate_setup_revisions')->count())->toBe(2);
    DB::table('ec_gate_setup_assignments')->update(['active' => false]);
    $this->postJson($this->url, $body)->assertNotFound();
});

it('rolls the draft head back if append-only audit storage fails', function (): void {
    DB::unprepared("CREATE TRIGGER setup_audit_fail BEFORE INSERT ON ec_gate_setup_revisions BEGIN SELECT RAISE(ABORT, 'SYNTHETIC failure'); END");
    $this->postJson($this->url, gateSetupBody())->assertStatus(500);
    expect(DB::table('ec_gate_setup_drafts')->count())->toBe(0);
    expect(DB::table('ec_gate_setup_revisions')->count())->toBe(0);
});

it('keeps literal student searches scoped and read-only', function (string $query, int $count): void {
    $this->getJson('/api/admin/v2/gate/setup?q='.urlencode($query))->assertOk()->assertJsonCount($count, 'data.students.data');
    expect(DB::table('ec_gate_setup_drafts')->count())->toBe(0);
})->with([['Synthetic', 1], ['%', 0], ['_', 0], ['!', 0]]);

it('does not treat complete fields as an active policy or a staff grant', function (): void {
    $c = array_replace(SetupRules::blank(), ['roster_mode' => 'selected', 'timezone' => 'Africa/Douala', 'weekdays' => [1],
        'student_ids' => [$this->fixture['student']->id], 'device_ids' => [$this->fixture['device']->id],
        'arrival_start' => '07:00:00', 'arrival_end' => '08:00:00', 'late_cutoff' => '07:30:00', 'late_at_cutoff' => false,
        'departure_start' => '15:00:00', 'departure_end' => '16:00:00', 'repeat_seconds' => 5, 'reversal_seconds' => 30,
        'future_tolerance_seconds' => 0, 'correction_reason_codes' => ['synthetic_mistake']]);
    $before = gateSetupUntouched();
    $this->postJson($this->url, gateSetupBody($c))->assertOk()->assertJsonPath('data.activated', false);
    $this->getJson('/api/admin/v2/gate/setup')->assertOk()->assertJsonPath('data.readiness.configuration_complete', true)
        ->assertJsonPath('data.readiness.activation_available', false)->assertJsonPath('data.readiness.checks.3.ok', false);
    expect(gateSetupUntouched())->toBe($before);
    Http::assertNothingSent();
});

it('marks narrower early-exit roles and incomplete return rules as preparation blockers', function (): void {
    $this->postJson($this->url, gateSetupBody(['early_exit_enabled' => true, 'early_exit_roles' => ['discipline_master'], 'return_enabled' => true]))->assertOk();
    $missing = $this->getJson('/api/admin/v2/gate/setup')->assertOk()->json('data.readiness.missing');
    expect(collect($missing)->where('field', 'early_exit_roles')->pluck('message')->implode(' '))->toContain('separate runtime change');
    expect(collect($missing)->pluck('field')->all())->toContain('return_min_seconds', 'early_exit_required_checks');
});

it('routes an explicitly granted restricted coordinator to setup without granting other admin access', function (bool $inertia): void {
    $response = $this->withHeaders($inertia ? ['X-Inertia' => 'true'] : [])->post('/admin/login', [
        'email' => $this->coordinator->email, 'password' => 'SYNTHETIC-password']);
    $inertia ? $response->assertStatus(409)->assertHeader('X-Inertia-Location', '/admin/gate/setup') : $response->assertRedirect('/admin/gate/setup');
    $this->getJson('/admin/students')->assertForbidden();
    $this->getJson('/api/admin/v2/dashboard')->assertForbidden();
    config(['gate.enabled' => true, 'gate.operator_enabled' => true]);
    $this->getJson('/api/admin/v2/gate')->assertForbidden();
})->with([false, true]);

it('renders the isolated setup entry with web-only csrf and no automatic draft save', function (): void {
    $this->withoutVite();
    $response = $this->actingAs($this->coordinator, 'admin')->get('/admin/gate/setup')->assertOk();
    $response->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->component('Admin/Gate/Setup')->has('csrfToken'));
    expect(DB::table('ec_gate_setup_drafts')->count())->toBe(0);
});

it('shows explicit operator and setup links only within the same granted school', function (): void {
    config(['gate.enabled' => true, 'gate.operator_enabled' => true]);
    DB::table('ec_gate_operator_assignments')->insert(['tenant_id' => $this->fixture['tenant']->id,
        'school_id' => $this->fixture['school']->id, 'admin_id' => $this->coordinator->id, 'role' => 'discipline_master', 'active' => true]);
    $this->getJson('/api/admin/v2/gate/setup')->assertOk()->assertJsonPath('data.gateUrl', '/admin/gate?school_id='.$this->fixture['school']->id);
    $this->getJson('/api/admin/v2/gate')->assertOk()->assertJsonPath('data.setupUrl', '/admin/gate/setup?school_id='.$this->fixture['school']->id);
    DB::table('ec_gate_setup_assignments')->update(['active' => false]);
    $this->getJson('/api/admin/v2/gate')->assertOk()->assertJsonPath('data.setupUrl', null);
});

it('does not accept parent accounts or non-json saves', function (): void {
    $this->actingAs($this->coordinator, 'admin')->post('/admin/gate/setup/schools/'.$this->fixture['school']->id, gateSetupBody())->assertStatus(415);
    $parent = ParentAccount::create(['phone' => 'SYNTHETIC-SETUP-PARENT', 'first_name' => 'Synthetic', 'last_name' => 'Parent', 'status' => 'active']);
    Sanctum::actingAs($parent, ['*']);
    $this->getJson('/api/admin/v2/gate/setup')->assertForbidden();
});

it('defaults new school drafts to automatic eligibility without a photo device or individual selection', function (): void {
    $before = gateSetupUntouched();
    $reply = $this->getJson('/api/admin/v2/gate/setup')->assertOk()
        ->assertJsonPath('data.draft.configuration.roster_mode', 'all_active')
        ->assertJsonPath('data.draft.configuration.student_ids', [])
        ->assertJsonPath('data.rosterEligibility.eligible_total', 1)
        ->assertJsonPath('data.rosterEligibility.includes_future_students', true)
        ->assertJsonPath('data.readiness.checks.1.ok', true)
        ->assertJsonPath('data.readiness.activation_available', false);
    expect(collect($reply->json('data.readiness.missing'))->pluck('field')->all())->not->toContain('student_ids');
    expect(gateSetupUntouched())->toBe($before);
    Http::assertNothingSent();
});

it('keeps the automatic roster dynamic beyond the pilot limit without freezing IDs or publishing devices', function (): void {
    $rows = [];
    for ($i = 0; $i < 205; $i++) {
        $rows[] = ['tenant_id' => $this->fixture['tenant']->id, 'school_id' => $this->fixture['school']->id,
            'student_number' => 'SYNTHETIC-AUTO-'.$i, 'first_name' => 'Synthetic', 'last_name' => 'Auto '.$i,
            'status' => 'enrolled', 'device_sync_enabled' => true, 'source_system' => 'edu_admin', 'source_id' => (string) (9000 + $i)];
    }
    DB::table('ec_students')->insert($rows);
    $before = gateSetupUntouched();
    $this->postJson($this->url, gateSetupBody(['roster_mode' => 'all_active']))->assertOk()->assertJsonPath('data.activated', false);
    $this->getJson('/api/admin/v2/gate/setup')->assertOk()->assertJsonPath('data.rosterEligibility.eligible_total', 206)
        ->assertJsonPath('data.rosterEligibility.configured_total', 206)->assertJsonCount(50, 'data.students.data')
        ->assertJsonPath('data.draft.configuration.student_ids', []);
    expect(gateSetupUntouched())->toBe($before);
    $saved = DB::table('ec_gate_setup_drafts')->sole();
    Student::create(['tenant_id' => $this->fixture['tenant']->id, 'school_id' => $this->fixture['school']->id,
        'student_number' => 'SYNTHETIC-AUTO-FUTURE', 'first_name' => 'Synthetic', 'last_name' => 'Future', 'status' => 'active',
        'device_sync_enabled' => true, 'source_system' => 'edu_admin', 'source_id' => '99000']);
    $this->getJson('/api/admin/v2/gate/setup')->assertOk()->assertJsonPath('data.rosterEligibility.configured_total', 207)
        ->assertJsonPath('data.draft.revision', 1)->assertJsonPath('data.draft.configuration.student_ids', []);
    expect(DB::table('ec_gate_setup_drafts')->sole())->toEqual($saved);
    expect(DB::table('ec_gate_setup_revisions')->count())->toBe(1);
    Http::assertNothingSent();
});

it('never widens a legacy pilot during reads or replay and requires a new explicit draft revision for automatic mode', function (array $ids): void {
    $c = SetupRules::blank();
    unset($c['roster_mode']);
    $c['student_ids'] = $ids === [] ? [] : [$this->fixture['student']->id];
    $bytes = json_encode($c, JSON_THROW_ON_ERROR);
    $id = DB::table('ec_gate_setup_drafts')->insertGetId(['tenant_id' => $this->fixture['tenant']->id, 'school_id' => $this->fixture['school']->id,
        'revision' => 1, 'configuration' => $bytes, 'updated_by' => $this->coordinator->id, 'updated_at' => now()]);
    DB::table('ec_gate_setup_revisions')->insert(['draft_id' => $id, 'revision' => 1, 'basis_revision' => 0, 'actor_id' => $this->coordinator->id,
        'request_id' => hash('sha256', 'gate-setup|'.$this->fixture['tenant']->id.'|'.$this->fixture['school']->id.'|'.$this->coordinator->id.'|SYNTHETIC-legacy-auto'),
        'request_hash' => hash('sha256', json_encode(['expected_revision' => 0, 'configuration' => $c], JSON_THROW_ON_ERROR)),
        'configuration' => $bytes, 'created_at' => now()]);
    $head = DB::table('ec_gate_setup_drafts')->sole();
    $history = DB::table('ec_gate_setup_revisions')->get();
    $this->getJson('/api/admin/v2/gate/setup')->assertOk()->assertJsonPath('data.draft.configuration.roster_mode', 'selected')
        ->assertJsonPath('data.draft.configuration.student_ids', $c['student_ids'])->assertJsonPath('data.rosterEligibility.includes_future_students', false);
    $this->postJson($this->url, gateSetupBody($c, 0, 'SYNTHETIC-legacy-auto'))->assertOk()->assertJsonPath('data.replayed', true);
    $this->postJson($this->url, gateSetupBody(['roster_mode' => 'all_active'], 0, 'SYNTHETIC-legacy-auto'))->assertStatus(409);
    expect(DB::table('ec_gate_setup_drafts')->sole())->toEqual($head);
    expect(DB::table('ec_gate_setup_revisions')->get())->toEqual($history);
    $this->postJson($this->url, gateSetupBody(['roster_mode' => 'all_active'], 1, 'SYNTHETIC-explicit-auto'))->assertOk();
    $this->getJson('/api/admin/v2/gate/setup')->assertOk()->assertJsonPath('data.draft.configuration.roster_mode', 'all_active')
        ->assertJsonPath('data.draft.configuration.student_ids', [])->assertJsonPath('data.readiness.activation_available', false);
})->with(['empty legacy pilot' => [[]], 'selected legacy pilot' => [[1]]]);

it('rejects an ambiguous automatic roster mode instead of interpreting a list or null as consent', function (array $c): void {
    $this->postJson($this->url, gateSetupBody($c))->assertUnprocessable();
    expect(DB::table('ec_gate_setup_drafts')->count())->toBe(0);
})->with([
    'null' => [['roster_mode' => null]], 'boolean' => [['roster_mode' => true]], 'unknown' => [['roster_mode' => 'all_complexes']],
    'automatic plus IDs' => [['roster_mode' => 'all_active', 'student_ids' => [1]]],
]);

it('excludes students who leave eligibility and never broadens the automatic roster across school or tenant boundaries', function (): void {
    $other = Tenant::create(['name' => 'Synthetic other auto complex', 'slug' => 'other-auto', 'status' => 'active']);
    $school = School::create(['tenant_id' => $other->id, 'name' => 'Synthetic other auto school', 'slug' => 'other-auto', 'type' => 'secondary', 'status' => 'active']);
    Student::create(['tenant_id' => $other->id, 'school_id' => $school->id, 'student_number' => 'SYNTHETIC-FOREIGN-AUTO',
        'first_name' => 'Synthetic', 'last_name' => 'Foreign', 'status' => 'enrolled', 'source_system' => 'edu_admin', 'source_id' => 'foreign-auto']);
    $this->getJson('/api/admin/v2/gate/setup')->assertOk()->assertJsonPath('data.rosterEligibility.eligible_total', 1)->assertJsonCount(1, 'data.students.data');
    $this->fixture['student']->update(['status' => 'suspended']);
    $this->getJson('/api/admin/v2/gate/setup')->assertOk()->assertJsonPath('data.rosterEligibility.eligible_total', 0)->assertJsonCount(0, 'data.students.data');
    $this->fixture['student']->update(['status' => 'enrolled', 'device_sync_enabled' => false]);
    $this->getJson('/api/admin/v2/gate/setup')->assertOk()->assertJsonPath('data.rosterEligibility.eligible_total', 0);
    $this->fixture['student']->update(['device_sync_enabled' => true]);
    $this->getJson('/api/admin/v2/gate/setup')->assertOk()->assertJsonPath('data.rosterEligibility.eligible_total', 1);
    expect(DB::table('ec_gate_setup_drafts')->count())->toBe(0);
    Http::assertNothingSent();
});
