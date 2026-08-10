<?php

use App\Models\AdminUser;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\IntegrationSyncRun;
use App\Models\V2\MobileMessage;
use App\Models\V2\School;
use App\Models\V2\Student;
use App\Models\V2\StudentMobileProfile;
use App\Models\V2\Tenant;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\Sanctum;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();

    config([
        'integrations.providers.edu_admin.driver' => 'fixture',
        'integrations.providers.edu_admin.fixture_path' => base_path('tests/Fixtures/edu_admin_connector'),
    ]);
});

it('lets a super admin manage an Edu-admin connection and run initial sync', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Local Demo',
        'slug' => 'local-demo',
        'status' => 'active',
    ]);

    $admin = AdminUser::query()->create([
        'name' => 'Ada Admin',
        'email' => 'ada@example.com',
        'password' => 'secret',
        'role' => 'super_admin',
        'is_active' => true,
    ]);

    Sanctum::actingAs($admin, ['*']);

    $createResponse = $this->postJson('/api/admin/v2/integration-connections', [
        'tenant_id' => $tenant->id,
        'mode' => 'connected',
        'status' => 'active',
        'base_url' => 'https://edu-admin.test',
        'remote_tenant_id' => '100',
        'scopes' => ['foundation:read', 'attendance:write'],
        'feature_flags' => ['results' => true],
        'access_token' => 'secret-access-token',
    ]);

    $createResponse->assertCreated();
    expect($createResponse->json('data.provider'))->toBe('edu_admin');
    expect($createResponse->json('data'))->not->toHaveKey('encrypted_access_token');

    $connectionId = $createResponse->json('data.id');

    $updateResponse = $this->patchJson("/api/admin/v2/integration-connections/{$connectionId}", [
        'base_url' => 'https://edu-admin-updated.test',
    ]);

    $updateResponse->assertOk();
    expect($updateResponse->json('data.base_url'))->toBe('https://edu-admin-updated.test');
    expect($updateResponse->json('data.remote_tenant_id'))->toBe('100');
    expect($updateResponse->json('data.status'))->toBe('active');

    $syncResponse = $this->postJson("/api/admin/v2/integration-connections/{$connectionId}/sync-initial", [
        'driver' => 'fixture',
    ]);

    $syncResponse->assertOk();
    expect($syncResponse->json('data.sync_run.status'))->toBe('completed');
    expect($syncResponse->json('data.sync_run.records_read'))->toBe(12);
    expect($syncResponse->json('data.sync_run.records_created'))->toBe(12);
    expect($syncResponse->json('data.sync_run.triggered_by_id'))->toBe($admin->id);
    expect(Student::query()->count())->toBe(2);
    expect(StudentMobileProfile::query()->count())->toBe(1);
    expect(MobileMessage::query()->count())->toBe(1);

    $incrementalResponse = $this->postJson("/api/admin/v2/integration-connections/{$connectionId}/sync-incremental", [
        'driver' => 'fixture',
        'updated_after' => '2026-08-07T08:00:00Z',
        'resources' => ['mobile_messages'],
    ]);

    $incrementalResponse->assertOk();
    expect($incrementalResponse->json('data.sync_run.status'))->toBe('completed');
    expect($incrementalResponse->json('data.sync_run.sync_type'))->toBe('incremental');
    expect($incrementalResponse->json('data.sync_run.records_read'))->toBe(1);
    expect($incrementalResponse->json('data.sync_run.records_updated'))->toBe(1);
    expect(MobileMessage::query()->count())->toBe(1);

    $connection = IntegrationConnection::query()->findOrFail($connectionId);
    expect($connection->last_successful_sync_at)->not->toBeNull();
    expect($connection->last_error)->toBeNull();

    $runsResponse = $this->getJson("/api/admin/v2/integration-connections/{$connectionId}/sync-runs");
    $runsResponse->assertOk();
    expect($runsResponse->json('data'))->toHaveCount(2);
});

