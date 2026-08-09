<?php

use App\Models\AdminUser;
use App\Models\V2\AcademicClass;
use App\Models\V2\ConversationMessage;
use App\Models\V2\ConversationParticipant;
use App\Models\V2\ConversationThread;
use App\Models\V2\MobileNotification;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\School;
use App\Models\V2\Section;
use App\Models\V2\Stream;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use App\Services\Conversations\ConversationService;
use Illuminate\Support\Facades\Hash;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();

    config([
        'educonnect.realtime.enabled' => true,
        'educonnect.realtime.app_key' => 'local-key',
        'educonnect.realtime.app_secret' => 'local-secret',
    ]);
});

it('automatically exposes class groups and school channels after child links', function (): void {
    [$parent, $schoolA, $classA, $studentA, $schoolB, $classB, $foreignSchool, $foreignClass] = createConversationGraph();
    $conversations = app(ConversationService::class);

    $foreignGroup = $conversations->findOrCreateClassGroup($foreignClass);
    $foreignChannel = $conversations->findOrCreateSchoolChannel($foreignSchool);

    $token = conversationParentToken($parent);
    conversationForgetGuards();

    $listResponse = $this->flushHeaders()->withToken($token)->getJson('/api/mobile/v2/conversations');
    $listResponse->assertOk();

    $items = collect($listResponse->json('data.items'));
    $classGroups = $items->where('type', ConversationThread::TYPE_CLASS_GROUP)->values();
    $schoolChannels = $items->where('type', ConversationThread::TYPE_SCHOOL_CHANNEL)->values();

    expect($classGroups)->toHaveCount(2)
        ->and($classGroups->pluck('class_id')->sort()->values()->all())->toBe(collect([$classA->id, $classB->id])->sort()->values()->all())
        ->and($schoolChannels)->toHaveCount(2)
        ->and($schoolChannels->pluck('school_id')->sort()->values()->all())->toBe(collect([$schoolA->id, $schoolB->id])->sort()->values()->all())
        ->and($items->pluck('id')->all())->not->toContain($foreignGroup->id, $foreignChannel->id);

    $classGroupA = ConversationThread::query()
        ->where('type', ConversationThread::TYPE_CLASS_GROUP)
        ->where('class_id', $classA->id)
        ->firstOrFail();

    $channelA = ConversationThread::query()
        ->where('type', ConversationThread::TYPE_SCHOOL_CHANNEL)
        ->where('school_id', $schoolA->id)
        ->firstOrFail();

    expect($classGroupA->created_by_type)->toBe(ConversationParticipant::TYPE_SYSTEM)
        ->and($classGroupA->created_by_id)->toBeNull()
        ->and($classGroupA->metadata['system_managed'])->toBeTrue()
        ->and($classGroupA->metadata['parents_can_post'])->toBeTrue()
        ->and($channelA->created_by_type)->toBe(ConversationParticipant::TYPE_SYSTEM)
        ->and($channelA->created_by_id)->toBeNull()
        ->and($channelA->metadata['system_managed'])->toBeTrue()
        ->and($channelA->metadata['parents_can_post'])->toBeFalse();

    $this->flushHeaders()->withToken($token)
        ->postJson("/api/mobile/v2/conversations/{$classGroupA->id}/messages", [
            'body' => 'Please what time is the PTA meeting?',
        ])
        ->assertCreated()
        ->assertJsonPath('data.message.sender_type', ConversationMessage::SENDER_PARENT)
        ->assertJsonPath('data.thread.type', ConversationThread::TYPE_CLASS_GROUP);

    expect(ConversationMessage::query()->where('thread_id', $classGroupA->id)->count())->toBe(1);

    $this->flushHeaders()->withToken($token)
        ->postJson("/api/mobile/v2/conversations/{$channelA->id}/messages", [
            'body' => 'Can parents reply here?',
        ])
        ->assertForbidden()
        ->assertJsonPath('message', 'Only school administrators can post in this channel.');

    $this->flushHeaders()->withToken($token)
        ->getJson("/api/mobile/v2/conversations/{$foreignGroup->id}")
        ->assertNotFound();

    $this->flushHeaders()->withToken($token)
        ->getJson("/api/mobile/v2/conversations/{$foreignChannel->id}")
        ->assertNotFound();

    $directResponse = $this->flushHeaders()->withToken($token)->postJson('/api/mobile/v2/conversations/direct', [
        'student_id' => $studentA->id,
        'subject' => 'Transport question',
        'body' => 'Who should I contact about pickup?',
    ]);

    $directResponse->assertCreated()
        ->assertJsonPath('data.thread.type', ConversationThread::TYPE_DIRECT)
        ->assertJsonPath('data.thread.student_id', $studentA->id)
        ->assertJsonPath('data.message.body', 'Who should I contact about pickup?');
});

