<?php

use App\Models\AdminUser;
use App\Models\V2\ParentAccount;
use App\Services\Biometrics\Gate\DayProjector;
use App\Services\Biometrics\Gate\OutboxDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Biometrics\GateFixture;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->fixture = GateFixture::boot();
    (require base_path('database/migrations/2026_10_20_000023_add_gate_only_account_restriction.php'))->up();
    Carbon::setTestNow('2026-10-20T09:00:00Z');
    config(['gate.operator_enabled' => true]);
    Http::preventStrayRequests();
    $this->fixture['policy']['operator_rules'] = [
        'correction' => ['reason_codes' => ['synthetic_mistake']],
        'resolution' => ['reason_codes' => ['synthetic_direction'], 'max_age_seconds' => 120],
        'early_exit' => ['reason_codes' => ['synthetic_release'], 'validity_seconds' => 120,
            'required_checks' => ['synthetic_identity', 'synthetic_release_check']],
    ];
    DB::table('ec_gate_policies')->update(['snapshot' => json_encode($this->fixture['policy'])]);
    GateFixture::parent($this->fixture);
    GateFixture::scan($this->fixture, 1, '07:30:00');
    app(DayProjector::class)->work();
    $this->day = DB::table('ec_gate_days')->sole();
    $this->movement = DB::table('ec_gate_movements')->sole();
    $this->operator = gateOperator($this->fixture, 'guard');
    Sanctum::actingAs($this->operator, ['admin:*']);
});

afterEach(fn () => Carbon::setTestNow());

function gateOperator(array $fixture, string $role, bool $only = true): AdminUser
{
    $admin = AdminUser::create(['name' => 'SYNTHETIC '.$role, 'email' => $role.'-'.bin2hex(random_bytes(4)).'@gate.test',
        'password' => 'SYNTHETIC-password', 'role' => 'school_admin', 'is_active' => true]);
    $admin->forceFill(['gate_only' => $only])->save();
    DB::table('ec_gate_operator_assignments')->insert(['tenant_id' => $fixture['tenant']->id, 'school_id' => $fixture['school']->id,
        'admin_id' => $admin->id, 'role' => $role, 'active' => true]);

    return $admin;
}

function gateActionBody(object $test, string $operation, array $override = []): array
{
    $reason = match ($operation) {
        'permit_exit', 'cancel_exit' => 'synthetic_release',
        'resolve' => 'synthetic_direction',
        default => 'synthetic_mistake',
    };

    return array_replace(['operation' => $operation, 'idempotency_key' => 'SYNTHETIC-request-1',
        'expected_revision' => (int) DB::table('ec_gate_days')->where('id', $test->day->id)->value('revision'),
        'reason_code' => $reason, 'note' => 'SYNTHETIC audit note'], $override);
}

function gateActionUrl(object $test): string
{
    return '/api/admin/v2/gate/days/'.$test->day->id.'/actions';
}

it('reads only assigned school days without enrollment pictures or raw device payloads', function (): void {
    $this->getJson('/api/admin/v2/gate')->assertOk()->assertJsonCount(1, 'data.days.data');
    $response = $this->getJson('/api/admin/v2/gate/days/'.$this->day->id)->assertOk()
        ->assertJsonPath('data.roles', ['guard'])->assertJsonPath('data.detail.state', 'inside');
    foreach (['metadata', 'fingerprint', 'credential_encrypted', 'photo_encrypted', 'request_hash'] as $private) {
        expect($response->getContent())->not->toContain('"'.$private.'"');
    }
    Http::assertNothingSent();
});

it('does not grant gate authority from a generic super admin role', function (): void {
    $admin = AdminUser::create(['name' => 'SYNTHETIC super', 'email' => 'super@gate.test', 'password' => 'SYNTHETIC', 'role' => 'super_admin']);
    Sanctum::actingAs($admin, ['admin:*']);
    $this->getJson('/api/admin/v2/gate')->assertForbidden();
    $this->getJson('/api/admin/v2/gate/days/'.$this->day->id)->assertNotFound();
    $this->postJson(gateActionUrl($this), gateActionBody($this, 'request_correction', ['movement_id' => $this->movement->event_id]))->assertNotFound();
});