it('creates the Edu-connect tenant automatically from Edu-admin credentials', function (): void {
    $admin = AdminUser::query()->create([
        'name' => 'Ada Admin',
        'email' => 'ada-auto@example.com',
        'password' => 'secret',
        'role' => 'super_admin',
        'is_active' => true,
    ]);

    Sanctum::actingAs($admin, ['*']);

    $createResponse = $this->postJson('/api/admin/v2/integration-connections', [
        'mode' => 'connected',
        'status' => 'active',
        'base_url' => 'https://edu-admin.test',
        'scopes' => ['foundation:read', 'attendance:write'],
        'access_token' => 'secret-access-token',
        'webhook_secret' => 'secret-webhook-secret',
    ]);

    $createResponse->assertCreated();
    expect($createResponse->json('data.tenant.name'))->toBe('Demo Education Complex');
    expect($createResponse->json('data.tenant.slug'))->toBe('demo-education-complex');
    expect($createResponse->json('data.remote_tenant_id'))->toBe('100');
    expect(Tenant::query()->count())->toBe(1);

    $tenant = Tenant::query()->firstOrFail();
    expect($tenant->source_system)->toBe('edu_admin');
    expect($tenant->source_id)->toBe('100');

    $connectionId = $createResponse->json('data.id');

    $syncResponse = $this->postJson("/api/admin/v2/integration-connections/{$connectionId}/sync-initial", [
        'driver' => 'fixture',
    ]);

    $syncResponse->assertOk();
    expect(School::query()->count())->toBe(1);
    expect(Student::query()->count())->toBe(2);
});

it('returns a readable validation error when initial sync cannot reach Edu-admin', function (): void {
    config(['integrations.providers.edu_admin.driver' => 'http']);

    $tenant = Tenant::query()->create([
        'name' => 'HTTP Tenant',
        'slug' => 'http-tenant',
        'status' => 'active',
    ]);

    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'edu_admin',
        'mode' => 'connected',
        'status' => 'active',
        'base_url' => 'https://edu-admin.test',
    ]);

    $admin = AdminUser::query()->create([
        'name' => 'Ada Admin',
        'email' => 'ada-http@example.com',
        'password' => 'secret',
        'role' => 'super_admin',
        'is_active' => true,
    ]);

    Sanctum::actingAs($admin, ['*']);

    $this->postJson("/api/admin/v2/integration-connections/{$connection->id}/sync-initial")
        ->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Edu-admin connector access token is missing.');
});

it('scopes school admins to tenants mapped from their legacy school', function (): void {
    $allowedTenant = Tenant::query()->create([
        'name' => 'Allowed Tenant',
        'slug' => 'allowed-tenant',
        'status' => 'active',
    ]);

    $blockedTenant = Tenant::query()->create([
        'name' => 'Blocked Tenant',
        'slug' => 'blocked-tenant',
        'status' => 'active',
    ]);

    School::query()->create([
        'tenant_id' => $allowedTenant->id,
        'name' => 'Legacy Linked School',
        'slug' => 'legacy-linked-school',
        'status' => 'active',
        'source_system' => 'legacy',
        'source_id' => '55',
    ]);

    $allowedConnection = IntegrationConnection::query()->create([
        'tenant_id' => $allowedTenant->id,
        'provider' => 'edu_admin',
        'mode' => 'connected',
        'status' => 'active',
    ]);

    $blockedConnection = IntegrationConnection::query()->create([
        'tenant_id' => $blockedTenant->id,
        'provider' => 'edu_admin',
        'mode' => 'connected',
        'status' => 'active',
    ]);

    $admin = AdminUser::query()->create([
        'name' => 'School Admin',
        'email' => 'school-admin@example.com',
        'password' => 'secret',
        'role' => 'school_admin',
        'school_id' => 55,
        'is_active' => true,
    ]);

    Sanctum::actingAs($admin, ['*']);

    $listResponse = $this->getJson('/api/admin/v2/integration-connections');
    $listResponse->assertOk();
    expect(collect($listResponse->json('data'))->pluck('id')->all())->toBe([$allowedConnection->id]);

    $this->getJson("/api/admin/v2/integration-connections/{$allowedConnection->id}")->assertOk();
    $this->getJson("/api/admin/v2/integration-connections/{$blockedConnection->id}")->assertForbidden();
});

it('rejects inactive admin API users', function (): void {
    $admin = AdminUser::query()->create([
        'name' => 'Inactive Admin',
        'email' => 'inactive-admin@example.com',
        'password' => 'secret',
        'role' => 'super_admin',
        'is_active' => false,
    ]);

    Sanctum::actingAs($admin, ['*']);

    $this->getJson('/api/admin/v2/integration-connections')
        ->assertForbidden()
        ->assertJsonPath('message', 'This admin account is inactive.');
});

