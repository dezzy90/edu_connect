<?php

use App\Models\AdminUser;
use App\Services\Biometrics\Gate\InstallationPreparation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Biometrics\GateFixture;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    $this->f = GateFixture::boot();
    foreach (['2026_10_20_000023_add_gate_only_account_restriction', '2026_10_21_000024_create_gate_setup_drafts',
        '2026_10_21_000025_create_device_registry'] as $migration) {
        (require base_path('database/migrations/'.$migration.'.php'))->up();
    }
    config(['gate.enabled' => false, 'gate.mirror_delivery_enabled' => false, 'gate.parent_delivery_enabled' => false,
        'gate.activation_enabled' => false, 'gate.activation_release_ready' => false, 'hikvision_push.enabled' => false,
        'hikvision_push.enrollment_enabled' => false, 'hikvision_push.enrollment_device_writes_enabled' => false,
        'integrations.provisioning.edu_admin.allowed_base_urls' => ['https://source.test']]);
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
    $this->source = ['origin' => 'https://source.test', 'complex' => ['id' => 42, 'name' => 'SYNTHETIC prepared complex',
        'slug' => 'synthetic-prepared-complex', 'status' => 'active'], 'schools' => [],
        'access_token' => 'SYNTHETIC-'.str_repeat('a', 64), 'webhook_secret' => 'SYNTHETIC-'.str_repeat('b', 64), 'credential_id' => 88];
    for ($i = 1; $i <= 2; $i++) {
        $this->source['schools'][] = ['id' => 1000 + $i, 'complex_id' => 42, 'name' => 'SYNTHETIC source school '.$i, 'type' => 'primary', 'status' => 'active'];
    }
    $this->requestId = 'b8b1eae8-9b0f-4cf2-9f92-a48b4b5c1311';
    $this->original = json_encode(DB::table('ec_integration_connections')->where('id', $this->f['connection']->id)->sole());
});

function prepareGateInstallation(object $test): array
{
    return app(InstallationPreparation::class)->prepare($test->source, 'setup@example.test', $test->requestId);
}