it('rejects wrong tenant school revoked and inactive gate authority', function (string $case): void {
    if ($case === 'school') {
        DB::table('ec_gate_operator_assignments')->update(['school_id' => 999]);
    }
    if ($case === 'tenant') {
        DB::table('ec_gate_operator_assignments')->update(['tenant_id' => 999]);
    }
    if ($case === 'revoked') {
        DB::table('ec_gate_operator_assignments')->update(['active' => false]);
    }
    if ($case === 'inactive') {
        $this->operator->update(['is_active' => false]);
    }
    $this->postJson(gateActionUrl($this), gateActionBody($this, 'request_correction', ['movement_id' => $this->movement->event_id]))
        ->assertStatus($case === 'inactive' ? 403 : 404);
    expect(DB::table('ec_gate_actions')->count())->toBe(0);
})->with(['school', 'tenant', 'revoked', 'inactive']);

it('keeps gate-only restrictions even if gate features are disabled', function (): void {
    config(['gate.operator_enabled' => false]);
    $this->getJson('/api/admin/v2/dashboard')->assertForbidden();
    $this->getJson('/api/admin/v2/integration-connections')->assertForbidden();
    $this->postJson('/api/admin/v2/hikvision-enrollments/1/retry')->assertForbidden();
    $this->getJson('/api/admin/v2/gate')->assertStatus(409);
    $this->actingAs($this->operator, 'admin')->get('/admin/dashboard')->assertRedirect('/admin/gate');
    $this->getJson('/admin/students')->assertForbidden();
    $this->postJson('/admin/devices/1/sync-students')->assertForbidden();
});

it('switches restricted Inertia logins to the isolated gate entry even with an old main bundle', function (bool $enabled): void {
    config(['gate.operator_enabled' => $enabled]);
    $this->withSession(['url.intended' => '/admin/students'])
        ->withHeader('X-Inertia', 'true')->post('/admin/login', [
            'email' => $this->operator->email, 'password' => 'SYNTHETIC-password',
        ])->assertStatus(409)->assertHeader('X-Inertia-Location', '/admin/gate')
        ->assertSessionMissing('url.intended');
    $this->assertAuthenticatedAs($this->operator, 'admin');
})->with([true, false]);

it('keeps normal form login redirects for restricted accounts', function (): void {
    $this->post('/admin/login', ['email' => $this->operator->email, 'password' => 'SYNTHETIC-password'])
        ->assertRedirect('/admin/gate');
    $this->assertAuthenticatedAs($this->operator, 'admin');
});

it('hard reloads stale restricted Inertia navigation without expanding account access', function (): void {
    $this->actingAs($this->operator, 'admin')->withHeader('X-Inertia', 'true')
        ->get('/admin/dashboard')->assertStatus(409)->assertHeader('X-Inertia-Location', '/admin/gate');
    $this->post('/admin/login', [])->assertStatus(409)->assertHeader('X-Inertia-Location', '/admin/gate');
    $this->getJson('/admin/students')->assertForbidden();
});

it('leaves ordinary existing administrator access unchanged', function (): void {
    $admin = gateOperator($this->fixture, 'correction_approver', false);
    Sanctum::actingAs($admin, ['admin:*']);
    $this->getJson('/api/admin/v2/auth/me')->assertOk();
    expect($admin->gate_only)->toBeFalse();
    expect(in_array('gate_only', $admin->getFillable(), true))->toBeFalse();
});