it('runs initial sync from the console command', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Command Tenant',
        'slug' => 'command-tenant',
        'status' => 'active',
    ]);

    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'edu_admin',
        'mode' => 'connected',
        'status' => 'active',
    ]);

    $this->artisan('educonnect:sync-initial', [
        'connection_id' => $connection->id,
        '--driver' => 'fixture',
    ])->assertExitCode(0);

    expect(IntegrationSyncRun::query()->count())->toBe(1);
    expect(IntegrationSyncRun::query()->first()->status)->toBe('completed');
    expect(Student::query()->count())->toBe(2);
    expect(MobileMessage::query()->count())->toBe(1);
});

it('runs incremental sync from the console command', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Command Tenant',
        'slug' => 'command-tenant',
        'status' => 'active',
    ]);

    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'edu_admin',
        'mode' => 'connected',
        'status' => 'active',
    ]);

    $this->artisan('educonnect:sync-initial', [
        'connection_id' => $connection->id,
        '--driver' => 'fixture',
    ])->assertExitCode(0);

    $this->artisan('educonnect:sync-incremental', [
        'connection_id' => $connection->id,
        '--driver' => 'fixture',
        '--updated-after' => '2026-08-07T08:00:00Z',
        '--resource' => ['mobile_messages'],
    ])->assertExitCode(0);

    expect(IntegrationSyncRun::query()->count())->toBe(2);
    expect(IntegrationSyncRun::query()->latest('id')->first()->sync_type)->toBe('incremental');
    expect(IntegrationSyncRun::query()->latest('id')->first()->records_read)->toBe(1);
});

it('accepts signed Edu-admin message webhooks and imports the message immediately', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Webhook Tenant',
        'slug' => 'webhook-tenant',
        'status' => 'active',
    ]);

    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'edu_admin',
        'mode' => 'connected',
        'status' => 'active',
        'remote_tenant_id' => '100',
        'webhook_secret' => Crypt::encryptString('webhook-shared-secret'),
    ]);

    $this->artisan('educonnect:sync-initial', [
        'connection_id' => $connection->id,
        '--driver' => 'fixture',
    ])->assertExitCode(0);

    $payload = [
        'event_type' => 'communication.message.sent',
        'complex_id' => 100,
        'message_id' => 90,
        'school_id' => 10,
        'status' => 'sent',
        'sent_at' => '2026-08-07T08:13:00Z',
        'updated_at' => '2026-08-07T08:13:00Z',
    ];

    $this->postJson('/api/integrations/v2/edu-admin/mobile-message-published', $payload)
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Missing Edu-admin webhook signature.');

    $this->postJson(
        '/api/integrations/v2/edu-admin/mobile-message-published',
        $payload,
        eduAdminWebhookHeaders($payload, 'webhook-shared-secret', signature: 'sha256=bad-signature'),
    )
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Invalid Edu-admin webhook signature.');

    $this->postJson(
        '/api/integrations/v2/edu-admin/mobile-message-published',
        $payload,
        eduAdminWebhookHeaders($payload, 'webhook-shared-secret'),
    )
        ->assertOk()
        ->assertJsonPath('data.connection_id', $connection->id)
        ->assertJsonPath('data.source_message_id', 90)
        ->assertJsonPath('data.sync_run.status', 'completed')
        ->assertJsonPath('data.sync_run.records_read', 1)
        ->assertJsonPath('data.published.messages', 1);

    $run = IntegrationSyncRun::query()->latest('id')->firstOrFail();

    expect($run->metadata['source'])->toBe('edu_admin_webhook');
    expect($run->metadata['source_message_id'])->toBe(90);
});

function eduAdminWebhookHeaders(
    array $payload,
    string $secret,
    ?int $timestamp = null,
    ?string $signature = null
): array {
    $timestamp ??= now()->timestamp;
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $signature ??= 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);

    return [
        'X-Edu-Admin-Timestamp' => (string) $timestamp,
        'X-Edu-Admin-Signature' => $signature,
        'X-Edu-Admin-Complex-Id' => (string) $payload['complex_id'],
    ];
}
