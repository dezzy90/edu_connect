<?php

use App\Models\AdminUser;
use App\Models\V2\AcademicClass;
use App\Models\V2\ConversationMessage;
use App\Models\V2\ConversationThread;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\School;
use App\Models\V2\Section;
use App\Models\V2\Stream;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use App\Services\Conversations\ConversationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();

    config([
        'educonnect.realtime.enabled' => true,
        'educonnect.realtime.app_key' => 'admin-realtime-key',
        'educonnect.realtime.app_secret' => 'admin-realtime-secret',
        'educonnect.realtime.host' => '127.0.0.1',
        'educonnect.realtime.port' => 8080,
        'educonnect.realtime.scheme' => 'http',
    ]);
});

it('renders the admin conversation workspace with admin realtime channels', function (): void {
    [$parent, $school, $class] = createAdminConversationWebGraph();
    $admin = createAdminConversationWebAdmin('super_admin');
    $threads = app(ConversationService::class)->ensureDefaultThreadsForParent($parent);
    $classGroup = $threads->firstWhere('type', ConversationThread::TYPE_CLASS_GROUP);

    app(ConversationService::class)->postParentMessage($parent, $classGroup, 'Please confirm tomorrow pickup time.');

    $this->actingAs($admin, 'admin')
        ->get('/admin/conversations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Conversations/Index')
            ->where('realtime.enabled', true)
            ->where('realtime.authEndpoint', '/admin/conversations/realtime/auth')
            ->where('realtime.channels', fn ($channels): bool => in_array("private-school.{$school->id}.admins.conversations", collect($channels)->all(), true)
                && in_array("private-conversation.{$classGroup->id}", collect($channels)->all(), true))
            ->has('threads', 2)
            ->where('selectedThread.school_id', $school->id)
            ->where('selectedThread.class_id', $class->id)
            ->has('messages', 1));
});

it('authorizes only admin-owned realtime conversation channels', function (): void {
    [, $school] = createAdminConversationWebGraph();
    $otherSchool = School::query()->create([
        'tenant_id' => $school->tenant_id,
        'name' => 'Blocked School',
        'slug' => 'blocked-school',
        'status' => 'active',
        'timezone' => 'Africa/Douala',
    ]);
    $admin = createAdminConversationWebAdmin('school_admin', $school->id);

    $this->actingAs($admin, 'admin')
        ->postJson('/admin/conversations/realtime/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-school.{$school->id}.admins.conversations",
        ])
        ->assertOk()
        ->assertJsonPath('data.authorized', true);

    $this->actingAs($admin, 'admin')
        ->postJson('/admin/conversations/realtime/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-school.{$otherSchool->id}.admins.conversations",
        ])
        ->assertForbidden()
        ->assertJsonPath('message', 'This admin account cannot access the requested realtime channel.');
});

it('lets admins load and reply to visible conversation threads from the web panel', function (): void {
    [$parent] = createAdminConversationWebGraph();
    $admin = createAdminConversationWebAdmin('super_admin');
    $threads = app(ConversationService::class)->ensureDefaultThreadsForParent($parent);
    $classGroup = $threads->firstWhere('type', ConversationThread::TYPE_CLASS_GROUP);

    $this->actingAs($admin, 'admin')
        ->getJson("/admin/conversations/{$classGroup->id}")
        ->assertOk()
        ->assertJsonPath('data.thread.id', $classGroup->id);

    $this->actingAs($admin, 'admin')
        ->postJson("/admin/conversations/{$classGroup->id}/messages", [
            'body' => 'Pickup is at 2:30 PM.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.message.sender_type', ConversationMessage::SENDER_ADMIN)
        ->assertJsonPath('data.message.body', 'Pickup is at 2:30 PM.')
        ->assertJsonPath('data.thread.id', $classGroup->id);

    expect(ConversationMessage::query()
        ->where('thread_id', $classGroup->id)
        ->where('sender_type', ConversationMessage::SENDER_ADMIN)
        ->where('body', 'Pickup is at 2:30 PM.')
        ->exists())->toBeTrue();
});

function createAdminConversationWebGraph(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Admin Conversation Tenant',
        'slug' => 'admin-conversation-tenant',
        'status' => 'active',
    ]);

    $school = School::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Admin Conversation School',
        'slug' => 'admin-conversation-school',
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
        'student_number' => 'ADMIN-CONVO-001',
        'first_name' => 'Amina',
        'last_name' => 'Student',
        'status' => 'active',
        'mobile_visible' => true,
    ]);

    $parent = ParentAccount::query()->create([
        'phone' => '650009001',
        'email' => 'admin-conversation-parent@example.com',
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
        'parent_account_id' => $parent->id,
        'parent_phone' => $parent->phone,
        'linking_code' => 'ADMIN-CONVO-LINK',
        'relationship' => 'parent',
        'is_primary_contact' => true,
        'can_pick_up' => true,
        'status' => 'active',
        'verified_at' => now(),
        'linked_at' => now(),
    ]);

    return [$parent, $school, $class, $student, $tenant];
}

function createAdminConversationWebAdmin(string $role, ?int $schoolId = null): AdminUser
{
    if ($schoolId) {
        ensureAdminConversationLegacySchool($schoolId);
    }

    return AdminUser::query()->create([
        'name' => $role === 'super_admin' ? 'Ada Admin' : 'School Admin',
        'email' => $role.'-'.uniqid().'@example.com',
        'password' => 'secret',
        'role' => $role,
        'school_id' => $schoolId,
        'is_active' => true,
    ]);
}

function ensureAdminConversationLegacySchool(int $schoolId): void
{
    if (! Schema::hasTable('schools')) {
        Schema::create('schools', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('timezone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });
    }

    if (! DB::table('schools')->where('id', $schoolId)->exists()) {
        DB::table('schools')->insert([
            'id' => $schoolId,
            'name' => 'Legacy School',
            'code' => 'legacy-school',
            'timezone' => 'Africa/Douala',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