it('refuses to roll back the durable restriction while gate-only accounts exist', function (): void {
    $migration = require base_path('database/migrations/2026_10_20_000023_add_gate_only_account_restriction.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect($this->operator->fresh()->gate_only)->toBeTrue();
    $this->getJson('/api/admin/v2/integration-connections')->assertForbidden();
});

it('lets a guard request but not directly apply or approve a correction', function (): void {
    $before = DB::table('ec_hikvision_observations')->sole();
    $body = gateActionBody($this, 'request_correction', ['movement_id' => $this->movement->event_id]);
    $response = $this->postJson(gateActionUrl($this), $body)->assertOk()->assertJsonPath('data.status', 'pending');
    $this->postJson(gateActionUrl($this), gateActionBody($this, 'correct', ['movement_id' => $this->movement->event_id]))->assertForbidden();
    $this->postJson(gateActionUrl($this), gateActionBody($this, 'approve_correction', ['action_id' => $response->json('data.action_id')]))->assertForbidden();
    expect(DB::table('ec_gate_days')->sole()->revision)->toBe($this->day->revision);
    expect(DB::table('ec_gate_movements')->sole()->state)->toBe('active');
    expect(DB::table('ec_gate_outbox')->count())->toBe(2);
    expect(DB::table('ec_hikvision_observations')->sole())->toEqual($before);
});

it('applies discipline master corrections directly with preserved originals and no approval queue', function (): void {
    $master = gateOperator($this->fixture, 'discipline_master');
    Sanctum::actingAs($master, ['admin:*']);
    $body = gateActionBody($this, 'correct', ['movement_id' => $this->movement->event_id]);
    $this->postJson(gateActionUrl($this), $body)->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.recorded_state', 'unknown');
    $head = DB::table('ec_gate_movements')->sole();
    expect($head->state)->toBe('withdrawn');
    expect($head->revision)->toBe(2);
    expect(json_decode($head->payload, true))->toMatchArray(['decision_source' => 'discipline_master', 'actor_id' => 'admin:'.$master->id]);
    expect(DB::table('ec_gate_movement_revisions')->count())->toBe(2);
    expect(DB::table('ec_hikvision_observations')->count())->toBe(1);
    expect(DB::table('ec_gate_actions')->count())->toBe(1);
    expect(DB::table('ec_gate_outbox')->count())->toBe(4);
    expect(DB::table('ec_attendance_events')->count())->toBe(0);
    expect(DB::table('ec_mobile_notifications')->count())->toBe(0);
    Http::assertNothingSent();
});

it('delivers an operator correction through the dedicated mirror and private parent notice paths', function (): void {
    Http::fake(['https://eduadmin.test/api/v1/integrations/edu-connect/gate-events' => function ($request) {
        $event = json_decode($request->body(), true)['events'][0];

        return Http::response(['accepted' => [$event['event_id'].':'.$event['revision']], 'duplicates' => []]);
    }]);
    expect(app(OutboxDispatcher::class)->work()['sent'])->toBe(2);
    $master = gateOperator($this->fixture, 'discipline_master');
    Sanctum::actingAs($master, ['admin:*']);
    $this->postJson(gateActionUrl($this), gateActionBody($this, 'correct', ['movement_id' => $this->movement->event_id,
        'note' => 'DO-NOT-SHARE-OPERATOR-NOTE']))->assertOk();
    expect(app(OutboxDispatcher::class)->work()['sent'])->toBe(2);
    expect(app(OutboxDispatcher::class)->work()['sent'])->toBe(0);
    Http::assertSentCount(2);
    Http::assertSent(function ($request) use ($master): bool {
        $event = json_decode($request->body(), true)['events'][0];

        return $event['event_id'] === $this->movement->event_id && $event['revision'] === 2 && $event['state'] === 'withdrawn'
            && $event['decision_source'] === 'discipline_master' && $event['actor_id'] === 'admin:'.$master->id
            && $event['student_id'] === 2934 && $event['school_id'] === 26
            && ! str_contains($request->body(), 'DO-NOT-SHARE-OPERATOR-NOTE');
    });
    $notices = DB::table('ec_mobile_notifications')->get();
    expect($notices)->toHaveCount(2);
    expect(json_encode($notices))->not->toContain('DO-NOT-SHARE-OPERATOR-NOTE', 'actor_id', 'reason_code');
    expect($notices->last()->title)->toBe('School gate record corrected');
    expect(DB::table('ec_attendance_events')->count())->toBe(0);
    expect(DB::table('ec_notification_deliveries')->count())->toBe(0);
});

it('allows a designated approver to apply a pending guard request exactly once', function (): void {
    $request = $this->postJson(gateActionUrl($this), gateActionBody($this, 'request_correction', ['movement_id' => $this->movement->event_id]))->assertOk()->json('data.action_id');
    Sanctum::actingAs(gateOperator($this->fixture, 'correction_approver'), ['admin:*']);
    $body = gateActionBody($this, 'approve_correction', ['idempotency_key' => 'SYNTHETIC-approval-1', 'action_id' => $request]);
    $this->postJson(gateActionUrl($this), $body)->assertOk();
    $this->postJson(gateActionUrl($this), $body)->assertOk()->assertJsonPath('data.replayed', true);
    expect(DB::table('ec_gate_actions')->count())->toBe(2);
    expect(DB::table('ec_gate_actions')->where('id', $request)->value('status'))->toBe('approved');
    expect(json_decode(DB::table('ec_gate_movements')->value('payload'), true)['decision_source'])->toBe('correction');
    DB::transaction(fn () => app(DayProjector::class)->rebuild($this->day->id));
    expect(DB::table('ec_gate_movement_revisions')->count())->toBe(2);
});

it('rejects stale correction requests instead of silently approving a changed history', function (): void {
    $request = $this->postJson(gateActionUrl($this), gateActionBody($this, 'request_correction', ['movement_id' => $this->movement->event_id]))->json('data.action_id');
    GateFixture::scan($this->fixture, 2, '07:31:00');
    app(DayProjector::class)->work();
    Sanctum::actingAs(gateOperator($this->fixture, 'correction_approver'), ['admin:*']);
    $this->postJson(gateActionUrl($this), gateActionBody($this, 'approve_correction', ['action_id' => $request]))->assertStatus(409);
    expect(DB::table('ec_gate_actions')->where('id', $request)->value('status'))->toBe('pending');
    expect(DB::table('ec_gate_movements')->sole()->state)->toBe('active');
});

it('deduplicates action retries but rejects key reuse with different contents', function (): void {
    $body = gateActionBody($this, 'request_correction', ['movement_id' => $this->movement->event_id]);
    $this->postJson(gateActionUrl($this), $body)->assertOk()->assertJsonPath('data.replayed', false);
    $this->postJson(gateActionUrl($this), $body)->assertOk()->assertJsonPath('data.replayed', true);
    $this->postJson(gateActionUrl($this), array_replace($body, ['note' => 'Changed']))->assertStatus(409);
    expect(DB::table('ec_gate_actions')->count())->toBe(1);
    DB::table('ec_gate_operator_assignments')->update(['active' => false]);
    $this->postJson(gateActionUrl($this), $body)->assertNotFound();
});

it('refuses forged actor role target clock and approval fields', function (string $field): void {
    $body = gateActionBody($this, 'request_correction', ['movement_id' => $this->movement->event_id, $field => 'forged']);
    $this->postJson(gateActionUrl($this), $body)->assertStatus(422);
    expect(DB::table('ec_gate_actions')->count())->toBe(0);
})->with(['actor_id', 'role', 'student_id', 'school_id', 'created_at', 'expires_at', 'approved', 'parameters']);

it('requires the current day revision and school-configured reasons', function (string $case): void {
    $body = gateActionBody($this, 'request_correction', ['movement_id' => $this->movement->event_id]);
    $body[$case === 'revision' ? 'expected_revision' : 'reason_code'] = $case === 'revision' ? 999 : 'unconfigured_reason';
    $this->postJson(gateActionUrl($this), $body)->assertStatus(409);
    expect(DB::table('ec_gate_actions')->count())->toBe(0);
})->with(['revision', 'reason']);

it('prepares an audited early exit without a movement until a fresh scan then allows one return', function (): void {
    Carbon::setTestNow('2026-10-20T13:58:00Z');
    $body = gateActionBody($this, 'permit_exit', ['return_allowed' => true, 'checks' => ['synthetic_identity', 'synthetic_release_check']]);
    $permit = $this->postJson(gateActionUrl($this), $body)->assertOk()->assertJsonPath('data.status', 'active')->json('data.action_id');
    expect(DB::table('ec_gate_movements')->count())->toBe(1);
    expect(DB::table('ec_gate_actions')->sole()->expires_at)->toBe('2026-10-20 14:00:00');
    // A scan captured at/before preparation cannot spend the new authorization.
    GateFixture::scan($this->fixture, 2, '14:57:59');
    app(DayProjector::class)->work();
    expect(DB::table('ec_gate_actions')->where('id', $permit)->value('status'))->toBe('active');
    GateFixture::scan($this->fixture, 3, '14:59:00');
    app(DayProjector::class)->work();
    expect(DB::table('ec_gate_actions')->where('id', $permit)->value('status'))->toBe('consumed');
    GateFixture::scan($this->fixture, 4, '14:59:10');
    GateFixture::scan($this->fixture, 5, '15:01:00');
    GateFixture::scan($this->fixture, 6, '15:01:20');
    GateFixture::scan($this->fixture, 7, '15:02:00');
    app(DayProjector::class)->work();
    expect(DB::table('ec_gate_movements')->orderBy('observation_id')->pluck('movement')->all())->toBe(['check_in', 'check_out', 'check_in', 'check_out']);
    expect(DB::table('ec_gate_actions')->count())->toBe(1);
    expect(DB::table('ec_gate_days')->sole()->state)->toBe('outside');
});

it('rejects missing school checks and overlapping prepared exit permits', function (): void {
    $body = gateActionBody($this, 'permit_exit', ['return_allowed' => false, 'checks' => ['synthetic_identity']]);
    $this->postJson(gateActionUrl($this), $body)->assertStatus(409);
    $body['checks'][] = 'synthetic_release_check';
    $this->postJson(gateActionUrl($this), $body)->assertOk();
    $this->postJson(gateActionUrl($this), array_replace($body, ['idempotency_key' => 'SYNTHETIC-second-permit']))->assertStatus(409);
});

it('does not consume a prepared exit after its operator assignment has been revoked', function (): void {
    $this->postJson(gateActionUrl($this), gateActionBody($this, 'permit_exit', ['return_allowed' => false,
        'checks' => ['synthetic_identity', 'synthetic_release_check']]))->assertOk();
    DB::table('ec_gate_operator_assignments')->update(['active' => false]);
    GateFixture::scan($this->fixture, 2, '10:00:10');
    app(DayProjector::class)->work();
    expect(DB::table('ec_gate_movements')->count())->toBe(1);
    expect(DB::table('ec_gate_days')->value('state'))->toBe('unknown');
    expect(DB::table('ec_gate_actions')->value('consumed_observation_id'))->toBeNull();
});

it('keeps a consumed authorization on its original scan when an earlier fresh scan arrives late', function (): void {
    $permit = $this->postJson(gateActionUrl($this), gateActionBody($this, 'permit_exit', ['return_allowed' => false,
        'checks' => ['synthetic_identity', 'synthetic_release_check']]))->assertOk()->json('data.action_id');
    $used = GateFixture::scan($this->fixture, 3, '10:00:20');
    app(DayProjector::class)->work();
    GateFixture::scan($this->fixture, 2, '10:00:10');
    app(DayProjector::class)->work();
    expect(DB::table('ec_gate_actions')->where('id', $permit)->value('consumed_observation_id'))->toBe($used);
    expect(DB::table('ec_gate_movements')->where('movement', 'check_out')->sole()->observation_id)->toBe($used);
    DB::table('ec_gate_operator_assignments')->update(['active' => false]);
    DB::transaction(fn () => app(DayProjector::class)->rebuild($this->day->id));
    expect(DB::table('ec_gate_movements')->where('movement', 'check_out')->sole()->state)->toBe('active');
});

it('renders the restricted web workspace without broad admin navigation', function (): void {
    $this->withoutVite();
    $this->actingAs($this->operator, 'admin')->get('/admin/gate/days/'.$this->day->id)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn ($page) => $page->component('Admin/Gate/Index')->where('roles', ['guard'])
        ->where('detail.id', $this->day->id)->missing('profiles')->missing('enrollments'));
});

