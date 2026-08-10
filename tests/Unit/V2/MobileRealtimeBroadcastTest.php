<?php

use App\Events\V2\MobileRealtimeEvent;
use App\Models\V2\AcademicClass;
use App\Models\V2\AttendanceEvent;
use App\Models\V2\BiometricDevice;
use App\Models\V2\ConversationMessage;
use App\Models\V2\ConversationThread;
use App\Models\V2\MobileMessage;
use App\Models\V2\MobileNotification;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\School;
use App\Models\V2\Section;
use App\Models\V2\Stream;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use App\Services\Conversations\ConversationService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();

    config([
        'educonnect.notifications.privacy_mode' => 'detailed',
        'educonnect.realtime.enabled' => true,
        'educonnect.realtime.app_key' => 'local-key',
        'educonnect.realtime.app_secret' => 'local-secret',
    ]);
});

it('broadcasts newly created mobile notifications to parent-owned channels only', function (): void {
    [$parent, $student, $school] = createBroadcastGraph();
    Event::fake([MobileRealtimeEvent::class]);

    $notification = MobileNotification::query()->create([
        'parent_account_id' => $parent->id,
        'tenant_id' => $student->tenant_id,
        'school_id' => $school->id,
        'type' => 'attendance',
        'title' => 'Attendance update',
        'body' => 'A student arrived.',
        'data' => ['student_id' => $student->id],
        'priority' => 'normal',
        'channel' => 'in_app_push',
        'delivery_status' => 'queued',
    ]);

    Event::assertDispatched(MobileRealtimeEvent::class, function (MobileRealtimeEvent $event) use ($parent, $student, $school, $notification): bool {
        $channels = broadcastEventChannels($event);

        return $event->eventName === 'mobile.notification.created'
            && $event->payload['notification_id'] === $notification->id
            && in_array("private-parent.{$parent->id}", $channels, true)
            && in_array("private-parent.{$parent->id}.notifications", $channels, true)
            && in_array("private-parent.{$parent->id}.student.{$student->id}", $channels, true)
            && in_array("private-school.{$school->id}.parent.{$parent->id}", $channels, true)
            && ! in_array("private-school.{$school->id}.parents", $channels, true);
    });
});

it('broadcasts attendance updates without leaking them to class or school-wide parent channels', function (): void {
    [$parent, $student, $school, $class, $device] = createBroadcastGraph();
    Event::fake([MobileRealtimeEvent::class]);

    $event = AttendanceEvent::query()->create([
        'tenant_id' => $student->tenant_id,
        'school_id' => $school->id,
        'student_id' => $student->id,
        'device_id' => $device->id,
        'event_key' => 'broadcast-attendance-001',
        'event_type' => 'check_in',
        'event_time' => '2026-09-15 07:20:00',
        'processing_status' => 'processed',
        'edu_admin_sync_status' => 'pending',
    ]);

    Event::assertDispatched(MobileRealtimeEvent::class, function (MobileRealtimeEvent $broadcast) use ($parent, $student, $school, $class, $event): bool {
        $channels = broadcastEventChannels($broadcast);

        return $broadcast->eventName === 'mobile.attendance.recorded'
            && $broadcast->payload['attendance_event_id'] === $event->id
            && in_array("private-parent.{$parent->id}", $channels, true)
            && in_array("private-parent.{$parent->id}.student.{$student->id}", $channels, true)
            && in_array("private-school.{$school->id}.parent.{$parent->id}", $channels, true)
            && ! in_array("private-school.{$school->id}.parents", $channels, true)
            && ! in_array("private-school.{$school->id}.class.{$class->id}.parents", $channels, true);
    });
});

it('broadcasts official school notices as conversation messages', function (): void {
    [$parent, $student, $school] = createBroadcastGraph();
    Event::fake([MobileRealtimeEvent::class]);

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

    Event::assertDispatched(MobileRealtimeEvent::class, function (MobileRealtimeEvent $event) use ($school, $message): bool {
        $channels = broadcastEventChannels($event);

        return $event->eventName === 'mobile.conversation.message.created'
            && $event->payload['thread']['type'] === ConversationThread::TYPE_SCHOOL_CHANNEL
            && $event->payload['thread']['school_id'] === $school->id
            && $event->payload['message']['sender_type'] === ConversationMessage::SENDER_SYSTEM
            && str_contains($event->payload['message']['body'], $message->title)
            && in_array("private-conversation.{$event->payload['thread']['id']}", $channels, true)
            && in_array("private-school.{$school->id}.channels", $channels, true);
    });
});

