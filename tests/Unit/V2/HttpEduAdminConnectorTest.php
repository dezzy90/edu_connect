<?php

use App\Models\V2\IntegrationConnection;
use App\Models\V2\Tenant;
use App\Services\Integration\Connectors\HttpEduAdminConnector;
use App\Services\Integration\EduAdminConnectorFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();

    config([
        'integrations.providers.edu_admin.driver' => 'http',
        'integrations.sync.default_batch_size' => 125,
    ]);
});

it('calls Edu-admin connector endpoints with the stored bearer token', function (): void {
    $connection = createHttpConnectorConnection();

    Http::fake([
        'https://edu-admin.test/api/v1/integrations/edu-connect/bootstrap' => Http::response([
            'complex' => ['id' => 100, 'name' => 'Demo Complex'],
            'features' => ['attendance' => true],
            'server_time' => '2026-08-07T08:00:00+00:00',
        ]),
        'https://edu-admin.test/api/v1/integrations/edu-connect/resources/schools*' => Http::response([
            'data' => [
                ['id' => 10, 'name' => 'Legacy Linked School'],
            ],
            'next_cursor' => null,
            'has_more' => false,
        ]),
        'https://edu-admin.test/api/v1/integrations/edu-connect/attendance-events' => Http::response([
            'accepted' => ['attendance-event-001'],
            'duplicates' => [],
            'rejected' => [],
        ]),
    ]);

    $connector = new HttpEduAdminConnector($connection);

    expect($connector->bootstrap()['complex']['id'])->toBe(100);
    expect($connector->resource('schools', '10', ['updated_after' => '2026-08-07T08:00:00Z'])['data'][0]['id'])->toBe(10);
    $attendanceEvents = [
        [
            'event_key' => 'attendance-event-001',
            'school_id' => 10,
            'student_id' => 70,
            'device_uid' => 'device-main-gate',
            'event_type' => 'check_in',
            'event_time' => '2026-09-15T07:20:00+01:00',
        ],
    ];

    expect($connector->pushAttendanceEvents($attendanceEvents)['accepted'][0])->toBe('attendance-event-001');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://edu-admin.test/api/v1/integrations/edu-connect/bootstrap'
        && $request->hasHeader('Authorization', 'Bearer connector-secret'));

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://edu-admin.test/api/v1/integrations/edu-connect/resources/schools')
        && str_contains($request->url(), 'cursor=10')
        && str_contains($request->url(), 'limit=125')
        && str_contains($request->url(), 'updated_after=2026-08-07T08%3A00%3A00Z')
        && $request->hasHeader('Authorization', 'Bearer connector-secret'));

    Http::assertSent(function (Request $request): bool {
        $timestamp = $request->header('X-Edu-Connect-Timestamp')[0] ?? null;
        $signature = $request->header('X-Edu-Connect-Signature')[0] ?? null;
        $body = $request->body();
        $payload = json_decode($body, true);
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, 'connector-webhook-secret');

        return $request->method() === 'POST'
            && $request->url() === 'https://edu-admin.test/api/v1/integrations/edu-connect/attendance-events'
            && $request->hasHeader('Authorization', 'Bearer connector-secret')
            && $request->hasHeader('Idempotency-Key')
            && $timestamp !== null
            && $signature === $expectedSignature
            && $payload['events'][0]['event_key'] === 'attendance-event-001';
    });
});

it('is selected by the connector factory when the http driver is configured', function (): void {
    $connection = createHttpConnectorConnection();

    expect(app(EduAdminConnectorFactory::class)->make($connection))
        ->toBeInstanceOf(HttpEduAdminConnector::class);
});

it('throws clear errors for missing credentials and failed responses', function (): void {
    $connection = createHttpConnectorConnection([
        'encrypted_access_token' => null,
    ]);

    expect(fn () => (new HttpEduAdminConnector($connection))->bootstrap())
        ->toThrow(RuntimeException::class, 'access token is missing');

    $connection->forceFill([
        'encrypted_access_token' => Crypt::encryptString('connector-secret'),
    ])->save();

    Http::fake([
        '*' => Http::response(['message' => 'Nope'], 401),
    ]);

    expect(fn () => (new HttpEduAdminConnector($connection->refresh()))->bootstrap())
        ->toThrow(RuntimeException::class, 'status 401');
});

it('requires a webhook secret before signed write requests are sent', function (): void {
    $connection = createHttpConnectorConnection([
        'webhook_secret' => null,
    ]);

    expect(fn () => (new HttpEduAdminConnector($connection))->pushAttendanceEvents([
        [
            'event_key' => 'attendance-event-001',
        ],
    ]))->toThrow(RuntimeException::class, 'webhook secret is missing');
});

function createHttpConnectorConnection(array $overrides = []): IntegrationConnection
{
    $tenant = Tenant::query()->create([
        'name' => 'Local Demo',
        'slug' => 'local-demo',
        'status' => 'active',
    ]);

    return IntegrationConnection::query()->create(array_merge([
        'tenant_id' => $tenant->id,
        'provider' => 'edu_admin',
        'mode' => 'connected',
        'base_url' => 'https://edu-admin.test',
        'status' => 'active',
        'encrypted_access_token' => Crypt::encryptString('connector-secret'),
        'webhook_secret' => Crypt::encryptString('connector-webhook-secret'),
    ], $overrides));
}
