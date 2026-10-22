<?php

use App\Models\V2\BiometricDevice;
use App\Models\V2\School;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use App\Services\Biometrics\Gate\DayProjector;
use App\Services\Biometrics\Hikvision\PushObservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();
    (require base_path('database/migrations/2026_10_10_000020_create_hikvision_push_inbox.php'))->up();
    (require base_path('database/migrations/2026_10_20_000022_create_gate_workflow.php'))->up();
    Carbon::setTestNow('2026-10-20T18:00:00Z');
    Http::preventStrayRequests();
    $tenant = Tenant::create(['name' => 'SYNTHETIC gate tenant', 'slug' => 'gate-projector', 'status' => 'active']);
    $school = School::create(['tenant_id' => $tenant->id, 'name' => 'SYNTHETIC gate school', 'slug' => 'gate', 'type' => 'secondary', 'status' => 'active']);
    $this->gateStudent = Student::create(['tenant_id' => $tenant->id, 'school_id' => $school->id,
        'student_number' => 'SYNTHETIC-GATE', 'first_name' => 'Synthetic', 'last_name' => 'Adult', 'status' => 'enrolled']);
    $this->gateDevice = BiometricDevice::create(['tenant_id' => $tenant->id, 'school_id' => $school->id,
        'name' => 'SYNTHETIC gate unit', 'device_uid' => 'SYNTHETIC-GATE-UNIT', 'device_type' => 'hikvision', 'status' => 'active']);
    $this->gatePush = (object) ['id' => 1, 'device_id' => $this->gateDevice->id, 'tenant_id' => $tenant->id,
        'school_id' => $school->id, 'deployment_epoch' => '71b8a962-1020-4026-a000-000000000004'];
    DB::table('ec_hikvision_push_devices')->insert(['id' => 1, 'device_id' => $this->gateDevice->id, 'tenant_id' => $tenant->id,
        'school_id' => $school->id, 'serial' => 'SYNTHETIC-GATE-UNIT', 'deployment_epoch' => $this->gatePush->deployment_epoch,
        'model' => 'SYNTHETIC', 'firmware' => 'SYNTHETIC', 'auth_profile' => 'sha256', 'username' => 'synthetic',
        'credential_encrypted' => 'unused', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('ec_device_personnel')->insert(['tenant_id' => $tenant->id, 'school_id' => $school->id,
        'device_id' => $this->gateDevice->id, 'student_id' => $this->gateStudent->id, 'person_identifier' => 'SYNTHETIC1',
        'sync_status' => 'acknowledged', 'created_at' => now(), 'updated_at' => now()]);
    $this->gatePolicy = ['tenant_id' => $tenant->id, 'school_id' => $school->id, 'version' => 'SYNTHETIC-v1',
        'day' => '2026-10-20', 'timezone' => 'Africa/Douala', 'closed' => false, 'late_at_cutoff' => false,
        'arrival_start' => '07:00:00', 'arrival_end' => '08:00:00', 'late_cutoff' => '08:00:00',
        'departure_start' => '15:00:00', 'departure_end' => '16:00:00', 'repeat_seconds' => 5,
        'reversal_seconds' => 30, 'future_tolerance_seconds' => 300,
        'return_rules' => ['min_seconds' => 60, 'max_seconds' => 3600, 'checkout_delay_seconds' => 60]];
    $this->gatePolicyId = hash('sha256', 'SYNTHETIC gate policy');
    DB::table('ec_gate_policies')->insert(['id' => $this->gatePolicyId, 'tenant_id' => $tenant->id, 'school_id' => $school->id,
        'day' => '2026-10-20', 'timezone' => 'Africa/Douala', 'version' => 'SYNTHETIC-v1',
        'snapshot' => json_encode($this->gatePolicy), 'student_ids' => json_encode([$this->gateStudent->id]),
        'device_ids' => json_encode([$this->gateDevice->id]), 'effective_from' => '2026-10-20 00:00:00',
        'enabled' => true, 'confirmed_by' => 1, 'created_at' => now()]);
    config(['gate.enabled' => true, 'gate.school_ids' => [$school->id],
        'gate.mirror_delivery_enabled' => false, 'gate.parent_delivery_enabled' => false]);
});

afterEach(fn () => Carbon::setTestNow());

