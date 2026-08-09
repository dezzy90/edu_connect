<?php

use App\Models\V2\MobileNotification;
use App\Models\V2\MobilePushToken;
use App\Models\V2\NotificationDelivery;
use App\Models\V2\ParentAccount;
use App\Models\V2\School;
use App\Models\V2\Tenant;
use App\Services\Notifications\PushNotificationDispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();

    config([
        'educonnect.notifications.push_transport' => 'provider',
        'educonnect.notifications.push_timeout_seconds' => 3,
        'educonnect.notifications.push_max_attempts' => 3,
        'educonnect.notifications.push_retry_backoff_seconds' => 300,
        'educonnect.notifications.fcm.project_id' => 'edu-connect-test',
        'educonnect.notifications.fcm.access_token' => 'fcm-access-token',
        'educonnect.notifications.apns.environment' => 'sandbox',
        'educonnect.notifications.apns.bundle_id' => 'com.educonnect.mobile',
        'educonnect.notifications.apns.bearer_token' => 'apns-provider-token',
    ]);
});

it('sends queued FCM deliveries through the provider transport', function (): void {
    [$notification] = createPushTransportFixture('fcm', 'android', 'fcm-token-001');

    Http::fake([
        'https://fcm.googleapis.com/v1/projects/edu-connect-test/messages:send' => Http::response([
            'name' => 'projects/edu-connect-test/messages/fcm-message-001',
        ]),
    ]);

    $dispatcher = app(PushNotificationDispatcher::class);
    $queued = $dispatcher->enqueuePending();
    $sent = $dispatcher->dispatchQueued();

    expect($queued)->toMatchArray([
        'notifications' => 1,
        'deliveries_queued' => 1,
        'skipped' => 0,
    ]);
    expect($sent)->toMatchArray([
        'sent' => 1,
        'failed' => 0,
        'skipped' => 0,
    ]);

    $delivery = NotificationDelivery::query()->firstOrFail();

    expect($delivery->status)->toBe('sent')
        ->and($delivery->provider_message_id)->toBe('projects/edu-connect-test/messages/fcm-message-001')
        ->and($delivery->provider_response['name'])->toBe('projects/edu-connect-test/messages/fcm-message-001')
        ->and($notification->refresh()->delivery_status)->toBe('sent')
        ->and($notification->sent_at)->not->toBeNull();

    Http::assertSent(function (Request $request): bool {
        $payload = json_decode($request->body(), true);

        return $request->url() === 'https://fcm.googleapis.com/v1/projects/edu-connect-test/messages:send'
            && $request->hasHeader('Authorization', 'Bearer fcm-access-token')
            && $payload['message']['token'] === 'fcm-token-001'
            && $payload['message']['notification']['title'] === 'Arrived at school'
            && $payload['message']['data']['notification_id'] === '1'
            && $payload['message']['data']['student_id'] === '701'
            && $payload['message']['android']['priority'] === 'HIGH';
    });
});

it('revokes invalid FCM tokens and skips the delivery', function (): void {
    [, $pushToken] = createPushTransportFixture('fcm', 'android', 'invalid-fcm-token');

    Http::fake([
        'https://fcm.googleapis.com/v1/projects/edu-connect-test/messages:send' => Http::response([
            'error' => [
                'code' => 404,
                'message' => 'Requested entity was not found.',
                'status' => 'NOT_FOUND',
                'details' => [
                    [
                        '@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError',
                        'errorCode' => 'UNREGISTERED',
                    ],
                ],
            ],
        ], 404),
    ]);

    $dispatcher = app(PushNotificationDispatcher::class);
    $dispatcher->enqueuePending();
    $sent = $dispatcher->dispatchQueued();

    $delivery = NotificationDelivery::query()->firstOrFail();

    expect($sent)->toMatchArray([
        'sent' => 0,
        'failed' => 0,
        'skipped' => 1,
    ]);
    expect($delivery->status)->toBe('skipped')
        ->and($delivery->last_error)->toBe('Requested entity was not found.')
        ->and($delivery->provider_response['error']['status'])->toBe('NOT_FOUND')
        ->and($pushToken->refresh()->revoked_at)->not->toBeNull();
});

