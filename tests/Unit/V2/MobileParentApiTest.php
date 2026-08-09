<?php

use App\Models\V2\AcademicClass;
use App\Models\V2\AttendanceEvent;
use App\Models\V2\BiometricDevice;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\School;
use App\Models\V2\Section;
use App\Models\V2\Stream;
use App\Models\V2\Student;
use App\Models\V2\StudentMobileProfile;
use App\Models\V2\Tenant;
use App\Services\Conversations\ConversationService;
use Illuminate\Support\Facades\Hash;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();
});

it('registers and authenticates parent accounts with mobile tokens', function (): void {
    $registerResponse = $this->postJson('/api/mobile/v2/auth/register', [
        'phone' => '+237 650 000 001',
        'email' => 'parent@example.com',
        'first_name' => 'Amina',
        'last_name' => 'Talla',
        'password' => 'password-secret',
        'preferred_language' => 'en',
        'device_name' => 'Pixel 8',
    ]);

    $registerResponse->assertCreated();
    expect($registerResponse->json('data.token_type'))->toBe('Bearer');
    expect($registerResponse->json('data.access_token'))->not->toBeEmpty();
    expect($registerResponse->json('data.parent.phone'))->toBe('+237650000001');

    $this->withToken($registerResponse->json('data.access_token'))
        ->getJson('/api/mobile/v2/me')
        ->assertOk()
        ->assertJsonPath('data.parent.full_name', 'Amina Talla')
        ->assertJsonPath('data.parent.active_children_count', 0);

    $loginResponse = $this->postJson('/api/mobile/v2/auth/login', [
        'identifier' => 'parent@example.com',
        'password' => 'password-secret',
        'device_name' => 'Pixel 8',
    ]);

    $loginResponse->assertOk();
    expect($loginResponse->json('data.access_token'))->not->toBeEmpty();

    $this->withToken($loginResponse->json('data.access_token'))
        ->postJson('/api/mobile/v2/auth/logout')
        ->assertOk();
});

it('links a parent to a child and returns linked-student attendance', function (): void {
    [$parent, $student, $event] = createMobileParentGraph();

    ParentStudentLink::query()->firstOrFail()->update([
        'parent_phone' => '+237677000010',
    ]);

    StudentMobileProfile::query()->create([
        'tenant_id' => $student->tenant_id,
        'school_id' => $student->school_id,
        'student_id' => $student->id,
        'source_system' => 'edu_admin',
        'source_id' => '1001',
        'source_updated_at' => '2026-09-16 10:30:00',
        'profile' => [
            'fees' => [
                'currency' => 'XAF',
                'status' => 'partial',
                'total_due' => 150000,
                'total_paid' => 90000,
                'balance' => 60000,
                'installments' => [
                    ['name' => 'First installment', 'amount' => 50000, 'paid' => 50000, 'balance' => 0, 'status' => 'paid'],
                ],
                'payments' => [
                    ['receipt_number' => 'RCPT-001', 'amount' => 90000, 'currency' => 'XAF', 'paid_at' => '2026-09-16T10:30:00+00:00'],
                ],
            ],
            'academics' => [
                'current_average' => 14.5,
                'class_position' => 4,
                'total_students' => 37,
                'subjects' => [
                    ['name' => 'Mathematics', 'average' => 15.0, 'coefficient' => 4],
                ],
            ],
            'report_cards' => [
                ['id' => 'secondary-8', 'title' => 'Term 1 report card', 'average' => 14.5, 'preview' => ['available' => true]],
            ],
            'timetable' => [
                ['day_name' => 'Monday', 'period_label' => 'P1', 'subject' => 'Mathematics', 'teacher' => 'Grace Teacher'],
            ],
            'discipline' => [
                'today' => ['date' => '2026-09-15', 'status' => 'present'],
                'month_summary' => ['present' => 12, 'late' => 1, 'absent' => 0],
                'recent_attendance' => [],
                'incidents' => [],
            ],
        ],
    ]);

    $loginResponse = $this->postJson('/api/mobile/v2/auth/login', [
        'phone' => $parent->phone,
        'password' => 'password-secret',
    ]);
    $token = $loginResponse->assertOk()->json('data.access_token');

    $linkResponse = $this->withToken($token)->postJson('/api/mobile/v2/children/link', [
        'linking_code' => 'link-001',
        'student_number' => $student->student_number,
    ]);

    $linkResponse->assertCreated();
    expect($linkResponse->json('data.student.id'))->toBe($student->id);
    expect($linkResponse->json('data.relationship'))->toBe('mother');
    expect(ParentStudentLink::query()->firstOrFail()->status)->toBe('active');
    expect(ParentStudentLink::query()->firstOrFail()->parent_phone)->toBe($parent->phone);
    expect($parent->refresh()->phone_verified_at)->not->toBeNull();

    $this->withToken($token)
        ->getJson('/api/mobile/v2/children')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.student.full_name', 'Desmond Mbah');

    $detailResponse = $this->withToken($token)
        ->getJson("/api/mobile/v2/children/{$student->id}");

    $detailResponse->assertOk();
    expect($detailResponse->json('data.recent_attendance.0.id'))->toBe($event->id);
    expect($detailResponse->json('data.recent_attendance.0.device.name'))->toBe('Main Gate');

    $attendanceResponse = $this->withToken($token)
        ->getJson("/api/mobile/v2/children/{$student->id}/attendance?date_from=2026-09-15&date_to=2026-09-15");

    $attendanceResponse->assertOk();
    expect($attendanceResponse->json('data.0.event_key'))->toBe('attendance-event-001');

    $profileResponse = $this->withToken($token)
        ->getJson("/api/mobile/v2/children/{$student->id}/profile");

    $profileResponse->assertOk()
        ->assertJsonPath('data.source_system', 'edu_admin')
        ->assertJsonPath('data.profile.fees.balance', 60000)
        ->assertJsonPath('data.profile.academics.current_average', 14.5)
        ->assertJsonPath('data.profile.timetable.0.subject', 'Mathematics')
        ->assertJsonPath('data.profile.discipline.today.status', 'present');
});

