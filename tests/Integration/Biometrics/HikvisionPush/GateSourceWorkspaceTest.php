<?php

use App\Models\AdminUser;
use App\Services\Biometrics\Gate\DayProjector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Biometrics\GateFixture;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->f = GateFixture::boot();
    foreach (['2026_10_19_000021_create_hikvision_enrollments.php', '2026_10_20_000023_add_gate_only_account_restriction.php', '2026_10_21_000024_create_gate_setup_drafts.php', '2026_10_21_000025_create_device_registry.php', '2026_10_22_000027_create_gate_publications.php', '2026_10_24_000029_create_gate_source_workspace.php'] as $file) (require base_path('database/migrations/'.$file))->up();
    Carbon::setTestNow('2026-10-20T09:00:00Z');
    config(['gate_workspace.enabled' => true, 'gate.operator_enabled' => true, 'gate.secretary_complex_ids' => [$this->f['tenant']->id]]);
    $this->f['policy']['operator_rules'] = ['early_exit' => ['reason_codes' => ['synthetic_release'], 'validity_seconds' => 120, 'required_checks' => ['synthetic_identity']]];
    DB::table('ec_gate_policies')->update(['snapshot' => json_encode($this->f['policy'])]);
    DB::table('ec_gate_staff_bridges')->insert(['id' => 'synthetic-bridge-0001', 'connection_id' => $this->f['connection']->id, 'source_complex_id' => 2, 'secret' => Crypt::encryptString('SYNTHETIC-bridge-secret'), 'enabled' => true]);
    $this->body = ['complex_id' => 2, 'operation' => 'days', 'actor' => ['id' => 31, 'name' => 'SYNTHETIC secretary', 'schools' => [['id' => 26, 'roles' => ['secretary']]]], 'school_id' => 26, 'day' => '2026-10-20'];
    GateFixture::scan($this->f, 1, '07:30:00'); app(DayProjector::class)->work();
    $this->url = 'https://localhost/api/integrations/edu-admin/gate-workspace';
});
afterEach(fn () => Carbon::setTestNow());

it('requires explicit source setup permissions and revokes them on refresh', function () {
    config(['gate.setup_enabled' => true, 'gate.setup_school_ids' => [$this->f['school']->id]]);
    $this->body['operation'] = 'setup';
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertForbidden();
    $this->body['actor']['schools'][0] = ['id' => 26, 'roles' => ['attendance_viewer'], 'permissions' => ['configure', 'activate']];
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertOk()->assertJsonPath('data.draft.revision', 0);
    expect(DB::table('ec_gate_setup_assignments')->where('active', true)->count())->toBe(1);
    $actor = AdminUser::sole();
    expect(fn () => app(\App\Services\Biometrics\Gate\SetupAccess::class)->actor($actor))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->body['actor']['schools'][0]['permissions'] = [];
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertForbidden();
    expect(DB::table('ec_gate_setup_assignments')->where('active', true)->count())->toBe(0);
    expect(DB::table('ec_gate_activation_assignments')->where('active', true)->count())->toBe(0);
});

it('does not allow class-scoped viewers to acquire configuration authority', function () {
    $this->body['actor']['schools'][0] = ['id' => 26, 'roles' => ['attendance_viewer'], 'class_ids' => [99], 'permissions' => ['configure']];
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertForbidden();
    expect(DB::table('ec_gate_setup_assignments')->count())->toBe(0);
});

it('keeps DM-only corrections disabled even when the authenticated actor has the DM role', function () {
    config(['gate.recording_only_school_ids' => [$this->f['school']->id]]);
    $this->body['operation'] = 'action'; $this->body['day_id'] = DB::table('ec_gate_days')->sole()->id;
    $this->body['actor']['schools'][0]['roles'] = ['discipline_master'];
    $this->body['action'] = ['operation' => 'correct', 'expected_revision' => 1, 'idempotency_key' => 'SYNTHETIC-disabled-dm',
        'reason_code' => 'synthetic', 'movement_id' => str_repeat('a', 64)];
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertForbidden();
    expect(DB::table('ec_gate_actions')->count())->toBe(0);
});