it('sends APNs deliveries through the provider transport', function (): void {
    [$notification] = createPushTransportFixture('apns', 'ios', 'apns-token-001');

    Http::fake([
        'https://api.sandbox.push.apple.com/3/device/apns-token-001' => Http::response('', 200, [
            'apns-id' => 'apns-message-001',
        ]),
    ]);

    $dispatcher = app(PushNotificationDispatcher::class);
    $dispatcher->enqueuePending();
    $sent = $dispatcher->dispatchQueued();

    $delivery = NotificationDelivery::query()->firstOrFail();

    expect($sent)->toMatchArray([
        'sent' => 1,
        'failed' => 0,
        'skipped' => 0,
    ]);
    expect($delivery->status)->toBe('sent')
        ->and($delivery->provider_message_id)->toBe('apns-message-001')
        ->and($notification->refresh()->delivery_status)->toBe('sent');

    Http::assertSent(function (Request $request): bool {
        $payload = json_decode($request->body(), true);

        return $request->url() === 'https://api.sandbox.push.apple.com/3/device/apns-token-001'
            && $request->hasHeader('Authorization', 'Bearer apns-provider-token')
            && $request->hasHeader('apns-topic', 'com.educonnect.mobile')
            && $request->hasHeader('apns-push-type', 'alert')
            && $payload['aps']['alert']['title'] === 'Arrived at school'
            && $payload['educonnect']['notification_id'] === 1
            && $payload['educonnect']['data']['student_id'] === 701;
    });
});

it('keeps transient provider failures retryable with backoff', function (): void {
    createPushTransportFixture('fcm', 'android', 'fcm-token-002');

    Http::fake([
        'https://fcm.googleapis.com/v1/projects/edu-connect-test/messages:send' => Http::response([
            'error' => [
                'code' => 503,
                'message' => 'Provider unavailable.',
                'status' => 'UNAVAILABLE',
            ],
        ], 503),
    ]);

    $dispatcher = app(PushNotificationDispatcher::class);
    $dispatcher->enqueuePending();
    $firstAttempt = $dispatcher->dispatchQueued();
    $secondAttempt = $dispatcher->dispatchQueued();

    $delivery = NotificationDelivery::query()->firstOrFail();

    expect($firstAttempt)->toMatchArray([
        'sent' => 0,
        'failed' => 1,
        'skipped' => 0,
    ]);
    expect($secondAttempt)->toMatchArray([
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0,
    ]);
    expect($delivery->status)->toBe('failed')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->next_attempt_at)->not->toBeNull()
        ->and($delivery->last_error)->toBe('Provider unavailable.');
});

function createPushTransportFixture(string $provider, string $platform, string $token): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Push Transport Tenant',
        'slug' => 'push-transport-tenant',
        'status' => 'active',
    ]);

    $school = School::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Demo School',
        'slug' => 'demo-school',
        'status' => 'active',
        'timezone' => 'Africa/Douala',
    ]);

    $parent = ParentAccount::query()->create([
        'phone' => '650000001',
        'email' => 'push-parent@example.com',
        'first_name' => 'Amina',
        'last_name' => 'Talla',
        'preferred_language' => 'en',
        'status' => 'active',
        'password_hash' => Hash::make('password-secret'),
    ]);

    $pushToken = MobilePushToken::query()->create([
        'parent_account_id' => $parent->id,
        'provider' => $provider,
        'platform' => $platform,
        'token' => $token,
        'last_seen_at' => now(),
    ]);

    $notification = MobileNotification::query()->create([
        'parent_account_id' => $parent->id,
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'type' => 'attendance',
        'title' => 'Arrived at school',
        'body' => 'Your child arrived at school.',
        'data' => [
            'student_id' => 701,
            'event_type' => 'check_in',
        ],
        'priority' => 'high',
        'channel' => 'in_app_push',
        'delivery_status' => 'queued',
    ]);

    return [$notification, $pushToken, $parent, $school, $tenant];
}
