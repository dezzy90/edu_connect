<?php

use App\Jobs\V2\Integration\PushEduAdminAttendanceOutboxJob;
use App\Jobs\V2\Integration\RunEduAdminIncrementalSyncJob;
use App\Jobs\V2\Notifications\DispatchMobilePushNotificationsJob;
use App\Jobs\V2\Notifications\PublishDueMobileMessagesJob;
use App\Models\V2\AttendanceEvent;
use App\Models\V2\BiometricDevice;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\IntegrationOutboxEvent;
use App\Models\V2\IntegrationSyncRun;
use App\Models\V2\MobileMessage;
use App\Models\V2\School;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use App\Services\Integration\AttendanceOutboxDispatcher;
use App\Services\Integration\EduAdminConnectorFactory;
use App\Services\Integration\SyncCoordinator;
use Illuminate\Support\Facades\Queue;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();

    config([
        'integrations.providers.edu_admin.driver' => 'fixture',
        'integrations.providers.edu_admin.fixture_path' => base_path('tests/Fixtures/edu_admin_connector'),
        'integrations.scheduler.queue' => 'edu-connect',
        'integrations.scheduler.connection_batch_size' => 25,
        'integrations.sync.outbox_retry_minutes' => 5,
        'integrations.sync.outbox_max_attempts' => 5,
    ]);
});

it('dispatches scheduled jobs only for active connected Edu-admin connections', function (): void {
    Queue::fake();

    $active = createScheduledWorkConnection('active-connected', 'connected', 'active');
    createScheduledWorkConnection('inactive-connected', 'connected', 'inactive');
    createScheduledWorkConnection('active-standalone', 'standalone', 'active');

    $this->artisan('educonnect:dispatch-scheduled-work', [
        '--only' => ['sync', 'attendance', 'messages', 'push'],
        '--queue' => 'edu-connect-critical',
        '--sync-resource' => ['mobile_messages'],
        '--attendance-limit' => 12,
        '--message-limit' => 13,
        '--push-limit' => 14,
    ])->assertExitCode(0);

    Queue::assertPushedOn('edu-connect-critical', RunEduAdminIncrementalSyncJob::class, function (RunEduAdminIncrementalSyncJob $job) use ($active): bool {
        return $job->connectionId === $active->id
            && $job->resources === ['mobile_messages'];
    });
    Queue::assertPushedOn('edu-connect-critical', PushEduAdminAttendanceOutboxJob::class, function (PushEduAdminAttendanceOutboxJob $job) use ($active): bool {
        return $job->connectionId === $active->id
            && $job->limit === 12;
    });
    Queue::assertPushedOn('edu-connect-critical', PublishDueMobileMessagesJob::class, fn (PublishDueMobileMessagesJob $job): bool => $job->limit === 13);
    Queue::assertPushedOn('edu-connect-critical', DispatchMobilePushNotificationsJob::class, fn (DispatchMobilePushNotificationsJob $job): bool => $job->limit === 14);
    Queue::assertPushed(RunEduAdminIncrementalSyncJob::class, 1);
    Queue::assertPushed(PushEduAdminAttendanceOutboxJob::class, 1);
});

it('runs queued incremental sync and attendance push jobs end to end', function (): void {
    $connection = createScheduledWorkConnection('job-active-connected', 'connected', 'active');

    (new RunEduAdminIncrementalSyncJob($connection->id, 'fixture'))->handle(
        app(EduAdminConnectorFactory::class),
        app(SyncCoordinator::class),
    );

    expect(IntegrationSyncRun::query()->count())->toBe(1);
    expect(IntegrationSyncRun::query()->first()->status)->toBe('completed');
    expect(Student::query()->count())->toBe(2);
    expect(MobileMessage::query()->count())->toBe(1);

    $school = School::query()->where('source_system', 'edu_admin')->where('source_id', '10')->firstOrFail();
    $student = Student::query()->where('source_system', 'edu_admin')->where('source_id', '70')->firstOrFail();
    $device = BiometricDevice::query()->create([
        'tenant_id' => $connection->tenant_id,
        'school_id' => $school->id,
        'name' => 'Main Gate',
        'device_uid' => 'scheduled-device-main-gate',
        'status' => 'active',
    ]);

    $event = AttendanceEvent::query()->create([
        'tenant_id' => $connection->tenant_id,
        'school_id' => $school->id,
        'student_id' => $student->id,
        'device_id' => $device->id,
        'event_key' => 'scheduled-attendance-event-001',
        'event_type' => 'check_in',
        'event_time' => '2026-09-15 07:20:00',
        'confidence_score' => 96.2,
        'raw_payload' => ['source' => 'scheduled-test'],
        'processing_status' => 'processed',
        'edu_admin_sync_status' => 'pending',
    ]);

    (new PushEduAdminAttendanceOutboxJob($connection->id, 10, 'fixture'))->handle(
        app(EduAdminConnectorFactory::class),
        app(AttendanceOutboxDispatcher::class),
    );

    expect(IntegrationOutboxEvent::query()->firstOrFail()->status)->toBe('sent');
    expect($event->refresh()->edu_admin_sync_status)->toBe('synced');
});

function createScheduledWorkConnection(string $slug, string $mode, string $status): IntegrationConnection
{
    $tenant = Tenant::query()->create([
        'name' => str($slug)->headline()->toString(),
        'slug' => $slug,
        'status' => 'active',
    ]);

    return IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'edu_admin',
        'mode' => $mode,
        'base_url' => 'https://edu-admin.test',
        'status' => $status,
    ]);
}
