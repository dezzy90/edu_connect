<?php

use App\Models\V2\AcademicClass;
use App\Models\V2\AttendanceEvent;
use App\Models\V2\BiometricDevice;
use App\Models\V2\MobileMessage;
use App\Models\V2\MobileMessageRecipient;
use App\Models\V2\MobileNotification;
use App\Models\V2\NotificationPreference;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\School;
use App\Models\V2\Section;
use App\Models\V2\Stream;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use Illuminate\Support\Facades\Hash;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();

    config([
        'educonnect.notifications.privacy_mode' => 'detailed',
    ]);
});

it('creates parent mobile notifications when attendance events are captured', function (): void {
    [$parent, $student, $school, $device] = createMobileContentGraph();

    $event = AttendanceEvent::query()->create([
        'tenant_id' => $student->tenant_id,
        'school_id' => $school->id,
        'student_id' => $student->id,
        'device_id' => $device->id,
        'event_key' => 'attendance-content-001',
        'event_type' => 'check_in',
        'event_time' => '2026-09-15 07:20:00',
        'confidence_score' => 95.5,
        'processing_status' => 'processed',
        'edu_admin_sync_status' => 'pending',
    ]);

    $notification = MobileNotification::query()->firstOrFail();

    expect($notification->parent_account_id)->toBe($parent->id);
    expect($notification->type)->toBe('attendance');
    expect($notification->title)->toBe('Desmond Mbah arrived at school');
    expect($notification->channel)->toBe('in_app_push');
    expect($notification->data['attendance_event_id'])->toBe($event->id);
    expect($notification->data['device_name'])->toBe('Main Gate');
});

it('honors parent notification preferences for attendance hooks', function (): void {
    [$parent, $student, $school, $device] = createMobileContentGraph();

    NotificationPreference::query()->create([
        'parent_account_id' => $parent->id,
        'category' => 'attendance',
        'in_app_enabled' => false,
        'push_enabled' => false,
    ]);

    AttendanceEvent::query()->create([
        'tenant_id' => $student->tenant_id,
        'school_id' => $school->id,
        'student_id' => $student->id,
        'device_id' => $device->id,
        'event_key' => 'attendance-content-002',
        'event_type' => 'check_out',
        'event_time' => '2026-09-15 15:20:00',
        'processing_status' => 'processed',
        'edu_admin_sync_status' => 'pending',
    ]);

    expect(MobileNotification::query()->count())->toBe(0);
});

it('publishes official mobile messages to linked parents and exposes the mobile inbox', function (): void {
    [$parent, $student, $school] = createMobileContentGraph();
    $token = mobileContentApiTokenFor($this, $parent);

    $message = MobileMessage::query()->create([
        'tenant_id' => $student->tenant_id,
        'school_id' => $school->id,
        'sender_type' => 'school_admin',
        'sender_name' => 'Demo School',
        'category' => 'general',
        'priority' => 'normal',
        'title' => 'PTA meeting',
        'body' => 'The PTA meeting starts at 10:00 tomorrow.',
        'audience_type' => 'parents',
        'status' => 'published',
        'published_at' => now(),
    ]);

    $recipient = MobileMessageRecipient::query()->firstOrFail();

    expect($recipient->message_id)->toBe($message->id);
    expect($recipient->parent_account_id)->toBe($parent->id);
    expect($recipient->student_id)->toBeNull();
    expect(MobileNotification::query()->where('type', 'messages')->count())->toBe(1);

    $listResponse = $this->withToken($token)->getJson('/api/mobile/v2/messages');

    $listResponse->assertOk();
    expect($listResponse->json('data.unread_count'))->toBe(1);
    expect($listResponse->json('data.items.0.message.id'))->toBe($message->id);
    expect($listResponse->json('data.items.0.message.title'))->toBe('PTA meeting');

    $showResponse = $this->withToken($token)->getJson("/api/mobile/v2/messages/{$message->id}");

    $showResponse->assertOk();
    expect($showResponse->json('data.message.body'))->toBe('The PTA meeting starts at 10:00 tomorrow.');
    expect($showResponse->json('data.recipients.0.id'))->toBe($recipient->id);

    $this->withToken($token)
        ->postJson("/api/mobile/v2/messages/{$message->id}/read")
        ->assertOk()
        ->assertJsonPath('data.marked_read', 1);

    expect($recipient->refresh()->read_at)->not->toBeNull();
    expect($recipient->delivery_status)->toBe('read');
});