it('broadcasts child linking changes with the automatic class group and school channel references', function (): void {
    [$parent, $student, $school, $class] = createBroadcastGraph(activeLink: false);
    $token = broadcastParentToken($parent);
    app('auth')->forgetGuards();

    Event::fake([MobileRealtimeEvent::class]);

    $this->withToken($token)
        ->postJson('/api/mobile/v2/children/link', [
            'linking_code' => 'BROADCAST-LINK',
            'student_number' => $student->student_number,
        ])
        ->assertCreated();

    Event::assertDispatched(MobileRealtimeEvent::class, function (MobileRealtimeEvent $event) use ($parent, $student, $school, $class): bool {
        $channels = broadcastEventChannels($event);
        $threadTypes = collect($event->payload['threads'])->pluck('type')->all();

        return $event->eventName === 'mobile.child.linked'
            && $event->payload['student_id'] === $student->id
            && $event->payload['school_id'] === $school->id
            && $event->payload['class_id'] === $class->id
            && in_array(ConversationThread::TYPE_CLASS_GROUP, $threadTypes, true)
            && in_array(ConversationThread::TYPE_SCHOOL_CHANNEL, $threadTypes, true)
            && in_array("private-parent.{$parent->id}", $channels, true)
            && in_array("private-parent.{$parent->id}.children", $channels, true)
            && in_array("private-parent.{$parent->id}.student.{$student->id}", $channels, true);
    });
});

it('broadcasts conversation messages to the conversation and group channels', function (): void {
    [$parent, $student, $school, $class] = createBroadcastGraph();
    $conversations = app(ConversationService::class);
    $threads = $conversations->ensureDefaultThreadsForParent($parent);
    $classGroup = $threads->firstWhere('type', ConversationThread::TYPE_CLASS_GROUP);

    Event::fake([MobileRealtimeEvent::class]);

    $message = $conversations->postParentMessage(
        $parent,
        $classGroup,
        'Please what time is the PTA meeting?'
    );

    Event::assertDispatched(MobileRealtimeEvent::class, function (MobileRealtimeEvent $event) use ($message, $classGroup, $school, $class): bool {
        $channels = broadcastEventChannels($event);

        return $event->eventName === 'mobile.conversation.message.created'
            && $event->payload['message']['id'] === $message->id
            && $event->payload['thread']['id'] === $classGroup->id
            && in_array("private-conversation.{$classGroup->id}", $channels, true)
            && in_array("private-school.{$school->id}.admins.conversations", $channels, true)
            && in_array("private-school.{$school->id}.class.{$class->id}.parents", $channels, true);
    });
});

function createBroadcastGraph(bool $activeLink = true): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Broadcast Tenant',
        'slug' => 'broadcast-tenant',
        'status' => 'active',
    ]);

    $school = School::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Broadcast School',
        'slug' => 'broadcast-school',
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
        'student_number' => 'BROADCAST-0001',
        'first_name' => 'Amina',
        'last_name' => 'Student',
        'status' => 'active',
        'mobile_visible' => true,
    ]);

    $parent = ParentAccount::query()->create([
        'phone' => '650001001',
        'email' => 'broadcast-parent@example.com',
        'first_name' => 'Nadine',
        'last_name' => 'Parent',
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
        'linking_code' => 'BROADCAST-LINK',
        'relationship' => 'parent',
        'is_primary_contact' => true,
        'can_pick_up' => true,
        'status' => $activeLink ? 'active' : 'pending',
        'verified_at' => $activeLink ? now() : null,
        'linked_at' => $activeLink ? now() : null,
    ]);

    $device = BiometricDevice::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'name' => 'Main Gate',
        'device_uid' => 'broadcast-main-gate',
        'location' => 'Front entrance',
        'status' => 'active',
    ]);

    return [$parent, $student, $school, $class, $device];
}

function broadcastParentToken(ParentAccount $parent): string
{
    return test()->postJson('/api/mobile/v2/auth/login', [
        'phone' => $parent->phone,
        'password' => 'password-secret',
    ])->assertOk()->json('data.access_token');
}

function broadcastEventChannels(MobileRealtimeEvent $event): array
{
    return collect($event->broadcastOn())
        ->map(fn ($channel): string => $channel->name)
        ->values()
        ->all();
}