it('creates default conversation spaces when a parent links a child code', function (): void {
    [$parent, $schoolA, $classA, $studentA] = createConversationGraph(activeLinks: false);

    expect(ConversationThread::query()->count())->toBe(0);

    $token = conversationParentToken($parent);
    conversationForgetGuards();

    $this->flushHeaders()->withToken($token)
        ->postJson('/api/mobile/v2/children/link', [
            'linking_code' => 'link-' . $studentA->id,
            'student_number' => $studentA->student_number,
        ])
        ->assertCreated()
        ->assertJsonPath('data.student.id', $studentA->id)
        ->assertJsonPath('data.status', 'active');

    $classGroup = ConversationThread::query()
        ->where('type', ConversationThread::TYPE_CLASS_GROUP)
        ->where('class_id', $classA->id)
        ->firstOrFail();

    $schoolChannel = ConversationThread::query()
        ->where('type', ConversationThread::TYPE_SCHOOL_CHANNEL)
        ->where('school_id', $schoolA->id)
        ->firstOrFail();

    expect(ConversationThread::query()->count())->toBe(2)
        ->and($classGroup->created_by_type)->toBe(ConversationParticipant::TYPE_SYSTEM)
        ->and($schoolChannel->created_by_type)->toBe(ConversationParticipant::TYPE_SYSTEM)
        ->and(conversationHasParentParticipant($classGroup, $parent))->toBeTrue()
        ->and(conversationHasParentParticipant($schoolChannel, $parent))->toBeTrue();
});

