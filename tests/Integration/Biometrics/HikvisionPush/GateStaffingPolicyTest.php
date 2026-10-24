<?php

use App\Models\AdminUser;
use App\Models\V2\School;
use App\Models\V2\Tenant;
use App\Services\Biometrics\Gate\DayProjector;
use App\Services\Biometrics\Gate\OperatorAccess;
use App\Services\Biometrics\Gate\StaffingPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Biometrics\GateFixture;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->f = GateFixture::boot();
    foreach (['2026_10_20_000023_add_gate_only_account_restriction.php', '2026_10_21_000024_create_gate_setup_drafts.php',
        '2026_10_21_000025_create_device_registry.php', '2026_10_23_000028_create_gate_account_invitations.php'] as $migration) {
        (require base_path('database/migrations/'.$migration))->up();
    }
    Carbon::setTestNow('2026-10-20T09:00:00Z');
    $this->schools = [$this->f['school']];
    foreach (['primary', 'nursery'] as $type) {
        $this->schools[] = School::create(['tenant_id' => $this->f['tenant']->id, 'name' => 'SYNTHETIC '.$type, 'slug' => $type, 'type' => $type, 'status' => 'active']);
    }
    $other = Tenant::create(['name' => 'SYNTHETIC other complex', 'slug' => 'other-staff', 'status' => 'active']);
    $this->otherSchool = School::create(['tenant_id' => $other->id, 'name' => 'SYNTHETIC other school', 'slug' => 'other-staff', 'type' => 'secondary', 'status' => 'active']);
    config(['gate.operator_enabled' => true, 'gate.secretary_complex_ids' => [$this->f['tenant']->id],
        'gate.school_ids' => array_merge(array_map(fn ($s) => $s->id, $this->schools), [$this->otherSchool->id]),
        'app.url' => 'https://staff.test', 'gate.account_invites_enabled' => true,
        'gate.setup_enabled' => true, 'gate.setup_school_ids' => [$this->f['school']->id],
        'device_registry.enabled' => true, 'device_registry.tenant_ids' => [$this->f['tenant']->id], 'device_registry.school_ids' => [$this->f['school']->id]]);
    Http::preventStrayRequests();
    $this->staff = AdminUser::create(['name' => 'SYNTHETIC secretary', 'email' => 'secretary@staff.test', 'password' => 'SYNTHETIC-password', 'role' => 'school_admin', 'is_active' => true]);
    $this->staff->forceFill(['gate_only' => true])->save();
    foreach ($this->schools as $school) {
        DB::table('ec_gate_operator_assignments')->insert(['tenant_id' => $school->tenant_id, 'school_id' => $school->id,
            'admin_id' => $this->staff->id, 'role' => 'secretary', 'active' => true]);
    }
    $this->f['policy']['operator_rules'] = ['correction' => ['reason_codes' => ['synthetic_mistake']],
        'early_exit' => ['reason_codes' => ['synthetic_release'], 'validity_seconds' => 120, 'required_checks' => ['synthetic_identity']]];
    DB::table('ec_gate_policies')->update(['snapshot' => json_encode($this->f['policy'])]);
    GateFixture::scan($this->f, 1, '07:30:00');
    app(DayProjector::class)->work();
    $this->day = DB::table('ec_gate_days')->sole();
    $this->actionUrl = '/api/admin/v2/gate/days/'.$this->day->id.'/actions';
    Sanctum::actingAs($this->staff, ['admin:*']);
});

afterEach(fn () => Carbon::setTestNow());

it('scopes a secretary across all participating schools but not another complex', function (): void {
    DB::table('ec_gate_operator_assignments')->insert(['tenant_id' => $this->otherSchool->tenant_id, 'school_id' => $this->otherSchool->id,
        'admin_id' => $this->staff->id, 'role' => 'secretary', 'active' => true]);
    $response = $this->getJson('/api/admin/v2/gate')->assertOk()->assertJsonCount(3, 'data.schools')->assertJsonPath('data.roles', ['secretary']);
    $this->getJson('/api/admin/v2/gate?school_id='.$this->otherSchool->id)->assertNotFound();
    $this->getJson('/api/admin/v2/gate/setup')->assertForbidden();
    $this->getJson('/api/admin/v2/gate/devices')->assertForbidden();
    expect(app(OperatorAccess::class)->assignments($this->staff)->count())->toBe(3);
});