function signedGateHeaders(array $body, ?string $nonce = null, ?string $stamp = null): array {
    $stamp ??= (string) now()->timestamp; $nonce ??= bin2hex(random_bytes(24));
    return ['X-Gate-Key' => 'synthetic-bridge-0001', 'X-Gate-Time' => $stamp, 'X-Gate-Nonce' => $nonce, 'X-Gate-Signature' => hash_hmac('sha256', "POST\n/api/integrations/edu-admin/gate-workspace\n".$stamp."\n".$nonce."\n".hash('sha256', json_encode($body)), 'SYNTHETIC-bridge-secret')];
}
it('opens the existing gate engine with a non-login source audit identity', function () {
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertOk()->assertJsonPath('data.roles', ['secretary'])->assertJsonPath('data.days.data.0.student_id', 2934);
    $actor = AdminUser::sole(); expect((bool) $actor->gate_source_only)->toBeTrue();
    expect(DB::table('ec_gate_source_actors')->sole()->source_user_id)->toBe(31);
});
it('rejects replay, stale signatures, body tampering and cleartext transport', function () {
    $headers = signedGateHeaders($this->body);
    $this->postJson($this->url, $this->body, $headers)->assertOk();
    $this->postJson($this->url, $this->body, $headers)->assertConflict();
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body, null, (string) (now()->timestamp - 120)))->assertUnauthorized();
    $body = $this->body; $body['actor']['schools'][0]['roles'] = ['discipline_master'];
    $this->postJson($this->url, $body, signedGateHeaders($this->body))->assertUnauthorized();
    $this->postJson('http://localhost/api/integrations/edu-admin/gate-workspace', $this->body, signedGateHeaders($this->body))->assertBadRequest();
});
it('rejects cross-complex and cross-school claims', function () {
    $this->body['complex_id'] = 99;
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertForbidden();
    $this->body['complex_id'] = 2; $this->body['school_id'] = 9999;
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertNotFound();
});
it('filters class-master lists and prevents direct access to another student day', function () {
    $this->body['actor']['schools'][0] = ['id' => 26, 'roles' => ['attendance_viewer'], 'class_ids' => [9999]];
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertOk()->assertJsonCount(0, 'data.days.data');
    $this->body['operation'] = 'detail'; $this->body['day_id'] = DB::table('ec_gate_days')->sole()->id;
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertNotFound();
    $this->body['actor']['schools'][0]['roles'] = ['secretary'];
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertForbidden();
});
it('returns status without credentials, portraits or stale device contact claims', function () {
    $this->body['operation'] = 'status';
    $reply = $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertOk()->assertJsonPath('data.scheduled', true)->assertJsonPath('data.publication_active', false);
    expect($reply->getContent())->not->toContain('secret')->not->toContain('password')->not->toContain('photo_encrypted');
});
it('rejects primary-school DM grants even with a correctly signed request', function () {
    DB::table('ec_schools')->where('id', $this->f['school']->id)->update(['type' => 'primary']);
    $this->body['actor']['schools'][0]['roles'] = ['discipline_master'];
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertForbidden();
});
it('refreshes source roles instead of retaining old secretary grants', function () {
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertOk();
    $this->body['actor']['schools'][0]['roles'] = ['attendance_viewer'];
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertOk()->assertJsonPath('data.roles', ['attendance_viewer']);
    expect(DB::table('ec_gate_operator_assignments')->where('role', 'secretary')->value('active'))->toBe(0);
});
it('denies local browser/API sessions for source-only actors', function () {
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertOk();
    $actor = AdminUser::sole();
    Sanctum::actingAs($actor, ['admin:*']);
    $this->getJson('/api/admin/v2/gate')->assertForbidden();
    $this->actingAs($actor, 'admin')->get('/admin/gate')->assertForbidden();
    expect(fn () => app(\App\Services\Biometrics\Gate\OperatorAccess::class)->actor($actor))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
it('keeps readonly source staff from performing gate actions', function () {
    $this->body['operation'] = 'action'; $this->body['day_id'] = DB::table('ec_gate_days')->sole()->id;
    $this->body['actor']['schools'][0]['roles'] = ['attendance_viewer'];
    $this->body['action'] = ['operation' => 'correct', 'expected_revision' => 1, 'idempotency_key' => 'SYNTHETIC-action', 'reason_code' => 'synthetic', 'movement_id' => str_repeat('a', 64)];
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertForbidden();
    expect(DB::table('ec_gate_actions')->count())->toBe(0);
});
it('records an attendance-only secretary permit with a bounded source-authority lease and compatible mirror provenance', function () {
    $this->body['operation'] = 'action'; $this->body['day_id'] = DB::table('ec_gate_days')->sole()->id;
    $this->body['action'] = ['operation' => 'permit_exit', 'expected_revision' => (int) DB::table('ec_gate_days')->sole()->revision,
        'idempotency_key' => 'SYNTHETIC-permit', 'reason_code' => 'synthetic_release', 'checks' => ['synthetic_identity'], 'return_allowed' => false];
    $this->postJson($this->url, $this->body, signedGateHeaders($this->body))->assertOk();
    $permit = DB::table('ec_gate_actions')->sole();
    expect($permit->role)->toBe('secretary')->and(Carbon::parse($permit->expires_at, 'UTC')->timestamp)->toBe(now()->addSeconds(60)->timestamp);
    Carbon::setTestNow('2026-10-20T09:00:10Z');
    GateFixture::scan($this->f, 2, '10:00:10'); app(DayProjector::class)->work();
    $movement = DB::table('ec_gate_movements')->where('movement', 'check_out')->sole();
    $payload = json_decode($movement->payload, true);
    expect($payload['decision_source'])->toBe('guard')->and($payload['actor_id'])->toBe('admin:'.$permit->actor_id);
    expect(DB::table('admin_users')->count())->toBe(1);
});