function projectGateScan(object $test, int $serial, string $time, array $overrides = []): int
{
    $normal = PushObservation::normalize($test->gatePush, ['majorEventType' => 5, 'subEventType' => 75,
        'serialNo' => $serial, 'employeeNoString' => 'SYNTHETIC1', 'time' => '2026-10-20T'.$time.'+01:00']);

    return DB::table('ec_hikvision_observations')->insertGetId(array_replace([
        'push_device_id' => 1, 'tenant_id' => $test->gateStudent->tenant_id, 'school_id' => $test->gateStudent->school_id,
        'event_key' => $normal['event_key'], 'fingerprint' => $normal['fingerprint'], 'metadata' => json_encode($normal['metadata']),
        'status' => 'recognition_candidate', 'received_at' => '2026-10-20 17:00:00'], $overrides));
}

it('durably projects accepted metadata and atomically records intents without legacy attendance or delivery', function (): void {
    projectGateScan($this, 1, '07:30:00');
    projectGateScan($this, 2, '07:30:10');
    projectGateScan($this, 3, '15:00:00');
    expect(app(DayProjector::class)->work())->toBe(3);
    expect(DB::table('ec_gate_days')->sole()->state)->toBe('outside');
    expect(DB::table('ec_gate_movements')->pluck('movement')->all())->toBe(['check_in', 'check_out']);
    expect(DB::table('ec_gate_movement_revisions')->count())->toBe(2);
    expect(DB::table('ec_gate_outbox')->count())->toBe(4);
    expect(DB::table('ec_attendance_events')->count())->toBe(0);
    expect(DB::table('ec_mobile_notifications')->count())->toBe(0);
    expect(DB::table('ec_hikvision_observations')->distinct()->pluck('delivery_state')->all())->toBe(['held_hardware_acceptance']);
    Http::assertNothingSent();
});

it('restarts and replays idempotently without duplicating ledger revisions or outbox intents', function (): void {
    projectGateScan($this, 1, '07:30:00');
    app(DayProjector::class)->work();
    expect((new DayProjector)->work())->toBe(0);
    DB::transaction(fn () => (new DayProjector)->rebuild((int) DB::table('ec_gate_days')->value('id')));
    expect(DB::table('ec_gate_days')->value('revision'))->toBe(1);
    expect(DB::table('ec_gate_movement_revisions')->count())->toBe(1);
    expect(DB::table('ec_gate_outbox')->count())->toBe(2);
});

it('reconciles an earlier delayed scan with append-only withdrawal instead of deleting history', function (): void {
    $later = projectGateScan($this, 2, '07:40:00');
    app(DayProjector::class)->work();
    $earlier = projectGateScan($this, 1, '07:30:00');
    app(DayProjector::class)->work();
    $old = DB::table('ec_gate_movements')->where('observation_id', $later)->sole();
    expect($old->state)->toBe('withdrawn');
    expect($old->revision)->toBe(2);
    expect(json_decode($old->payload, true)['decision_source'])->toBe('reconciliation');
    expect(DB::table('ec_gate_movements')->where('observation_id', $earlier)->value('state'))->toBe('active');
    expect(DB::table('ec_gate_movement_revisions')->count())->toBe(3);
    expect(DB::table('ec_gate_outbox')->count())->toBe(6);
    expect(DB::table('ec_hikvision_observations')->count())->toBe(2);
});

it('withdraws a previously published interpretation when a later receipt reveals a content conflict', function (): void {
    $id = projectGateScan($this, 1, '07:30:00');
    app(DayProjector::class)->work();
    DB::table('ec_hikvision_observations')->where('id', $id)->update(['status' => 'held_content_conflict']);
    expect(app(DayProjector::class)->work())->toBe(1);
    expect(DB::table('ec_gate_days')->value('state'))->toBe('unknown');
    expect(DB::table('ec_gate_movements')->sole()->state)->toBe('withdrawn');
    expect(DB::table('ec_gate_movement_revisions')->count())->toBe(2);
    expect(app(DayProjector::class)->work())->toBe(0);
});

it('keeps scope and policy snapshots frozen across roster device and future configuration changes', function (): void {
    $id = projectGateScan($this, 1, '07:30:00');
    app(DayProjector::class)->work();
    $this->gateStudent->update(['status' => 'suspended']);
    $this->gateDevice->update(['device_uid' => 'CHANGED-DEVICE-NAME']);
    DB::table('ec_device_personnel')->delete();
    DB::table('ec_gate_policies')->where('id', $this->gatePolicyId)->update(['snapshot' => json_encode(array_replace($this->gatePolicy, ['closed' => true]))]);
    DB::transaction(fn () => app(DayProjector::class)->rebuild((int) DB::table('ec_gate_days')->value('id')));
    expect(DB::table('ec_gate_days')->value('revision'))->toBe(1);
    $event = json_decode(DB::table('ec_gate_movements')->value('payload'), true);
    expect($event['student_id'])->toBe($this->gateStudent->id);
    expect($event['device_uid'])->toBe('SYNTHETIC-GATE-UNIT');
    expect(DB::table('ec_gate_observation_links')->where('observation_id', $id)->value('policy_id'))->toBe($this->gatePolicyId);
});