it('restricts a DM to secondary even with accidentally broader assignment rows', function (): void {
    DB::table('ec_gate_operator_assignments')->update(['role' => 'discipline_master']);
    $this->getJson('/api/admin/v2/gate')->assertOk()->assertJsonCount(1, 'data.schools')->assertJsonPath('data.roles', ['discipline_master']);
    foreach (array_slice($this->schools, 1) as $school) {
        $this->getJson('/api/admin/v2/gate?school_id='.$school->id)->assertNotFound();
    }
});

it('requires a secretary for every school and a DM only for secondary', function (string $type, array $roles): void {
    $school = collect($this->schools)->firstWhere('type', $type);
    $requirement = StaffingPolicy::requirement($school->tenant_id, $school->id);
    expect($requirement['roles'])->toBe($roles);
    expect(StaffingPolicy::satisfied($requirement, ['secretary']))->toBe($type !== 'secondary');
})->with([['primary', ['secretary']], ['nursery', ['secretary']], ['secondary', ['secretary', 'discipline_master']]]);

it('preserves legacy staffing unless this complex explicitly opts in', function (): void {
    config(['gate.secretary_complex_ids' => []]);
    $this->getJson('/api/admin/v2/gate')->assertForbidden();
    foreach ($this->schools as $school) {
        expect(StaffingPolicy::requirement($school->tenant_id, $school->id)['roles'])->toBe(['guard', 'discipline_master']);
    }
});

it('fails closed for malformed complex scope', function ($ids): void {
    config(['gate.secretary_complex_ids' => $ids]);
    $this->getJson('/api/admin/v2/gate')->assertForbidden();
    expect(StaffingPolicy::satisfied(StaffingPolicy::requirement($this->f['tenant']->id, $this->f['school']->id), ['guard', 'secretary', 'discipline_master']))->toBeFalse();
})->with(['not-array' => ['1'], 'zero' => [[0]], 'duplicate' => [[1, 1]], 'string-id' => [['1']], 'not-list' => [['tenant' => 1]]]);

it('revokes access when an assignment school or actor becomes inactive', function (string $case): void {
    match ($case) {
        'assignment' => DB::table('ec_gate_operator_assignments')->update(['active' => false]),
        'school' => DB::table('ec_schools')->where('tenant_id', $this->f['tenant']->id)->update(['status' => 'inactive']),
        'actor' => $this->staff->update(['is_active' => false]),
    };
    $this->getJson('/api/admin/v2/gate')->assertForbidden();
})->with(['assignment', 'school', 'actor']);

it('allows correction requests but denies secretary disciplinary corrections and approvals', function (): void {
    $movement = DB::table('ec_gate_movements')->sole();
    $body = ['operation' => 'request_correction', 'idempotency_key' => 'SYNTHETIC-secretary-request', 'expected_revision' => (int) $this->day->revision,
        'reason_code' => 'synthetic_mistake', 'movement_id' => $movement->event_id];
    $this->postJson($this->actionUrl, array_replace($body, ['operation' => 'correct']))->assertForbidden();
    $this->postJson($this->actionUrl, $body)->assertSuccessful()->assertJsonPath('data.status', 'pending');
    $action = DB::table('ec_gate_actions')->sole();
    expect($action->role)->toBe('secretary');
    unset($body['movement_id']);
    $this->postJson($this->actionUrl, array_replace($body, ['operation' => 'approve_correction', 'action_id' => $action->id]))->assertForbidden();
});

it('allows a secretary policy-checked early exit with the real role retained in the audit', function (): void {
    $body = ['operation' => 'permit_exit', 'idempotency_key' => 'SYNTHETIC-secretary-exit', 'expected_revision' => (int) $this->day->revision,
        'reason_code' => 'synthetic_release', 'checks' => ['synthetic_identity'], 'return_allowed' => false];
    $this->postJson($this->actionUrl, $body)->assertSuccessful();
    expect(DB::table('ec_gate_actions')->sole()->role)->toBe('secretary');
    Carbon::setTestNow('2026-10-20T09:00:05Z');
    GateFixture::scan($this->f, 2, '10:00:05');
    app(DayProjector::class)->work();
    expect(DB::table('ec_gate_days')->sole()->state)->toBe('outside');
});
