<?php

use App\Contracts\EduAdminConnector;
use App\Models\V2\AcademicClass;
use App\Models\V2\AttendanceEvent;
use App\Models\V2\BiometricDevice;
use App\Models\V2\IntegrationAuditEvent;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\IntegrationMapping;
use App\Models\V2\IntegrationOutboxEvent;
use App\Models\V2\School;
use App\Models\V2\Section;
use App\Models\V2\Stream;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use App\Services\Integration\AttendanceOutboxDispatcher;
use App\Services\Integration\MappingService;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();

    config([
        'integrations.providers.edu_admin.driver' => 'fixture',
        'integrations.providers.edu_admin.fixture_path' => base_path('tests/Fixtures/edu_admin_connector'),
        'integrations.sync.outbox_retry_minutes' => 5,
        'integrations.sync.outbox_max_attempts' => 5,
    ]);
});

it('queues mapped attendance events and marks accepted pushes as synced', function (): void {
    [$connection, $event] = createAttendanceOutboxGraph();
    $dispatcher = app(AttendanceOutboxDispatcher::class);

    $queued = $dispatcher->enqueuePending($connection);

    expect($queued)->toMatchArray([
        'queued' => 1,
        'skipped' => 0,
        'failed' => 0,
    ]);

    $outbox = IntegrationOutboxEvent::query()->firstOrFail();

    expect($outbox->event_type)->toBe('attendance.event.created');
    expect($outbox->payload['event_key'])->toBe('attendance-event-001');
    expect($outbox->payload['school_id'])->toBe(10);
    expect($outbox->payload['student_id'])->toBe(70);
    expect($outbox->payload['class_id'])->toBe(60);
    expect($event->refresh()->edu_admin_sync_status)->toBe('queued');
    expect(IntegrationAuditEvent::query()->where('event_type', 'attendance.outbox.enqueued')->first()->metadata['queued'])->toBe(1);

    $connector = new class implements EduAdminConnector {
        public array $pushedEvents = [];

        public function bootstrap(): array
        {
            return [];
        }

        public function resource(string $resource, ?string $cursor = null, array $filters = []): array
        {
            return [];
        }

        public function pushAttendanceEvents(array $events): array
        {
            $this->pushedEvents = $events;

            return [
                'accepted' => collect($events)->pluck('event_key')->all(),
                'duplicates' => [],
                'rejected' => [],
            ];
        }
    };

    $pushed = $dispatcher->dispatchPending($connection, $connector);

    expect($pushed)->toMatchArray([
        'sent' => 1,
        'duplicates' => 0,
        'failed' => 0,
    ]);
    expect($connector->pushedEvents[0]['event_key'])->toBe('attendance-event-001');
    expect($outbox->refresh()->status)->toBe('sent');
    expect($outbox->attempts)->toBe(1);
    expect($outbox->sent_at)->not->toBeNull();
    expect($event->refresh()->edu_admin_sync_status)->toBe('synced');
    expect($event->edu_admin_synced_at)->not->toBeNull();
    expect($connection->refresh()->last_successful_sync_at)->not->toBeNull();
    expect(IntegrationAuditEvent::query()->where('event_type', 'attendance.outbox.dispatched')->first()->metadata['sent'])->toBe(1);
});

it('fails attendance enqueue when required Edu-admin mappings are missing', function (): void {
    [$connection, $event] = createAttendanceOutboxGraph();

    IntegrationMapping::query()
        ->where('connection_id', $connection->id)
        ->where('local_type', 'student')
        ->delete();

    $queued = app(AttendanceOutboxDispatcher::class)->enqueuePending($connection);

    expect($queued)->toMatchArray([
        'queued' => 0,
        'skipped' => 0,
        'failed' => 1,
    ]);
    expect(IntegrationOutboxEvent::query()->count())->toBe(0);
    expect($event->refresh()->edu_admin_sync_status)->toBe('failed');
    expect($event->edu_admin_error)->toContain('student mapping');
    expect(IntegrationAuditEvent::query()->where('event_type', 'attendance.outbox.enqueued')->first()->severity)->toBe('warning');
    expect(IntegrationAuditEvent::query()->where('event_type', 'attendance.outbox.enqueued')->first()->metadata['failed'])->toBe(1);
});

it('runs attendance pushes from the console command', function (): void {
    [$connection, $event] = createAttendanceOutboxGraph();

    $this->artisan('educonnect:push-attendance', [
        'connection_id' => $connection->id,
        '--driver' => 'fixture',
    ])->assertExitCode(0);

    expect(IntegrationOutboxEvent::query()->firstOrFail()->status)->toBe('sent');
    expect($event->refresh()->edu_admin_sync_status)->toBe('synced');
});

function createAttendanceOutboxGraph(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Local Demo',
        'slug' => 'local-demo',
        'status' => 'active',
    ]);

    $school = School::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Legacy Linked School',
        'slug' => 'legacy-linked-school',
        'type' => 'secondary',
        'status' => 'active',
        'source_system' => 'edu_admin',
        'source_id' => '10',
    ]);

    $section = Section::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'name' => 'Anglophone',
        'code' => 'EN',
        'status' => 'active',
        'source_system' => 'edu_admin',
        'source_id' => '30',
    ]);

    $stream = Stream::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'section_id' => $section->id,
        'name' => 'Form 1',
        'display_name' => 'Form 1',
        'status' => 'active',
        'source_system' => 'edu_admin',
        'source_id' => '50',
    ]);

    $class = AcademicClass::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'stream_id' => $stream->id,
        'name' => 'A',
        'full_name' => 'Form 1A',
        'status' => 'active',
        'source_system' => 'edu_admin',
        'source_id' => '60',
    ]);

    $student = Student::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'class_id' => $class->id,
        'student_number' => 'DEMO-0001',
        'first_name' => 'Desmond',
        'last_name' => 'Ndzi',
        'status' => 'active',
        'source_system' => 'edu_admin',
        'source_id' => '70',
    ]);

    $device = BiometricDevice::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'name' => 'Main Gate',
        'device_uid' => 'device-main-gate',
        'status' => 'active',
    ]);

    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'edu_admin',
        'mode' => 'connected',
        'base_url' => 'https://edu-admin.test',
        'status' => 'active',
    ]);

    $mappings = app(MappingService::class);
    $mappings->upsert($connection, 'school', $school->id, 'school', 10);
    $mappings->upsert($connection, 'class', $class->id, 'class', 60);
    $mappings->upsert($connection, 'student', $student->id, 'student', 70);

    $event = AttendanceEvent::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'student_id' => $student->id,
        'device_id' => $device->id,
        'event_key' => 'attendance-event-001',
        'event_type' => 'check_in',
        'event_time' => '2026-09-15 07:20:00',
        'confidence_score' => 95.5,
        'raw_payload' => ['camera' => 'front'],
        'processing_status' => 'processed',
        'edu_admin_sync_status' => 'pending',
    ]);

    return [$connection, $event];
}