it('does not expose staff-only mobile messages to parent recipients', function (): void {
    [$parent, $student, $school] = createMobileContentGraph();
    $token = mobileContentApiTokenFor($this, $parent);

    $message = MobileMessage::withoutEvents(fn () => MobileMessage::query()->create([
        'tenant_id' => $student->tenant_id,
        'school_id' => $school->id,
        'sender_type' => 'school_admin',
        'sender_name' => 'Demo School',
        'category' => 'general',
        'priority' => 'normal',
        'title' => 'Staff notice',
        'body' => 'Internal staff planning note.',
        'audience_type' => 'parents',
        'status' => 'published',
        'published_at' => now(),
    ]));

    $this->artisan('educonnect:publish-mobile-messages', [
        '--limit' => 10,
    ])->assertExitCode(0);

    expect(MobileMessageRecipient::query()->where('message_id', $message->id)->count())->toBe(0);

    $leakedRecipient = MobileMessageRecipient::query()->create([
        'message_id' => $message->id,
        'parent_account_id' => $parent->id,
        'student_id' => null,
        'recipient_phone' => $parent->phone,
        'delivery_status' => 'queued',
    ]);

    MobileNotification::query()->create([
        'parent_account_id' => $parent->id,
        'tenant_id' => $student->tenant_id,
        'school_id' => $school->id,
        'type' => 'messages',
        'title' => $message->title,
        'body' => 'You have a new school message.',
        'data' => ['mobile_message_id' => $message->id],
        'priority' => 'normal',
        'channel' => 'in_app_push',
        'delivery_status' => 'queued',
    ]);

    $this->withToken($token)
        ->getJson('/api/mobile/v2/messages')
        ->assertOk()
        ->assertJsonMissing(['title' => 'Staff notice']);

    $this->withToken($token)
        ->getJson("/api/mobile/v2/messages/{$message->id}")
        ->assertNotFound();

    expect($leakedRecipient->exists)->toBeTrue();

    $message->forceFill(['body' => 'Internal staff planning note updated.'])->save();

    expect(MobileMessageRecipient::query()->where('message_id', $message->id)->count())->toBe(0);
    expect(MobileNotification::query()->where('data->mobile_message_id', $message->id)->count())->toBe(0);
});

it('publishes due messages from the console command without duplicate recipients', function (): void {
    [$parent, $student, $school] = createMobileContentGraph();

    $message = MobileMessage::withoutEvents(fn () => MobileMessage::query()->create([
        'tenant_id' => $student->tenant_id,
        'school_id' => $school->id,
        'sender_type' => 'system',
        'sender_name' => 'Edu-connect',
        'category' => 'general',
        'priority' => 'normal',
        'title' => 'Holiday reminder',
        'body' => 'School is closed on Friday.',
        'audience_type' => 'students',
        'audience_filters' => ['student_ids' => [$student->id]],
        'status' => 'published',
        'published_at' => now()->subMinute(),
    ]));

    $this->artisan('educonnect:publish-mobile-messages', [
        '--limit' => 10,
    ])->assertExitCode(0);

    $this->artisan('educonnect:publish-mobile-messages', [
        '--limit' => 10,
    ])->assertExitCode(0);

    expect(MobileMessageRecipient::query()->count())->toBe(1);
    expect(MobileNotification::query()->where('type', 'messages')->count())->toBe(1);

    $recipient = MobileMessageRecipient::query()->firstOrFail();
    expect($recipient->message_id)->toBe($message->id);
    expect($recipient->parent_account_id)->toBe($parent->id);
    expect($recipient->student_id)->toBe($student->id);
});

function createMobileContentGraph(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Content Tenant',
        'slug' => 'content-tenant',
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
        'email' => 'content-parent@example.com',
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
        'parent_account_id' => $parent->id,
        'parent_phone' => $parent->phone,
        'linking_code' => 'LINK-001',
        'relationship' => 'mother',
        'is_primary_contact' => true,
        'can_pick_up' => true,
        'status' => 'active',
        'verified_at' => now(),
        'linked_at' => now(),
    ]);

    $device = BiometricDevice::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'name' => 'Main Gate',
        'device_uid' => 'device-main-gate',
        'location' => 'Front entrance',
        'status' => 'active',
    ]);

    return [$parent, $student, $school, $device];
}

function mobileContentApiTokenFor(Tests\TestCase $testCase, ParentAccount $parent): string
{
    $loginResponse = $testCase->postJson('/api/mobile/v2/auth/login', [
        'phone' => $parent->phone,
        'password' => 'password-secret',
    ]);

    return $loginResponse->assertOk()->json('data.access_token');
}
