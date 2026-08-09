<?php

use App\Models\AdminUser;
use App\Models\V2\AcademicClass;
use App\Models\V2\AttendanceEvent;
use App\Models\V2\BiometricDevice;
use App\Models\V2\IntegrationAuditEvent;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\IntegrationMapping;
use App\Models\V2\IntegrationOutboxEvent;
use App\Models\V2\IntegrationSyncItem;
use App\Models\V2\IntegrationSyncRun;
use App\Models\V2\School;
use App\Models\V2\Section;
use App\Models\V2\Stream;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use Illuminate\Support\Facades\Crypt;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\V2Schema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    V2Schema::migrate();

    config([
        'integrations.providers.edu_admin.driver' => 'fixture',
        'integrations.providers.edu_admin.fixture_path' => base_path('tests/Fixtures/edu_admin_connector'),
    ]);
});

it('renders the admin integration dashboard with connection and outbox health', function (): void {
    $admin = AdminUser::query()->create([
        'name' => 'Ada Admin',
        'email' => 'ada@example.com',
        'password' => 'secret',
        'role' => 'super_admin',
        'is_active' => true,
    ]);

    [$connection] = createAdminWebIntegrationGraph();

    $run = IntegrationSyncRun::query()->create([
        'connection_id' => $connection->id,
        'sync_type' => 'initial',
        'direction' => 'pull',
        'status' => 'completed',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'records_read' => 10,
        'records_created' => 10,
    ]);

    IntegrationSyncItem::query()->create([
        'sync_run_id' => $run->id,
        'local_type' => 'student',
        'local_id' => 70,
        'external_type' => 'student',
        'external_id' => '70',
        'action' => 'created',
        'status' => 'success',
    ]);

    IntegrationOutboxEvent::query()->create([
        'connection_id' => $connection->id,
        'event_type' => 'attendance.event.created',
        'event_key' => 'web-outbox-pending',
        'payload' => ['event_key' => 'web-outbox-pending'],
        'status' => 'pending',
        'available_at' => now(),
    ]);

    IntegrationAuditEvent::query()->create([
        'tenant_id' => $connection->tenant_id,
        'connection_id' => $connection->id,
        'category' => 'sync',
        'event_type' => 'sync.initial.completed',
        'severity' => 'info',
        'status' => 'completed',
        'summary' => 'Initial sync completed.',
        'metadata' => ['records_read' => 10],
        'related_type' => IntegrationSyncRun::class,
        'related_id' => $run->id,
        'occurred_at' => now(),
    ]);

    $this->actingAs($admin, 'admin')
        ->get('/admin/integrations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Integrations/Index')
            ->where('summary.total_connections', 1)
            ->where('summary.active_connections', 1)
            ->where('summary.pending_outbox', 1)
            ->has('connections', 1)
            ->where('connections.0.id', $connection->id)
            ->where('connections.0.has_access_token', false)
            ->where('connections.0.has_webhook_secret', false)
            ->where('connections.0.audit_events_count', 1)
            ->has('availableTenants', 0)
            ->where('connectorScopes', ['foundation:read', 'messages:read', 'attendance:write', 'connector:*'])
            ->where('connectorDefaultScopes', ['foundation:read', 'messages:read', 'attendance:write'])
            ->has('connections.0.recent_sync_runs', 1)
            ->has('recentOutboxEvents', 1)
            ->has('recentAuditEvents', 1)
            ->where('recentAuditEvents.0.event_type', 'sync.initial.completed')
            ->has('recentSyncItems', 1)
            ->where('recentSyncItems.0.external_type', 'student'));
});