it('accepts Edu-connect QR payloads when linking a child', function (): void {
    [$parent, $student] = createMobileParentGraph();

    ParentStudentLink::query()->firstOrFail()->update([
        'parent_phone' => '+237677000010',
    ]);

    $loginResponse = $this->postJson('/api/mobile/v2/auth/login', [
        'phone' => $parent->phone,
        'password' => 'password-secret',
    ]);
    $token = $loginResponse->assertOk()->json('data.access_token');

    $qrPayload = 'educonnect://link?' . http_build_query([
        'code' => 'link-001',
        'student_number' => $student->student_number,
    ]);

    $this->withToken($token)->postJson('/api/mobile/v2/children/link', [
        'linking_code' => $qrPayload,
    ])
        ->assertCreated()
        ->assertJsonPath('data.student.id', $student->id)
        ->assertJsonPath('data.status', 'active');
});

it('allows enrolled Edu-admin students to be linked in the mobile app', function (): void {
    [$parent, $student] = createMobileParentGraph();
    $student->forceFill(['status' => 'enrolled'])->save();

    $loginResponse = $this->postJson('/api/mobile/v2/auth/login', [
        'phone' => $parent->phone,
        'password' => 'password-secret',
    ]);
    $token = $loginResponse->assertOk()->json('data.access_token');

    $this->withToken($token)->postJson('/api/mobile/v2/children/link', [
        'linking_code' => 'LINK-001',
        'student_number' => $student->student_number,
    ])
        ->assertCreated()
        ->assertJsonPath('data.student.id', $student->id)
        ->assertJsonPath('data.student.status', 'enrolled');

    $this->withToken($token)
        ->getJson("/api/mobile/v2/children/{$student->id}")
        ->assertOk()
        ->assertJsonPath('data.student.status', 'enrolled');
});

it('keeps a successful child link even when conversation setup is unavailable', function (): void {
    [$parent, $student] = createMobileParentGraph();

    $this->mock(ConversationService::class, function ($mock): void {
        $mock->shouldReceive('ensureDefaultThreadsForLink')
            ->once()
            ->andThrow(new RuntimeException('conversation tables unavailable'));
    });

    $loginResponse = $this->postJson('/api/mobile/v2/auth/login', [
        'phone' => $parent->phone,
        'password' => 'password-secret',
    ]);
    $token = $loginResponse->assertOk()->json('data.access_token');

    $this->withToken($token)->postJson('/api/mobile/v2/children/link', [
        'linking_code' => 'LINK-001',
        'student_number' => $student->student_number,
    ])
        ->assertCreated()
        ->assertJsonPath('data.student.id', $student->id);

    $this->assertDatabaseHas('ec_parent_student_links', [
        'student_id' => $student->id,
        'parent_account_id' => $parent->id,
        'status' => 'active',
    ]);
});