it('lets school admins reply to automatic groups and channels only inside their school scope', function (): void {
    [$parent, $schoolA, $classA, $studentA, $schoolB] = createConversationGraph();
    $conversations = app(ConversationService::class);
    $conversations->ensureDefaultThreadsForParent($parent);

    $classGroup = ConversationThread::query()
        ->where('type', ConversationThread::TYPE_CLASS_GROUP)
        ->where('class_id', $classA->id)
        ->firstOrFail();

    $otherSchoolChannel = $conversations->findOrCreateSchoolChannel($schoolB);
    $schoolAdmin = createConversationAdmin('School Admin', 'school-admin@example.com', 'school_admin', 701);
    $adminToken = conversationAdminToken($schoolAdmin);

    conversationForgetGuards();

    $this->flushHeaders()->withToken($adminToken)
        ->postJson("/api/admin/v2/conversations/{$classGroup->id}/messages", [
            'body' => 'Welcome to the Form 1A parents group.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.message.sender_type', ConversationMessage::SENDER_ADMIN)
        ->assertJsonPath('data.thread.type', ConversationThread::TYPE_CLASS_GROUP);

    expect(MobileNotification::query()
        ->where('parent_account_id', $parent->id)
        ->get()
        ->contains(fn (MobileNotification $notification) => (int) ($notification->data['conversation_thread_id'] ?? 0) === (int) $classGroup->id))->toBeTrue();

    $this->flushHeaders()->withToken($adminToken)
        ->postJson("/api/admin/v2/conversations/{$otherSchoolChannel->id}/messages", [
            'body' => 'This should not cross school scope.',
        ])
        ->assertForbidden()
        ->assertJsonPath('message', 'You cannot access this conversation.');

    $parentToken = conversationParentToken($parent);
    conversationForgetGuards();

    $this->flushHeaders()->withToken($parentToken)
        ->postJson("/api/mobile/v2/conversations/{$classGroup->id}/read")
        ->assertOk()
        ->assertJsonPath('data.marked_read', 1);

    $directThread = $this->flushHeaders()->withToken($parentToken)->postJson('/api/mobile/v2/conversations/direct', [
        'student_id' => $studentA->id,
    ])->assertCreated()->json('data.thread');

    conversationForgetGuards();

    $this->flushHeaders()->withToken($adminToken)
        ->postJson("/api/admin/v2/conversations/{$directThread['id']}/messages", [
            'body' => 'The transport office will call you today.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.message.sender_display_name', 'School Admin');

    expect(ConversationParticipant::query()
        ->where('thread_id', $directThread['id'])
        ->where('participant_type', ConversationParticipant::TYPE_ADMIN)
        ->where('participant_id', $schoolAdmin->id)
        ->exists())->toBeTrue();
});

it('creates separate direct conversation threads for named school desks', function (): void {
    [$parent, $schoolA, $classA, $studentA] = createConversationGraph();
    $token = conversationParentToken($parent);
    conversationForgetGuards();

    $accountsThread = $this->flushHeaders()->withToken($token)
        ->postJson('/api/mobile/v2/conversations/direct', [
            'student_id' => $studentA->id,
            'desk' => 'accounts',
        ])
        ->assertCreated()
        ->assertJsonPath('data.thread.metadata.desk', 'accounts')
        ->assertJsonPath('data.thread.metadata.desk_label', 'Accounts office')
        ->json('data.thread');

    $principalThread = $this->flushHeaders()->withToken($token)
        ->postJson('/api/mobile/v2/conversations/direct', [
            'student_id' => $studentA->id,
            'desk' => 'principal',
        ])
        ->assertCreated()
        ->assertJsonPath('data.thread.metadata.desk', 'principal')
        ->json('data.thread');

    $sameAccountsThread = $this->flushHeaders()->withToken($token)
        ->postJson('/api/mobile/v2/conversations/direct', [
            'student_id' => $studentA->id,
            'desk' => 'accounts',
            'body' => 'Please can I get the latest fee balance?',
        ])
        ->assertCreated()
        ->assertJsonPath('data.thread.metadata.desk', 'accounts')
        ->assertJsonPath('data.message.body', 'Please can I get the latest fee balance?')
        ->json('data.thread');

    expect($accountsThread['id'])->not->toBe($principalThread['id'])
        ->and($sameAccountsThread['id'])->toBe($accountsThread['id']);
});

it('exposes automatic conversation realtime channels for linked parents only', function (): void {
    [$parent, $schoolA, $classA] = createConversationGraph();
    $token = conversationParentToken($parent);
    conversationForgetGuards();

    $response = $this->flushHeaders()->withToken($token)->getJson('/api/mobile/v2/realtime/config');
    $response->assertOk();

    $classGroupId = ConversationThread::query()
        ->where('type', ConversationThread::TYPE_CLASS_GROUP)
        ->where('class_id', $classA->id)
        ->firstOrFail()
        ->id;

    $channelId = ConversationThread::query()
        ->where('type', ConversationThread::TYPE_SCHOOL_CHANNEL)
        ->where('school_id', $schoolA->id)
        ->firstOrFail()
        ->id;

    expect($response->json('data.channels'))->toContain(
        "private-school.{$schoolA->id}.parents",
        "private-school.{$schoolA->id}.class.{$classA->id}.parents",
        "private-conversation.{$classGroupId}",
        "private-conversation.{$channelId}",
    );

    $this->flushHeaders()->withToken($token)
        ->postJson('/api/mobile/v2/realtime/auth', [
            'socket_id' => '123.456',
            'channel_name' => "private-conversation.{$classGroupId}",
        ])
        ->assertOk();

    $this->flushHeaders()->withToken($token)
        ->postJson('/api/mobile/v2/realtime/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-conversation.999',
        ])
        ->assertForbidden();
});

function createConversationGraph(bool $activeLinks = true): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Conversation Tenant',
        'slug' => 'conversation-tenant',
        'status' => 'active',
    ]);

    [$schoolA, $classA, $studentA] = createConversationSchoolGraph($tenant, 'Secondary School', 'secondary-school', 701, 'Form 1A', 'Amina');
    [$schoolB, $classB, $studentB] = createConversationSchoolGraph($tenant, 'Primary School', 'primary-school', 702, 'Grade 4B', 'Boris');
    [$foreignSchool, $foreignClass] = createConversationSchoolGraph($tenant, 'Foreign School', 'foreign-school', 703, 'Form 2C', 'Celine');

    $parent = ParentAccount::query()->create([
        'phone' => '650000001',
        'email' => 'conversation-parent@example.com',
        'first_name' => 'Nadine',
        'last_name' => 'Parent',
        'preferred_language' => 'en',
        'status' => 'active',
        'password_hash' => Hash::make('password-secret'),
    ]);

    foreach ([$studentA, $studentB] as $student) {
        ParentStudentLink::query()->create([
            'tenant_id' => $tenant->id,
            'school_id' => $student->school_id,
            'student_id' => $student->id,
            'parent_account_id' => $activeLinks ? $parent->id : null,
            'parent_phone' => $parent->phone,
            'linking_code' => 'LINK-' . $student->id,
            'relationship' => 'parent',
            'is_primary_contact' => true,
            'can_pick_up' => true,
            'status' => $activeLinks ? 'active' : 'pending',
            'verified_at' => $activeLinks ? now() : null,
            'linked_at' => $activeLinks ? now() : null,
        ]);
    }

    return [$parent, $schoolA, $classA, $studentA, $schoolB, $classB, $foreignSchool, $foreignClass];
}

