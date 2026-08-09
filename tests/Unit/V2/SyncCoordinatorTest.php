<?php

use App\Models\V2\AcademicClass;
use App\Models\V2\AcademicYear;
use App\Models\V2\EducationOption;
use App\Models\V2\IntegrationAuditEvent;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\IntegrationMapping;
use App\Models\V2\IntegrationSyncItem;
use App\Models\V2\MobileMessage;
use App\Models\V2\MobileMessageRecipient;
use App\Models\V2\MobileNotification;
use App\Models\V2\ParentAccount;
use App\Models\V2\ParentStudentLink;
use App\Models\V2\School;
use App\Models\V2\Section;
use App\Models\V2\Stream;
use App\Models\V2\Student;
use App\Models\V2\StudentMobileProfile;
use App\Models\V2\Tenant;
use App\Services\Integration\Connectors\FixtureEduAdminConnector;
use App\Services\Integration\SyncCoordinator;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();
});

it('imports the Edu-admin foundation graph and keeps the sync idempotent', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Local Demo',
        'slug' => 'local-demo',
        'status' => 'active',
    ]);

    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'edu_admin',
        'mode' => 'connected',
        'base_url' => 'https://edu-admin.test',
        'status' => 'active',
    ]);

    $connector = new FixtureEduAdminConnector(base_path('tests/Fixtures/edu_admin_connector'));
    $coordinator = app(SyncCoordinator::class);

    $firstRun = $coordinator->runInitialSync($connection, $connector);

    expect($firstRun->status)->toBe('completed');
    expect($firstRun->records_read)->toBe(12);
    expect($firstRun->records_created)->toBe(12);
    expect($firstRun->records_failed)->toBe(0);
    expect(Tenant::query()->first()->source_id)->toBe('100');
    expect(School::query()->count())->toBe(1);
    expect(AcademicYear::query()->count())->toBe(1);
    expect(Section::query()->count())->toBe(1);
    expect(EducationOption::query()->count())->toBe(1);
    expect(Stream::query()->count())->toBe(1);
    expect(AcademicClass::query()->count())->toBe(1);
    expect(Student::query()->count())->toBe(2);
    expect(StudentMobileProfile::query()->count())->toBe(1);
    expect(ParentStudentLink::query()->count())->toBe(2);
    expect(MobileMessage::query()->count())->toBe(1);
    expect(IntegrationMapping::query()->where('external_type', 'student')->count())->toBe(2);
    expect(IntegrationMapping::query()->where('external_type', 'student_mobile_profile')->count())->toBe(1);
    expect(IntegrationMapping::query()->where('external_type', 'mobile_message')->count())->toBe(1);
    expect(IntegrationSyncItem::query()->where('status', 'success')->count())->toBe(12);
    expect(IntegrationAuditEvent::query()->where('event_type', 'sync.initial.completed')->count())->toBe(1);
    expect(IntegrationAuditEvent::query()->where('event_type', 'messages.ingested')->count())->toBe(1);
    expect(IntegrationAuditEvent::query()->where('event_type', 'sync.initial.completed')->first()->metadata['records_read'])->toBe(12);

    $student = Student::query()->where('source_id', '70')->firstOrFail();
    expect($student->class_id)->toBe(AcademicClass::query()->where('source_id', '60')->value('id'));
    expect(StudentMobileProfile::query()->firstOrFail()->profile['fees']['balance'])->toBe(60000);

    $secondRun = $coordinator->runInitialSync($connection->refresh(), $connector);

    expect($secondRun->status)->toBe('completed');
    expect($secondRun->records_read)->toBe(12);
    expect($secondRun->records_created)->toBe(0);
    expect($secondRun->records_updated)->toBe(12);
    expect(School::query()->count())->toBe(1);
    expect(Student::query()->count())->toBe(2);
    expect(ParentStudentLink::query()->count())->toBe(2);
    expect(MobileMessage::query()->count())->toBe(1);
});

it('pulls Edu-admin mobile messages incrementally and publishes them to linked parents', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Local Demo',
        'slug' => 'local-demo',
        'status' => 'active',
    ]);

    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'edu_admin',
        'mode' => 'connected',
        'base_url' => 'https://edu-admin.test',
        'status' => 'active',
    ]);

    $connector = new FixtureEduAdminConnector(base_path('tests/Fixtures/edu_admin_connector'));
    $coordinator = app(SyncCoordinator::class);

    $coordinator->runInitialSync($connection, $connector);

    $parent = ParentAccount::query()->create([
        'phone' => '+237677000010',
        'first_name' => 'Grace',
        'last_name' => 'Parent',
        'status' => 'active',
        'phone_verified_at' => now(),
    ]);

    ParentStudentLink::query()
        ->where('source_id', '80')
        ->firstOrFail()
        ->forceFill([
            'parent_account_id' => $parent->id,
            'status' => 'active',
        ])
        ->save();

    $run = $coordinator->runIncrementalSync($connection->refresh(), $connector, [
        'updated_after' => '2026-08-07T08:00:00Z',
        'resources' => ['mobile_messages'],
        'metadata' => ['test' => 'mobile-message-incremental'],
    ]);

    expect($run->status)->toBe('completed');
    expect($run->sync_type)->toBe('incremental');
    expect($run->records_read)->toBe(1);
    expect($run->records_created)->toBe(0);
    expect($run->records_updated)->toBe(1);
    expect($run->records_failed)->toBe(0);
    expect($run->metadata['resource_cursors']['mobile_messages'])->toBe('90');

    $message = MobileMessage::query()->firstOrFail();
    expect($message->source_id)->toBe('90');
    expect($message->status)->toBe('published');
    expect($message->title)->toBe('Parent Orientation Reminder');

    expect(MobileMessageRecipient::query()->count())->toBe(1);
    expect(MobileNotification::query()->where('type', 'messages')->count())->toBe(1);
    expect(IntegrationMapping::query()->where('external_type', 'mobile_message')->value('external_id'))->toBe('90');
    expect(IntegrationAuditEvent::query()->where('event_type', 'sync.incremental.completed')->count())->toBe(1);
    expect(IntegrationAuditEvent::query()->where('event_type', 'messages.ingested')->count())->toBe(2);
    expect(IntegrationAuditEvent::query()->where('event_type', 'messages.ingested')->latest('id')->first()->metadata['resource'])->toBe('mobile_messages');
});