it('allows a child code to link two parent accounts and rejects a third', function (): void {
    [$firstParent, $student] = createMobileParentGraph();
    $asParent = function (string $token) {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders()->withToken($token);
    };

    $secondParent = ParentAccount::query()->create([
        'phone' => '650000002',
        'email' => 'second@example.com',
        'first_name' => 'Second',
        'last_name' => 'Parent',
        'preferred_language' => 'en',
        'status' => 'active',
        'password_hash' => Hash::make('password-secret'),
    ]);

    $thirdParent = ParentAccount::query()->create([
        'phone' => '650000003',
        'email' => 'third@example.com',
        'first_name' => 'Third',
        'last_name' => 'Parent',
        'preferred_language' => 'en',
        'status' => 'active',
        'password_hash' => Hash::make('password-secret'),
    ]);

    $firstLogin = $this->postJson('/api/mobile/v2/auth/login', [
        'phone' => $firstParent->phone,
        'password' => 'password-secret',
    ]);
    $firstToken = $firstLogin->assertOk()->json('data.access_token');

    $asParent($firstToken)
        ->postJson('/api/mobile/v2/children/link', [
            'linking_code' => 'LINK-001',
            'student_number' => $student->student_number,
        ])
        ->assertCreated();

    $secondLogin = $this->postJson('/api/mobile/v2/auth/login', [
        'phone' => $secondParent->phone,
        'password' => 'password-secret',
    ]);
    $secondToken = $secondLogin->assertOk()->json('data.access_token');

    $asParent($secondToken)
        ->postJson('/api/mobile/v2/children/link', [
            'linking_code' => 'LINK-001',
            'student_number' => $student->student_number,
        ])
        ->assertCreated();

    expect(ParentStudentLink::query()
        ->where('student_id', $student->id)
        ->where('status', 'active')
        ->whereNotNull('parent_account_id')
        ->distinct('parent_account_id')
        ->count('parent_account_id'))->toBe(2);

    $thirdLogin = $this->postJson('/api/mobile/v2/auth/login', [
        'phone' => $thirdParent->phone,
        'password' => 'password-secret',
    ]);
    $thirdToken = $thirdLogin->assertOk()->json('data.access_token');

    $asParent($thirdToken)
        ->postJson('/api/mobile/v2/children/link', [
            'linking_code' => 'LINK-001',
            'student_number' => $student->student_number,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('linking_code');

    $asParent($thirdToken)
        ->getJson("/api/mobile/v2/children/{$student->id}")
        ->assertNotFound();
});

it('registers and revokes mobile push tokens for authenticated parents', function (): void {
    [$parent] = createMobileParentGraph();

    $loginResponse = $this->postJson('/api/mobile/v2/auth/login', [
        'phone' => $parent->phone,
        'password' => 'password-secret',
    ]);
    $token = $loginResponse->assertOk()->json('data.access_token');

    $this->withToken($token)
        ->postJson('/api/mobile/v2/push-tokens', [
            'provider' => 'fcm',
            'platform' => 'android',
            'token' => 'push-token-001',
            'device_name' => 'Pixel 8',
            'app_version' => '1.0.0',
            'locale' => 'en',
            'timezone' => 'Africa/Douala',
        ])
        ->assertCreated()
        ->assertJsonPath('data.provider', 'fcm')
        ->assertJsonPath('data.platform', 'android');

    $this->assertDatabaseHas('ec_mobile_push_tokens', [
        'parent_account_id' => $parent->id,
        'provider' => 'fcm',
        'platform' => 'android',
        'token' => 'push-token-001',
    ]);

    $this->withToken($token)
        ->deleteJson('/api/mobile/v2/push-tokens', [
            'provider' => 'fcm',
            'token' => 'push-token-001',
        ])
        ->assertOk();

    expect(\App\Models\V2\MobilePushToken::query()->firstOrFail()->revoked_at)->not->toBeNull();
});

function createMobileParentGraph(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Mobile Tenant',
        'slug' => 'mobile-tenant',
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
        'email' => 'amina@example.com',
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
        'parent_phone' => $parent->phone,
        'linking_code' => 'LINK-001',
        'relationship' => 'mother',
        'is_primary_contact' => true,
        'can_pick_up' => true,
        'status' => 'pending',
    ]);

    $device = BiometricDevice::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'name' => 'Main Gate',
        'device_uid' => 'device-main-gate',
        'location' => 'Front entrance',
        'status' => 'active',
    ]);

    $event = AttendanceEvent::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'student_id' => $student->id,
        'device_id' => $device->id,
        'event_key' => 'attendance-event-001',
        'event_type' => 'check_in',
        'event_time' => '2026-09-15 07:20:00',
        'confidence_score' => 95.5,
        'processing_status' => 'processed',
        'edu_admin_sync_status' => 'pending',
    ]);

    return [$parent, $student, $event];
}