function createConversationSchoolGraph(
    Tenant $tenant,
    string $schoolName,
    string $slug,
    int $legacySchoolId,
    string $className,
    string $studentFirstName
): array {
    $school = School::query()->create([
        'tenant_id' => $tenant->id,
        'name' => $schoolName,
        'slug' => $slug,
        'status' => 'active',
        'timezone' => 'Africa/Douala',
        'source_system' => 'legacy',
        'source_id' => (string) $legacySchoolId,
    ]);

    $section = Section::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'name' => $schoolName . ' Section',
        'code' => strtoupper(substr($slug, 0, 2)) . $legacySchoolId,
        'status' => 'active',
    ]);

    $stream = Stream::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'section_id' => $section->id,
        'name' => $className,
        'display_name' => $className,
        'status' => 'active',
    ]);

    $class = AcademicClass::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'stream_id' => $stream->id,
        'name' => $className,
        'full_name' => $className,
        'status' => 'active',
    ]);

    $student = Student::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'class_id' => $class->id,
        'student_number' => strtoupper($slug) . '-001',
        'first_name' => $studentFirstName,
        'last_name' => 'Student',
        'status' => 'active',
        'mobile_visible' => true,
    ]);

    return [$school, $class, $student];
}

function createConversationAdmin(string $name, string $email, string $role, ?int $schoolId = null): AdminUser
{
    return AdminUser::query()->create([
        'name' => $name,
        'email' => $email,
        'password' => 'secret',
        'role' => $role,
        'school_id' => $schoolId,
        'is_active' => true,
    ]);
}

function conversationParentToken(ParentAccount $parent): string
{
    $response = test()->postJson('/api/mobile/v2/auth/login', [
        'phone' => $parent->phone,
        'password' => 'password-secret',
    ]);

    return $response->assertOk()->json('data.access_token');
}

function conversationAdminToken(AdminUser $admin): string
{
    return $admin->createToken('admin-conversation-test', ['*'])->plainTextToken;
}

function conversationHasParentParticipant(ConversationThread $thread, ParentAccount $parent): bool
{
    return ConversationParticipant::query()
        ->where('thread_id', $thread->id)
        ->where('participant_type', ConversationParticipant::TYPE_PARENT)
        ->where('participant_id', $parent->id)
        ->whereNull('left_at')
        ->exists();
}

function conversationForgetGuards(): void
{
    app('auth')->forgetGuards();
}