it('cancels an unused permit with an immutable separate audit and cannot consume it later', function (): void {
    $permit = $this->postJson(gateActionUrl($this), gateActionBody($this, 'permit_exit', ['return_allowed' => false,
        'checks' => ['synthetic_identity', 'synthetic_release_check']]))->assertOk()->json('data.action_id');
    $this->postJson(gateActionUrl($this), gateActionBody($this, 'cancel_exit', ['idempotency_key' => 'SYNTHETIC-cancel', 'action_id' => $permit]))->assertOk();
    GateFixture::scan($this->fixture, 2, '10:00:10');
    app(DayProjector::class)->work();
    expect(DB::table('ec_gate_actions')->where('id', $permit)->value('status'))->toBe('cancelled');
    expect(DB::table('ec_gate_actions')->count())->toBe(2);
    expect(DB::table('ec_gate_movements')->count())->toBe(1);
});

it('refuses another guards cancellation and refuses cancellation after consumption', function (): void {
    $permit = $this->postJson(gateActionUrl($this), gateActionBody($this, 'permit_exit', ['return_allowed' => false,
        'checks' => ['synthetic_identity', 'synthetic_release_check']]))->assertOk()->json('data.action_id');
    Sanctum::actingAs(gateOperator($this->fixture, 'guard'), ['admin:*']);
    $this->postJson(gateActionUrl($this), gateActionBody($this, 'cancel_exit', ['action_id' => $permit]))->assertForbidden();
    Sanctum::actingAs($this->operator, ['admin:*']);
    GateFixture::scan($this->fixture, 2, '10:00:10');
    app(DayProjector::class)->work();
    $this->postJson(gateActionUrl($this), gateActionBody($this, 'cancel_exit', ['action_id' => $permit]))->assertStatus(409);
    expect(DB::table('ec_gate_actions')->where('id', $permit)->value('status'))->toBe('consumed');
});