it('prepares exact source mappings and a restricted inactive owner without starting any live operation', function (): void {
    $result = prepareGateInstallation($this);
    $owner = AdminUser::findOrFail($result['admin_id']);
    expect($owner->is_active)->toBeFalse()->and($owner->gate_only)->toBeTrue()->and($owner->role)->toBe('school_admin')
        ->and($owner->school_id)->toBeNull()->and($owner->email_verified_at)->toBeNull();
    expect($result)->toMatchArray(['account_active' => false, 'connection_active' => false, 'student_import_started' => false, 'terminal_registered' => false]);
    expect(DB::table('ec_gate_setup_assignments')->where('admin_id', $owner->id)->count())->toBe(2);
    expect(DB::table('ec_device_admin_assignments')->where('admin_id', $owner->id)->count())->toBe(1);
    expect(DB::table('ec_gate_operator_assignments')->where('admin_id', $owner->id)->count())->toBe(0);
    foreach ($result['schools'] as $mapping) {
        expect(DB::table('ec_integration_mappings')->where('connection_id', $result['connection_id'])->where('local_type', 'school')
            ->where('local_id', $mapping['local_id'])->value('external_id'))->toBe((string) $mapping['source_id']);
        expect(DB::table('ec_gate_mirror_bindings')->where('school_id', $mapping['local_id'])->value('enabled'))->toBe(0);
    }
    $connection = DB::table('ec_integration_connections')->where('id', $result['connection_id'])->sole();
    expect($connection->status)->toBe('inactive')->and(json_encode((array) $connection))->not->toContain($this->source['access_token'], $this->source['webhook_secret']);
    expect(DB::table('ec_students')->count())->toBe(1)->and(DB::table('ec_hikvision_push_devices')->count())->toBe(1)
        ->and(DB::table('ec_gate_setup_drafts')->count())->toBe(0);
    expect(json_encode(DB::table('ec_integration_connections')->where('id', $this->f['connection']->id)->sole()))->toBe($this->original);
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('replays without resetting the owner password grants secrets or mapping identities', function (): void {
    $first = prepareGateInstallation($this);
    $owner = AdminUser::findOrFail($first['admin_id']);
    $owner->forceFill(['password' => 'SYNTHETIC-Activated-123!', 'is_active' => true])->save();
    $second = prepareGateInstallation($this);
    expect($second['schools'])->toBe($first['schools'])->and($second['admin_id'])->toBe($first['admin_id']);
    expect(Hash::check('SYNTHETIC-Activated-123!', $owner->fresh()->password))->toBeTrue();
    expect(DB::table('ec_gate_setup_assignments')->count())->toBe(2)->and(DB::table('ec_device_admin_assignments')->count())->toBe(1);
});

it('supports a variable number of schools without assuming three', function (int $count): void {
    $this->source['schools'] = [];
    for ($i = 1; $i <= $count; $i++) {
        $this->source['schools'][] = ['id' => 1000 + $i, 'complex_id' => 42, 'name' => 'SYNTHETIC school '.$i, 'type' => 'primary', 'status' => 'active'];
    }
    expect(prepareGateInstallation($this)['schools'])->toHaveCount($count);
})->with([1, 3, 5, 20]);

it('rejects mismatched source scopes and unsafe inputs before creating any new identities', function (string $case): void {
    match ($case) {
        'foreign' => $this->source['schools'][0]['complex_id'] = 99,
        'duplicate' => $this->source['schools'][1]['id'] = $this->source['schools'][0]['id'],
        'inactive' => $this->source['schools'][0]['status'] = 'inactive',
        'unapproved_origin' => $this->source['origin'] = 'https://untrusted.test',
        'live_pipeline' => config(['gate.enabled' => true]),
    };
    try {
        prepareGateInstallation($this);
        $this->fail('Unsafe preparation unexpectedly succeeded');
    } catch (RuntimeException|Illuminate\Validation\ValidationException) {
        expect(DB::table('ec_tenants')->count())->toBe(1)->and(DB::table('admin_users')->count())->toBe(0);
    }
})->with(['foreign', 'duplicate', 'inactive', 'unapproved_origin', 'live_pipeline']);

it('does not repurpose an existing account and rolls back newly allocated source identities', function (): void {
    AdminUser::create(['name' => 'Existing account', 'email' => 'setup@example.test', 'password' => 'SYNTHETIC-old', 'role' => 'super_admin']);
    expect(fn () => prepareGateInstallation($this))->toThrow(RuntimeException::class);
    expect(DB::table('ec_tenants')->count())->toBe(1)->and(DB::table('ec_schools')->count())->toBe(1);
});

it('never silently reactivates revoked grants or overwrites a changed prepared connection', function (string $case): void {
    $first = prepareGateInstallation($this);
    match ($case) {
        'grant' => DB::table('ec_gate_setup_assignments')->where('admin_id', $first['admin_id'])->update(['active' => false]),
        'connection' => DB::table('ec_integration_connections')->where('id', $first['connection_id'])->update(['status' => 'active']),
        'credential' => $this->source['access_token'] = 'SYNTHETIC-'.str_repeat('c', 64),
        'membership' => array_pop($this->source['schools']),
    };
    expect(fn () => prepareGateInstallation($this))->toThrow(RuntimeException::class);
    expect(DB::table('ec_schools')->where('tenant_id', $first['tenant_id'])->count())->toBe(2);
    if ($case === 'grant') {
        expect(DB::table('ec_gate_setup_assignments')->where('admin_id', $first['admin_id'])->where('active', true)->count())->toBe(0);
    }
})->with(['grant', 'connection', 'credential', 'membership']);

function gateReconnectPayload(object $test): array
{
    return ['request_id' => 'b8b1eae8-9b0f-4cf2-9f92-a48b4b5c1322', 'edu_admin_base_url' => $test->source['origin'],
        'complex' => $test->source['complex'], 'school_ids' => array_column($test->source['schools'], 'id'),
        'scopes' => ['unrelated:read'], 'access_token' => $test->source['access_token'], 'webhook_secret' => $test->source['webhook_secret']];
}

it('reconnects prepared schools with local permissions and preserves gate-only resources and existing school visibility', function (): void {
    $prepared = prepareGateInstallation($this);
    $connection = App\Models\V2\IntegrationConnection::findOrFail($prepared['connection_id']);
    $flags = $connection->feature_flags + ['gate_only_sync' => true, 'gate_parent_links_enabled' => true];
    $connection->update(['feature_flags' => $flags, 'status' => 'active']);
    $scopes = $connection->scopes;
    $reconnected = app(App\Services\Integration\EduAdminProvisioningService::class)->connect(gateReconnectPayload($this));
    expect($reconnected->enabled_school_ids)->toBe(array_column($prepared['schools'], 'local_id'))
        ->and($reconnected->feature_flags)->toBe($flags)->and($reconnected->scopes)->toBe($scopes);
    app(App\Services\Integration\EduAdminProvisioningService::class)->deactivateSchoolsOutsideScope($reconnected);
    expect(DB::table('ec_schools')->where('tenant_id', $prepared['tenant_id'])->where('status', 'active')->count())->toBe(2);
    Queue::assertPushed(App\Jobs\V2\Integration\RunEduAdminInitialSyncJob::class, 1);
    Http::assertNothingSent();
});

it('rejects reconnect scope drift before changing the prepared connection', function (string $change): void {
    $prepared = prepareGateInstallation($this);
    $connection = App\Models\V2\IntegrationConnection::findOrFail($prepared['connection_id']);
    $connection->update(['feature_flags' => $connection->feature_flags + ['gate_only_sync' => true]]);
    $payload = gateReconnectPayload($this);
    match ($change) {
        'removed' => array_pop($payload['school_ids']),
        'added' => $payload['school_ids'][] = 9999,
        'origin' => $payload['edu_admin_base_url'] = 'https://another-approved.test',
        'mapping' => DB::table('ec_integration_mappings')->where('connection_id', $connection->id)->where('local_type', 'school')->limit(1)->update(['external_id' => '9999']),
        'lost_profile' => $connection->update(['feature_flags' => []]),
    };
    config(['integrations.provisioning.edu_admin.allowed_base_urls' => ['https://source.test', 'https://another-approved.test']]);
    $before = $connection->fresh()->getRawOriginal();
    expect(fn () => app(App\Services\Integration\EduAdminProvisioningService::class)->connect($payload))->toThrow(RuntimeException::class);
    expect($connection->fresh()->getRawOriginal())->toBe($before);
    Queue::assertNothingPushed();
})->with(['removed', 'added', 'origin', 'mapping', 'lost_profile']);