it('creates Edu-admin connection credentials from the admin dashboard', function (): void {
    $admin = AdminUser::query()->create([
        'name' => 'Ada Admin',
        'email' => 'ada-create-credentials@example.com',
        'password' => 'secret',
        'role' => 'super_admin',
        'is_active' => true,
    ]);

    $tenant = Tenant::query()->create([
        'name' => 'Credential Tenant',
        'slug' => 'credential-tenant',
        'status' => 'active',
    ]);

    $this->actingAs($admin, 'admin')
        ->post('/admin/integrations', [
            'tenant_id' => $tenant->id,
            'base_url' => 'https://edu-admin.test',
            'api_version' => 'v1',
            'remote_tenant_id' => '100',
            'status' => 'active',
            'scopes' => ['foundation:read', 'messages:read', 'attendance:write'],
            'access_token' => 'issued-access-token',
            'webhook_secret' => 'issued-webhook-secret',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $connection = IntegrationConnection::query()->firstOrFail();

    expect($connection->tenant_id)->toBe($tenant->id);
    expect($connection->provider)->toBe('edu_admin');
    expect($connection->status)->toBe('active');
    expect(Crypt::decryptString($connection->encrypted_access_token))->toBe('issued-access-token');
    expect(Crypt::decryptString($connection->webhook_secret))->toBe('issued-webhook-secret');

    $audit = IntegrationAuditEvent::query()->where('event_type', 'credentials.created')->firstOrFail();

    expect($audit->category)->toBe('credentials');
    expect($audit->metadata['access_token_action'])->toBe('set');
    expect($audit->metadata['webhook_secret_action'])->toBe('set');
    expect(json_encode($audit->metadata))->not->toContain('issued-access-token');
    expect(json_encode($audit->metadata))->not->toContain('issued-webhook-secret');

    $this->actingAs($admin, 'admin')
        ->get('/admin/integrations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.has_access_token', true)
            ->where('connections.0.has_webhook_secret', true)
            ->missing('connections.0.encrypted_access_token')
            ->missing('connections.0.webhook_secret'));
});

it('updates and clears Edu-admin connection credentials from the admin dashboard', function (): void {
    $admin = AdminUser::query()->create([
        'name' => 'Ada Admin',
        'email' => 'ada-update-credentials@example.com',
        'password' => 'secret',
        'role' => 'super_admin',
        'is_active' => true,
    ]);

    [$connection] = createAdminWebIntegrationGraph();
    $connection->forceFill([
        'encrypted_access_token' => Crypt::encryptString('old-access-token'),
        'webhook_secret' => Crypt::encryptString('old-webhook-secret'),
        'scopes' => ['foundation:read'],
    ])->save();

    $this->actingAs($admin, 'admin')
        ->patch("/admin/integrations/{$connection->id}/credentials", [
            'base_url' => 'https://edu-admin-updated.test',
            'api_version' => 'v1',
            'remote_tenant_id' => '200',
            'status' => 'active',
            'scopes' => ['foundation:read', 'messages:read', 'attendance:write'],
            'access_token' => 'new-access-token',
            'webhook_secret' => 'new-webhook-secret',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $connection->refresh();

    expect($connection->base_url)->toBe('https://edu-admin-updated.test');
    expect($connection->remote_tenant_id)->toBe('200');
    expect($connection->scopes)->toBe(['foundation:read', 'messages:read', 'attendance:write']);
    expect(Crypt::decryptString($connection->encrypted_access_token))->toBe('new-access-token');
    expect(Crypt::decryptString($connection->webhook_secret))->toBe('new-webhook-secret');

    $updatedAudit = IntegrationAuditEvent::query()->where('event_type', 'credentials.updated')->firstOrFail();

    expect($updatedAudit->metadata['access_token_action'])->toBe('replaced');
    expect($updatedAudit->metadata['webhook_secret_action'])->toBe('replaced');
    expect($updatedAudit->metadata['changed_fields'])->toContain('access_token');
    expect(json_encode($updatedAudit->metadata))->not->toContain('new-access-token');

    $this->actingAs($admin, 'admin')
        ->patch("/admin/integrations/{$connection->id}/credentials", [
            'base_url' => 'https://edu-admin-updated.test',
            'api_version' => 'v1',
            'remote_tenant_id' => '200',
            'status' => 'inactive',
            'scopes' => ['foundation:read'],
            'clear_access_token' => true,
            'clear_webhook_secret' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $connection->refresh();

    expect($connection->status)->toBe('inactive');
    expect($connection->encrypted_access_token)->toBeNull();
    expect($connection->webhook_secret)->toBeNull();

    $clearedAudit = IntegrationAuditEvent::query()->where('event_type', 'credentials.cleared')->firstOrFail();

    expect($clearedAudit->metadata['access_token_action'])->toBe('cleared');
    expect($clearedAudit->metadata['webhook_secret_action'])->toBe('cleared');
});

it('requires super admin access for credential changes', function (): void {
    $admin = AdminUser::query()->create([
        'name' => 'School Admin',
        'email' => 'school-credentials@example.com',
        'password' => 'secret',
        'role' => 'school_admin',
        'is_active' => true,
    ]);

    [$connection] = createAdminWebIntegrationGraph();

    $this->actingAs($admin, 'admin')
        ->patch("/admin/integrations/{$connection->id}/credentials", [
            'base_url' => 'https://edu-admin.test',
            'api_version' => 'v1',
            'status' => 'inactive',
            'scopes' => ['foundation:read'],
        ])
        ->assertForbidden();
});

it('pushes attendance from the admin integration dashboard action', function (): void {
    $admin = AdminUser::query()->create([
        'name' => 'Ada Admin',
        'email' => 'ada-action@example.com',
        'password' => 'secret',
        'role' => 'super_admin',
        'is_active' => true,
    ]);

    [$connection, $event] = createAdminWebIntegrationGraph();

    $this->actingAs($admin, 'admin')
        ->post("/admin/integrations/{$connection->id}/push-attendance")
        ->assertRedirect();

    expect($event->refresh()->edu_admin_sync_status)->toBe('synced');
    expect(IntegrationOutboxEvent::query()->firstOrFail()->status)->toBe('sent');
});

it('runs incremental sync from the admin integration dashboard action', function (): void {
    $admin = AdminUser::query()->create([
        'name' => 'Ada Admin',
        'email' => 'ada-incremental@example.com',
        'password' => 'secret',
        'role' => 'super_admin',
        'is_active' => true,
    ]);

    [$connection] = createAdminWebIntegrationGraph();

    $this->actingAs($admin, 'admin')
        ->post("/admin/integrations/{$connection->id}/sync-incremental")
        ->assertRedirect();

    $run = IntegrationSyncRun::query()->latest('id')->firstOrFail();

    expect($run->sync_type)->toBe('incremental');
    expect($run->status)->toBe('completed');
});

function createAdminWebIntegrationGraph(): array
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
        'last_name' => 'Ndzi',
        'status' => 'active',
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

    IntegrationMapping::query()->create([
        'connection_id' => $connection->id,
        'local_type' => 'school',
        'local_id' => $school->id,
        'external_type' => 'school',
        'external_id' => '10',
    ]);

    IntegrationMapping::query()->create([
        'connection_id' => $connection->id,
        'local_type' => 'class',
        'local_id' => $class->id,
        'external_type' => 'class',
        'external_id' => '60',
    ]);

    IntegrationMapping::query()->create([
        'connection_id' => $connection->id,
        'local_type' => 'student',
        'local_id' => $student->id,
        'external_type' => 'student',
        'external_id' => '70',
    ]);

    $event = AttendanceEvent::query()->create([
        'tenant_id' => $tenant->id,
        'school_id' => $school->id,
        'student_id' => $student->id,
        'device_id' => $device->id,
        'event_key' => 'web-attendance-event-001',
        'event_type' => 'check_in',
        'event_time' => '2026-09-15 07:20:00',
        'processing_status' => 'processed',
        'edu_admin_sync_status' => 'pending',
    ]);

    return [$connection, $event];
}