it('fails closed for missing early release or return configuration', function (string $setting): void {
    $policy = $this->fixture['policy'];
    if ($setting === 'release') {
        unset($policy['operator_rules']['early_exit']);
    }
    if ($setting === 'return') {
        $policy['return_rules'] = null;
    }
    if ($setting === 'timing') {
        unset($policy['operator_rules']['early_exit']['validity_seconds']);
    }
    DB::table('ec_gate_policies')->update(['snapshot' => json_encode($policy)]);
    $this->postJson(gateActionUrl($this), gateActionBody($this, 'permit_exit', ['return_allowed' => true,
        'checks' => ['synthetic_identity', 'synthetic_release_check']]))->assertStatus(409);
    expect(DB::table('ec_gate_actions')->count())->toBe(0);
})->with(['release', 'return', 'timing']);

it('resolves only a fresh first departure scan not an old direction guess', function (bool $stale): void {
    DB::table('ec_gate_policies')->update(['snapshot' => json_encode(array_replace($this->fixture['policy'], ['arrival_start' => '15:00:00', 'arrival_end' => '15:01:00', 'late_cutoff' => '15:01:00', 'departure_start' => '15:02:00']))]);
    // A different fixture student, not a deletion or rewrite of existing evidence.
    $this->fixture['student']->update(['student_number' => 'SYNTHETIC-OLD']);
    $student = App\Models\V2\Student::create(['tenant_id' => $this->fixture['tenant']->id, 'school_id' => $this->fixture['school']->id,
        'student_number' => 'SYNTHETIC-DIRECTION', 'first_name' => 'Synthetic', 'last_name' => 'Direction', 'status' => 'enrolled']);
    DB::table('ec_device_personnel')->update(['student_id' => $student->id]);
    DB::table('ec_gate_policies')->update(['student_ids' => json_encode([$student->id])]);
    Carbon::setTestNow($stale ? '2026-10-20T14:10:00Z' : '2026-10-20T14:02:10Z');
    $scan = GateFixture::scan($this->fixture, 2, '15:02:00');
    app(DayProjector::class)->work();
    $this->day = DB::table('ec_gate_days')->where('student_id', $student->id)->sole();
    $body = gateActionBody($this, 'resolve', ['observation_id' => $scan, 'movement' => 'check_out']);
    $this->postJson(gateActionUrl($this), $body)->assertStatus($stale ? 409 : 200);
    expect(DB::table('ec_gate_movements')->where('day_id', $this->day->id)->count())->toBe($stale ? 0 : 1);
})->with([false, true]);

