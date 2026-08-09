<?php

use App\Models\V2\AcademicClass;
use App\Models\V2\MobileNotification;
use App\Models\V2\MobilePushToken;
use App\Models\V2\NotificationDelivery;
use App\Models\V2\NotificationPreference;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\School;
use App\Models\V2\Section;
use App\Models\V2\Stream;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use App\Services\Notifications\PushNotificationDispatcher;
use Illuminate\Support\Facades\Hash;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();

    config([
        'educonnect.notifications.push_transport' => 'log',
        'educonnect.realtime.enabled' => true,
        'educonnect.realtime.driver' => 'reverb',
        'educonnect.realtime.app_key' => 'local-key',
        'educonnect.realtime.app_secret' => 'local-secret',
    ]);
});

it('lists and marks parent mobile notifications as read', function (): void {
    [$parent, $student, $school] = createRealtimeNotificationGraph();
    $token = mobileRealtimeApiTokenFor($this, $parent);

    $notification = MobileNotification::query()->create([
        'parent_account_id' => $parent->id,
        'tenant_id' => $student->tenant_id,
        'school_id' => $school->id,
        'type' => 'attendance',
        'title' => 'Arrived at school',
        'body' => 'Desmond checked in at the main gate.',
        'data' => ['student_id' => $student->id],
        'priority' => 'normal',
        'channel' => 'in_app_push',
        'delivery_status' => 'queued',
    ]);

    $otherParent = ParentAccount::query()->create([
        'phone' => '650000999',
        'email' => 'other-notify@example.com',
        'first_name' => 'Other',
        'last_name' => 'Parent',
        'preferred_language' => 'en',
        'status' => 'active',
        'password_hash' => Hash::make('password-secret'),
    ]);

    MobileNotification::query()->create([
        'parent_account_id' => $otherParent->id,
        'tenant_id' => $student->tenant_id,
        'school_id' => $school->id,
        'type' => 'attendance',
        'title' => 'Other child',
        'body' => 'This should not leak.',
        'priority' => 'normal',
        'channel' => 'in_app',
        'delivery_status' => 'queued',
    ]);

    $listResponse = $this->withToken($token)->getJson('/api/mobile/v2/notifications');

    $listResponse->assertOk();
    expect($listResponse->json('data.unread_count'))->toBe(1);
    expect($listResponse->json('data.items'))->toHaveCount(1);
    expect($listResponse->json('data.items.0.id'))->toBe($notification->id);

    $this->withToken($token)
        ->postJson("/api/mobile/v2/notifications/{$notification->id}/read")
        ->assertOk()
        ->assertJsonPath('data.read_at', fn ($value) => filled($value));

    expect($notification->refresh()->read_at)->not->toBeNull();

    MobileNotification::query()->create([
        'parent_account_id' => $parent->id,
        'tenant_id' => $student->tenant_id,
        'school_id' => $school->id,
        'type' => 'messages',
        'title' => 'PTA reminder',
        'body' => 'Meeting tomorrow.',
        'priority' => 'normal',
        'channel' => 'in_app',
        'delivery_status' => 'queued',
    ]);

    $this->withToken($token)
        ->postJson('/api/mobile/v2/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('data.marked_read', 1);
});

it('updates notification preferences and uses them when queueing push deliveries', function (): void {
    [$parent, $student, $school] = createRealtimeNotificationGraph();
    $token = mobileRealtimeApiTokenFor($this, $parent);

    $this->withToken($token)
        ->putJson('/api/mobile/v2/notification-preferences', [
            'preferences' => [
                [
                    'category' => 'attendance',
                    'push_enabled' => false,
                    'in_app_enabled' => true,
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.0.category', 'attendance')
        ->assertJsonPath('data.0.push_enabled', false);

    expect(NotificationPreference::query()->where('category', 'attendance')->value('push_enabled'))->toBeFalse();

    MobilePushToken::query()->create([
        'parent_account_id' => $parent->id,
        'provider' => 'fcm',
        'platform' => 'android',
        'token' => 'push-token-disabled',
        'last_seen_at' => now(),
    ]);

    MobileNotification::query()->create([
        'parent_account_id' => $parent->id,
        'tenant_id' => $student->tenant_id,
        'school_id' => $school->id,
        'type' => 'attendance',
        'title' => 'Arrived at school',
        'body' => 'This should remain in-app only by preference.',
        'priority' => 'normal',
        'channel' => 'in_app_push',
        'delivery_status' => 'queued',
    ]);

    $queued = app(PushNotificationDispatcher::class)->enqueuePending();

    expect($queued)->toMatchArray([
        'notifications' => 1,
        'deliveries_queued' => 0,
        'skipped' => 1,
    ]);
    expect(NotificationDelivery::query()->count())->toBe(0);
});

it('authorizes only channels owned by the authenticated parent', function (): void {
    [$parent, $student, $school] = createRealtimeNotificationGraph(activeLink: true);
    $token = mobileRealtimeApiTokenFor($this, $parent);

    $configResponse = $this->withToken($token)->getJson('/api/mobile/v2/realtime/config');

    $configResponse->assertOk();
    expect($configResponse->json('data.channels'))->toContain(
        "private-parent.{$parent->id}.notifications",
        "private-parent.{$parent->id}.student.{$student->id}",
        "private-school.{$school->id}.parent.{$parent->id}",
    );

    $authResponse = $this->withToken($token)->postJson('/api/mobile/v2/realtime/auth', [
        'socket_id' => '123.456',
        'channel_name' => "private-parent.{$parent->id}.student.{$student->id}",
        'metadata' => ['platform' => 'android'],
    ]);

    $authResponse->assertOk();
    expect($authResponse->json('data.authorized'))->toBeTrue();
    expect($authResponse->json('data.auth'))->toBe('local-key:' . hash_hmac(
        'sha256',
        "123.456:private-parent.{$parent->id}.student.{$student->id}",
        'local-secret',
    ));

    $this->assertDatabaseHas('ec_realtime_subscriptions', [
        'parent_account_id' => $parent->id,
        'socket_id' => '123.456',
        'channel_name' => "private-parent.{$parent->id}.student.{$student->id}",
    ]);

    $this->withToken($token)
        ->postJson('/api/mobile/v2/realtime/heartbeat', [
            'socket_id' => '123.456',
            'channel_name' => "private-parent.{$parent->id}.student.{$student->id}",
        ])
        ->assertOk()
        ->assertJsonPath('data.updated', true);

    $this->withToken($token)
        ->postJson('/api/mobile/v2/realtime/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-parent.999.notifications',
        ])
        ->assertForbidden();
});

it('creates and dispatches push delivery rows from the console command', function (): void {
    [$parent, $student, $school] = createRealtimeNotificationGraph(activeLink: true);

    MobilePushToken::query()->create([
        'parent_account_id' => $parent->id,
        'provider' => 'fcm',
        'platform' => 'android',
        'token' => 'push-token-001',
        'last_seen_at' => now(),
    ]);

    $notification = MobileNotification::query()->create([
        'parent_account_id' => $parent->id,
        'tenant_id' => $student->tenant_id,
        'school_id' => $school->id,
        'type' => 'attendance',
        'title' => 'Arrived at school',
        'body' => 'Desmond checked in at the main gate.',
        'data' => ['student_id' => $student->id],
        'priority' => 'normal',
        'channel' => 'in_app_push',
        'delivery_status' => 'queued',
    ]);

    $this->artisan('educonnect:dispatch-push-notifications', [
        '--limit' => 10,
    ])->assertExitCode(0);

    $delivery = NotificationDelivery::query()->firstOrFail();

    expect($delivery->notification_id)->toBe($notification->id);
    expect($delivery->status)->toBe('sent');
    expect($delivery->provider_message_id)->toBe('log-' . $delivery->id);
    expect($notification->refresh()->delivery_status)->toBe('sent');
    expect($notification->sent_at)->not->toBeNull();
});

function createRealtimeNotificationGraph(bool $activeLink = false): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Realtime Tenant',
        'slug' => 'realtime-tenant',
        'status' => 'active',
    ]);

    $school = School::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Demo School',
        'slug' => 'demo-school',
        'status' => 'active',
        'timezone' => 'Africa/Douala',
    ]);

    $section = Section::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'name' => 'Anglophone',
        'code' => 'EN',
        'status' => 'active',
    ]);

    $stream = Stream::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'section_id' => $section->id,
        'name' => 'Form 1',
        'display_name' => 'Form 1',
        'status' => 'active',
    ]);

    $class = AcademicClass::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'stream_id' => $stream->id,
        'name' => 'A',
        'full_name' => 'Form 1A',
        'status' => 'active',
    ]);

    $student = Student::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'class_id' => $class->id,
        'student_number' => 'DEMO-0001',
        'first_name' => 'Desmond',
        'last_name' => 'Mbah',
        'status' => 'active',
        'mobile_visible' => true,
    ]);

    $parent = ParentAccount::query()->create([
        'phone' => '650000001',
        'email' => 'realtime-parent@example.com',
        'first_name' => 'Amina',
        'last_name' => 'Talla',
        'preferred_language' => 'en',
        'status' => 'active',
        'password_hash' => Hash::make('password-secret'),
    ]);

    ParentStudentLink::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'student_id' => $student->id,
        'parent_account_id' => $activeLink ? $parent->id : null,
        'parent_phone' => $parent->phone,
        'linking_code' => 'LINK-001',
        'relationship' => 'mother',
        'is_primary_contact' => true,
        'can_pick_up' => true,
        'status' => $activeLink ? 'active' : 'pending',
        'verified_at' => $activeLink ? now() : null,
        'linked_at' => $activeLink ? now() : null,
    ]);

    return [$parent, $student, $school];
}

function mobileRealtimeApiTokenFor(Tests\TestCase $testCase, ParentAccount $parent): string
{
    $loginResponse = $testCase->postJson('/api/mobile/v2/auth/login', [
        'phone' => $parent->phone,
        'password' => 'password-secret',
    ]);

    return $loginResponse->assertOk()->json('data.access_token');
}
