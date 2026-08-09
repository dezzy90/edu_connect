<?php

use App\Models\AdminUser;
use App\Models\V2\AcademicClass;
use App\Models\V2\AttendanceEvent;
use App\Models\V2\BiometricDevice;
use App\Models\V2\ConversationMessage;
use App\Models\V2\ConversationThread;
use App\Models\V2\EducationOption;
use App\Models\V2\IntegrationAuditEvent;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\IntegrationOutboxEvent;
use App\Models\V2\IntegrationSyncRun;
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
});

it('returns dashboard data scoped to the authenticated school admin', function (): void {
    [$visibleTenant, $visibleSchool, $visibleClass, $visibleStudent, $visibleDevice] = createDashboardSchoolGraph('Visible', '10');
    [$hiddenTenant, $hiddenSchool] = createDashboardSchoolGraph('Hidden', '20');

    $parent = ParentAccount::query()->create([
        'phone' => '+237650000111',
        'email' => 'parent@example.com',
        'first_name' => 'Parent',
        'last_name' => 'One',
        'status' => 'active',
        'password_hash' => Hash::make('password-secret'),
    ]);

    ParentStudentLink::query()->create([
        'tenant_id' => $visibleTenant->id,
        'school_id' => $visibleSchool->id,
        'parent_account_id' => $parent->id,
        'student_id' => $visibleStudent->id,
        'parent_phone' => $parent->phone,
        'relationship' => 'mother',
        'status' => 'active',
        'linked_at' => now(),
    ]);

    AttendanceEvent::query()->create([
        'tenant_id' => $visibleTenant->id,
        'school_id' => $visibleSchool->id,
        'student_id' => $visibleStudent->id,
        'device_id' => $visibleDevice->id,
        'event_key' => 'visible-event',
        'event_type' => 'check_in',
        'event_time' => now(),
        'processing_status' => 'processed',
        'edu_admin_sync_status' => 'pending',
    ]);

    AttendanceEvent::query()->create([
        'tenant_id' => $hiddenTenant->id,
        'school_id' => $hiddenSchool->id,
        'device_id' => BiometricDevice::query()->where('school_id', $hiddenSchool->id)->value('id'),
        'event_key' => 'hidden-event',
        'event_type' => 'check_in',
        'event_time' => now(),
        'processing_status' => 'processed',
        'edu_admin_sync_status' => 'pending',
    ]);

    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $visibleTenant->id,
        'provider' => 'edu_admin',
        'mode' => 'connected',
        'status' => 'active',
        'base_url' => 'https://eduadmin-api.example.test',
    ]);

    IntegrationConnection::query()->create([
        'tenant_id' => $hiddenTenant->id,
        'provider' => 'edu_admin',
        'mode' => 'connected',
        'status' => 'active',
    ]);

    $run = IntegrationSyncRun::query()->create([
        'connection_id' => $connection->id,
        'sync_type' => 'initial',
        'direction' => 'pull',
        'status' => 'completed',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'records_read' => 4,
        'records_created' => 4,
    ]);

    IntegrationOutboxEvent::query()->create([
        'connection_id' => $connection->id,
        'event_type' => 'attendance.event.created',
        'event_key' => 'outbox-visible',
        'payload' => ['event_key' => 'visible-event'],
        'status' => 'pending',
        'available_at' => now(),
    ]);

    IntegrationAuditEvent::query()->create([
        'tenant_id' => $visibleTenant->id,
        'connection_id' => $connection->id,
        'category' => 'sync',
        'event_type' => 'sync.initial.completed',
        'severity' => 'info',
        'status' => 'completed',
        'summary' => 'Initial sync completed.',
        'related_type' => IntegrationSyncRun::class,
        'related_id' => $run->id,
        'occurred_at' => now(),
    ]);

    $thread = ConversationThread::query()->create([
        'tenant_id' => $visibleTenant->id,
        'school_id' => $visibleSchool->id,
        'class_id' => $visibleClass->id,
        'type' => ConversationThread::TYPE_CLASS_GROUP,
        'title' => 'Visible Class Group',
        'status' => 'open',
        'created_by_type' => 'system',
    ]);

    ConversationMessage::query()->create([
        'thread_id' => $thread->id,
        'sender_type' => ConversationMessage::SENDER_PARENT,
        'sender_id' => $parent->id,
        'sender_display_name' => 'Parent One',
        'body' => 'Hello administration',
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    AdminUser::query()->create([
        'name' => 'School Admin',
        'email' => 'school@example.com',
        'password' => Hash::make('password-secret'),
        'role' => 'school_admin',
        'school_id' => 10,
        'is_active' => true,
    ]);

    $token = $this->postJson('/api/admin/v2/auth/login', [
        'email' => 'school@example.com',
        'password' => 'password-secret',
    ])->assertOk()->json('data.access_token');

    $response = $this->withToken($token)->getJson('/api/admin/v2/dashboard');

    $response->assertOk()
        ->assertJsonPath('data.summary.tenants', 1)
        ->assertJsonPath('data.summary.schools', 1)
        ->assertJsonPath('data.summary.classes', 1)
        ->assertJsonPath('data.summary.students', 1)
        ->assertJsonPath('data.summary.parents', 1)
        ->assertJsonPath('data.summary.attendance_today', 1)
        ->assertJsonPath('data.health.active_connections', 1)
        ->assertJsonPath('data.health.pending_outbox', 1)
        ->assertJsonPath('data.organization.schools.0.name', 'Visible School');

    expect(collect($response->json('data.organization.schools'))->pluck('name')->all())
        ->toBe(['Visible School']);
    expect(collect($response->json('data.recent.attendance_events'))->pluck('event_key')->all())
        ->not->toContain('hidden-event');
});

function createDashboardSchoolGraph(string $name, string $legacySchoolId): array
{
    $tenant = Tenant::query()->create([
        'name' => "{$name} Tenant",
        'slug' => strtolower($name) . '-tenant',
        'status' => 'active',
    ]);

    $school = School::query()->create([
        'tenant_id' => $tenant->id,
        'name' => "{$name} School",
        'slug' => strtolower($name) . '-school',
        'status' => 'active',
        'source_system' => 'legacy',
        'source_id' => $legacySchoolId,
    ]);

    $section = Section::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'name' => "{$name} Section",
        'status' => 'active',
    ]);

    $option = EducationOption::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'section_id' => $section->id,
        'name' => "{$name} Option",
        'status' => 'active',
    ]);

    $stream = Stream::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'section_id' => $section->id,
        'education_option_id' => $option->id,
        'name' => "{$name} Stream",
        'status' => 'active',
    ]);

    $class = AcademicClass::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'stream_id' => $stream->id,
        'name' => "{$name} A",
        'full_name' => "{$name} Class A",
        'status' => 'active',
    ]);

    $student = Student::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'class_id' => $class->id,
        'student_number' => strtolower($name) . '-001',
        'first_name' => $name,
        'last_name' => 'Student',
        'status' => 'active',
    ]);

    $device = BiometricDevice::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'name' => "{$name} Gate",
        'device_uid' => strtolower($name) . '-gate',
        'status' => 'active',
        'last_seen_at' => now(),
    ]);

    return [$tenant, $school, $class, $student, $device];
}