it('rolls the action audit and withdrawal back when durable delivery intent storage fails', function (): void {
    Sanctum::actingAs(gateOperator($this->fixture, 'discipline_master'), ['admin:*']);
    DB::unprepared("CREATE TRIGGER gate_action_fail BEFORE INSERT ON ec_gate_outbox BEGIN SELECT RAISE(ABORT, 'SYNTHETIC failure'); END");
    $this->postJson(gateActionUrl($this), gateActionBody($this, 'correct', ['movement_id' => $this->movement->event_id]))->assertStatus(500);
    expect(DB::table('ec_gate_actions')->count())->toBe(0);
    expect(DB::table('ec_gate_movements')->sole()->state)->toBe('active');
    expect(DB::table('ec_gate_movement_revisions')->count())->toBe(1);
});

it('rejects parent tokens and recovery-only administrators', function (): void {
    $parent = ParentAccount::create(['phone' => 'SYNTHETIC-NOT-OPERATOR', 'first_name' => 'Synthetic', 'last_name' => 'Parent', 'status' => 'active']);
    Sanctum::actingAs($parent, ['*']);
    $this->getJson('/api/admin/v2/gate')->assertForbidden();
    $this->operator->forceFill(['test_recovery_only' => true])->save();
    Sanctum::actingAs($this->operator, ['admin:*']);
    $this->getJson('/api/admin/v2/gate')->assertForbidden();
});
