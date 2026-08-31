<?php

use App\Jobs\V2\Integration\RunEduAdminInitialSyncJob;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\IntegrationInboxEvent;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\Tenant;
use App\Services\Integration\EduAdminConnectorFactory;
use App\Services\Integration\SyncCoordinator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();

    config([
        'integrations.provisioning.edu_admin.secret' => str_repeat('p', 64),
        'integrations.provisioning.edu_admin.allowed_base_urls' => ['https://edu-admin-api.queevo.tech'],
        'integrations.providers.edu_admin.driver' => 'fixture',
        'integrations.providers.edu_admin.fixture_path' => base_path('tests/Fixtures/edu_admin_connector'),
    ]);
});

it('provisions an encrypted connection idempotently and queues its initial sync', function (): void {
    Queue::fake();
    $payload = [
        'action' => 'connect',
        'request_id' => '75db3ee6-697b-4fb9-a447-0a5f2fd5b0af',
        'edu_admin_base_url' => 'https://edu-admin-api.queevo.tech',
        'complex' => [
            'id' => 44,
            'name' => 'Queevo Learning Complex',
            'slug' => 'queevo-learning-complex',
            'status' => 'active',
        ],
        'school_ids' => [7, 8],
        'scopes' => ['foundation:read', 'messages:read', 'attendance:write'],
        'access_token' => 'ec_live_'.str_repeat('a', 64),
        'webhook_secret' => 'ecwhsec_'.str_repeat('b', 64),
    ];

    $response = signedJsonRequest(
        $this,
        '/api/integrations/v2/edu-admin/provision',
        $payload,
        provisioningHeaders($payload),
    );

    $response->assertAccepted()
        ->assertJsonPath('data.status', 'syncing');

    $connection = IntegrationConnection::query()->firstOrFail();
    expect(Tenant::query()->count())->toBe(1)
        ->and($connection->remote_tenant_id)->toBe('44')
        ->and($connection->enabled_school_ids)->toBe([7, 8])
        ->and(Crypt::decryptString($connection->encrypted_access_token))->toBe($payload['access_token'])
        ->and(Crypt::decryptString($connection->webhook_secret))->toBe($payload['webhook_secret'])
        ->and($connection->encrypted_access_token)->not->toContain($payload['access_token']);

    Queue::assertPushed(RunEduAdminInitialSyncJob::class, 1);

    signedJsonRequest(
        $this,
        '/api/integrations/v2/edu-admin/provision',
        $payload,
        provisioningHeaders($payload),
    )->assertAccepted();

    expect(IntegrationConnection::query()->count())->toBe(1)
        ->and(Tenant::query()->count())->toBe(1);
    Queue::assertPushed(RunEduAdminInitialSyncJob::class, 1);
});

it('pulls linking code changes immediately and ignores replayed events', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Fixture Tenant',
        'slug' => 'fixture-tenant',
        'status' => 'active',
    ]);
    $webhookSecret = 'ecwhsec_'.str_repeat('w', 64);
    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'edu_admin',
        'mode' => 'connected',
        'status' => 'active',
        'base_url' => 'https://edu-admin-api.queevo.tech',
        'remote_tenant_id' => '100',
        'encrypted_access_token' => Crypt::encryptString('ec_live_'.str_repeat('t', 64)),
        'webhook_secret' => Crypt::encryptString($webhookSecret),
    ]);

    app(SyncCoordinator::class)->runInitialSync(
        $connection,
        app(EduAdminConnectorFactory::class)->make($connection),
    );
    expect(ParentStudentLink::query()->where('source_id', '80')->value('linking_code'))->toBe('ABC12345');

    config(['integrations.providers.edu_admin.driver' => 'http']);
    $record = json_decode(file_get_contents(base_path('tests/Fixtures/edu_admin_connector/parent_links.json')), true)['data'][0];
    $record['linking_code'] = 'NEW54321';
    $record['updated_at'] = '2026-08-30T12:00:00Z';
    Http::fake([
        'https://edu-admin-api.queevo.tech/api/v1/integrations/edu-connect/resources/parent_links*' => Http::response([
            'data' => [$record],
            'next_cursor' => null,
            'has_more' => false,
        ]),
    ]);
    $payload = [
        'event_key' => 'ecsync_'.str_repeat('1', 64),
        'event_type' => 'edu_admin.resource_changed',
        'complex_id' => 100,
        'resource' => 'parent_links',
        'action' => 'updated',
        'resource_ids' => [80],
        'metadata' => ['reason' => 'linking_code_changed'],
        'occurred_at' => '2026-08-30T12:00:00Z',
    ];

    signedJsonRequest(
        $this,
        '/api/integrations/v2/edu-admin/resource-changed',
        $payload,
        resourceHeaders($payload, $webhookSecret),
    )
        ->assertOk()
        ->assertJsonPath('data.duplicate', false);

    expect(ParentStudentLink::query()->where('source_id', '80')->value('linking_code'))->toBe('NEW54321')
        ->and(IntegrationInboxEvent::query()->where('event_key', $payload['event_key'])->count())->toBe(1);

    signedJsonRequest(
        $this,
        '/api/integrations/v2/edu-admin/resource-changed',
        $payload,
        resourceHeaders($payload, $webhookSecret),
    )
        ->assertOk()
        ->assertJsonPath('data.duplicate', true);

    Http::assertSentCount(1);
});

function signedJsonRequest(Tests\TestCase $test, string $uri, array $payload, array $headers): \Illuminate\Testing\TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $test->call('POST', $uri, [], [], [], $server, $body);
}

function provisioningHeaders(array $payload): array
{
    $timestamp = (string) now()->timestamp;
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return [
        'Idempotency-Key' => $payload['request_id'],
        'X-Edu-Admin-Timestamp' => $timestamp,
        'X-Edu-Admin-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, str_repeat('p', 64)),
    ];
}

function resourceHeaders(array $payload, string $secret): array
{
    $timestamp = (string) now()->timestamp;
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return [
        'Idempotency-Key' => $payload['event_key'],
        'X-Edu-Admin-Complex-Id' => (string) $payload['complex_id'],
        'X-Edu-Admin-Timestamp' => $timestamp,
        'X-Edu-Admin-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ];
}