it('does not retroactively backfill pre-activation hardware scans or let them starve new receipts', function (): void {
    DB::table('ec_gate_policies')->update(['effective_from' => '2026-10-20 07:00:00']);
    $old = projectGateScan($this, 1, '07:30:00'); // 06:30 UTC, before activation.
    app(DayProjector::class)->work(1);
    expect(DB::table('ec_gate_observation_links')->where('observation_id', $old)->value('error_code'))->toBe('policy_not_configured');
    projectGateScan($this, 2, '08:30:00');
    expect(app(DayProjector::class)->work(1))->toBe(1);
    expect(DB::table('ec_gate_movements')->count())->toBe(1);
    expect(app(DayProjector::class)->work())->toBe(0);
});

it('denies disabled or malformed processing scope', function (mixed $scope): void {
    projectGateScan($this, 1, '07:30:00');
    config(['gate.school_ids' => $scope]);
    expect(app(DayProjector::class)->work())->toBe(0);
    expect(DB::table('ec_gate_observation_links')->count())->toBe(0);
})->with(['empty' => [[]], 'zero' => [[0]], 'string_id' => [['1']], 'null' => [null], 'string' => ['1']]);

it('leaves the receiver inbox entirely held when gate processing is disabled', function (): void {
    projectGateScan($this, 1, '07:30:00');
    config(['gate.enabled' => false]);
    expect(app(DayProjector::class)->work())->toBe(0);
    expect(DB::table('ec_gate_days')->count())->toBe(0);
});

it('fails closed for overlapping or empty explicit school policy selections', function (string $case): void {
    if ($case === 'overlap') {
        $p = (array) DB::table('ec_gate_policies')->sole();
        $p['id'] = hash('sha256', 'SYNTHETIC conflicting policy');
        $p['version'] = 'SYNTHETIC-v2';
        $p['snapshot'] = json_encode(array_replace($this->gatePolicy, ['version' => $p['version']]));
        DB::table('ec_gate_policies')->insert($p);
    } else {
        DB::table('ec_gate_policies')->update(['student_ids' => '[]']);
    }
    projectGateScan($this, 1, '07:30:00');
    app(DayProjector::class)->work();
    expect(DB::table('ec_gate_observation_links')->value('error_code'))->toBe($case === 'overlap' ? 'policy_overlap' : 'policy_not_configured');
    expect(DB::table('ec_gate_movements')->count())->toBe(0);
})->with(['overlap', 'empty']);

it('does not project failed recognition or borrow an unacknowledged mapping', function (string $case): void {
    $overrides = $case === 'failed' ? ['status' => 'held_authentication_failed'] : [];
    if ($case === 'mapping') {
        DB::table('ec_device_personnel')->update(['sync_status' => 'pending']);
    }
    projectGateScan($this, 1, '07:30:00', $overrides);
    app(DayProjector::class)->work();
    expect(DB::table('ec_gate_movements')->count())->toBe(0);
    expect(DB::table('ec_gate_outbox')->count())->toBe(0);
})->with(['failed', 'mapping']);

it('rolls back the ledger and intents together if durable outbox storage fails', function (): void {
    projectGateScan($this, 1, '07:30:00');
    DB::statement("CREATE TRIGGER synthetic_gate_outbox_failure BEFORE INSERT ON ec_gate_outbox BEGIN SELECT RAISE(ABORT, 'synthetic storage failure'); END");
    try {
        app(DayProjector::class)->work();
        $this->fail('The simulated durable storage failure must propagate.');
    } catch (\Illuminate\Database\QueryException) {
        expect(DB::table('ec_gate_movements')->count())->toBe(0);
        expect(DB::table('ec_gate_movement_revisions')->count())->toBe(0);
        expect(DB::table('ec_gate_observation_links')->count())->toBe(0);
        expect(DB::table('ec_gate_outbox')->count())->toBe(0);
        expect(DB::table('ec_gate_days')->sole()->revision)->toBe(0);
        expect(DB::table('ec_hikvision_observations')->sole()->delivery_state)->toBe('held_hardware_acceptance');
    }
});
