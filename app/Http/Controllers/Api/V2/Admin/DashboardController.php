<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\V2\AcademicClass;
use App\Models\V2\AttendanceEvent;
use App\Models\V2\BiometricDevice;
use App\Models\V2\ConversationMessage;
use App\Models\V2\ConversationThread;
use App\Models\V2\IntegrationAuditEvent;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\IntegrationOutboxEvent;
use App\Models\V2\IntegrationSyncRun;
use App\Models\V2\MobileNotification;
use App\Models\V2\ParentAccount;
use App\Models\V2\School;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use App\Services\Realtime\RealtimeConfigurationHealth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    public function __invoke(Request $request, RealtimeConfigurationHealth $realtimeHealth): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $tenantIds = $this->tenantIdsForAdmin($admin);
        $schoolIds = $this->schoolIdsForAdmin($admin);

        return response()->json([
            'status' => 'success',
            'data' => [
                'summary' => $this->summary($tenantIds, $schoolIds),
                'health' => $this->health($tenantIds, $schoolIds, $realtimeHealth),
                'organization' => [
                    'tenants' => $this->tenants($tenantIds),
                    'schools' => $this->schools($schoolIds),
                ],
                'recent' => [
                    'attendance_events' => $this->recentAttendanceEvents($schoolIds),
                    'sync_runs' => $this->recentSyncRuns($tenantIds),
                    'audit_events' => $this->recentAuditEvents($tenantIds),
                    'outbox_events' => $this->recentOutboxEvents($tenantIds),
                    'conversation_messages' => $this->recentConversationMessages($schoolIds),
                ],
            ],
        ]);
    }

    private function summary(?Collection $tenantIds, ?Collection $schoolIds): array
    {
        return [
            'tenants' => $this->tenantQuery($tenantIds)->count(),
            'schools' => $this->schoolQuery($schoolIds)->count(),
            'classes' => $this->classQuery($schoolIds)->count(),
            'students' => $this->studentQuery($schoolIds)->count(),
            'parents' => $this->parentQuery($tenantIds, $schoolIds)->count(),
            'devices' => $this->deviceQuery($schoolIds)->count(),
            'active_devices' => $this->deviceQuery($schoolIds)->where('status', 'active')->count(),
            'attendance_today' => $this->attendanceQuery($schoolIds)->whereDate('event_time', today())->count(),
            'open_conversations' => $this->conversationQuery($schoolIds)->where('status', 'open')->count(),
            'notifications_queued' => $this->notificationQuery($tenantIds, $schoolIds)->where('delivery_status', 'queued')->count(),
        ];
    }

    private function health(?Collection $tenantIds, ?Collection $schoolIds, RealtimeConfigurationHealth $realtimeHealth): array
    {
        return [
            'mode' => config('educonnect.mode'),
            'api_version' => config('educonnect.api_version'),
            'realtime_enabled' => (bool) config('educonnect.realtime.enabled'),
            'realtime' => $realtimeHealth->snapshot(),
            'push_provider' => config('educonnect.notifications.push_provider'),
            'active_connections' => $this->connectionQuery($tenantIds)->where('status', 'active')->count(),
            'failed_sync_runs' => $this->syncRunQuery($tenantIds)->where('status', 'failed')->count(),
            'pending_outbox' => $this->outboxQuery($tenantIds)->where('status', 'pending')->count(),
            'failed_outbox' => $this->outboxQuery($tenantIds)->where('status', 'failed')->count(),
            'online_devices' => $this->deviceQuery($schoolIds)
                ->where(function (Builder $query): void {
                    $query->where('last_seen_at', '>=', now()->subMinutes(5))
                        ->orWhere('last_heartbeat_at', '>=', now()->subMinutes(5));
                })
                ->count(),
            'server_time' => now()->toIso8601String(),
        ];
    }

    private function tenants(?Collection $tenantIds): array
    {
        return $this->tenantQuery($tenantIds)
            ->withCount('schools')
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(fn (Tenant $tenant): array => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'code' => $tenant->code,
                'status' => $tenant->status,
                'schools_count' => $tenant->schools_count,
            ])
            ->values()
            ->all();
    }

    private function schools(?Collection $schoolIds): array
    {
        return $this->schoolQuery($schoolIds)
            ->withCount(['classes', 'students', 'biometricDevices'])
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->map(fn (School $school): array => [
                'id' => $school->id,
                'tenant_id' => $school->tenant_id,
                'name' => $school->name,
                'slug' => $school->slug,
                'code' => $school->code,
                'type' => $school->type,
                'status' => $school->status,
                'source_system' => $school->source_system,
                'source_id' => $school->source_id,
                'students_count' => $school->students_count,
                'classes_count' => $school->classes_count,
                'devices_count' => $school->biometric_devices_count,
            ])
            ->values()
            ->all();
    }

    private function recentAttendanceEvents(?Collection $schoolIds): array
    {
        return $this->attendanceQuery($schoolIds)
            ->with(['school', 'student', 'device'])
            ->latest('event_time')
            ->limit(8)
            ->get()
            ->map(fn (AttendanceEvent $event): array => [
                'id' => $event->id,
                'event_key' => $event->event_key,
                'event_type' => $event->event_type,
                'event_time' => $event->event_time?->toIso8601String(),
                'confidence_score' => $event->confidence_score,
                'processing_status' => $event->processing_status,
                'edu_admin_sync_status' => $event->edu_admin_sync_status,
                'student' => $event->student ? [
                    'id' => $event->student->id,
                    'full_name' => $event->student->full_name,
                    'student_number' => $event->student->student_number,
                ] : null,
                'school' => $event->school ? [
                    'id' => $event->school->id,
                    'name' => $event->school->name,
                ] : null,
                'device' => $event->device ? [
                    'id' => $event->device->id,
                    'name' => $event->device->name,
                    'device_uid' => $event->device->device_uid,
                ] : null,
            ])
            ->values()
            ->all();
    }

    private function recentSyncRuns(?Collection $tenantIds): array
    {
        return $this->syncRunQuery($tenantIds)
            ->with('connection.tenant')
            ->latest('started_at')
            ->limit(8)
            ->get()
            ->map(fn (IntegrationSyncRun $run): array => [
                'id' => $run->id,
                'connection_id' => $run->connection_id,
                'sync_type' => $run->sync_type,
                'direction' => $run->direction,
                'status' => $run->status,
                'records_read' => $run->records_read,
                'records_created' => $run->records_created,
                'records_updated' => $run->records_updated,
                'records_failed' => $run->records_failed,
                'error_message' => $run->error_message,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'tenant' => $run->connection?->tenant ? [
                    'id' => $run->connection->tenant->id,
                    'name' => $run->connection->tenant->name,
                ] : null,
            ])
            ->values()
            ->all();
    }

    private function recentAuditEvents(?Collection $tenantIds): array
    {
        return $this->auditQuery($tenantIds)
            ->with('tenant')
            ->latest('occurred_at')
            ->limit(8)
            ->get()
            ->map(fn (IntegrationAuditEvent $event): array => [
                'id' => $event->id,
                'category' => $event->category,
                'event_type' => $event->event_type,
                'severity' => $event->severity,
                'status' => $event->status,
                'summary' => $event->summary,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'tenant' => $event->tenant ? [
                    'id' => $event->tenant->id,
                    'name' => $event->tenant->name,
                ] : null,
            ])
            ->values()
            ->all();
    }

    private function recentOutboxEvents(?Collection $tenantIds): array
    {
        return $this->outboxQuery($tenantIds)
            ->with('connection.tenant')
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (IntegrationOutboxEvent $event): array => [
                'id' => $event->id,
                'connection_id' => $event->connection_id,
                'event_type' => $event->event_type,
                'event_key' => $event->event_key,
                'status' => $event->status,
                'attempts' => $event->attempts,
                'last_error' => $event->last_error,
                'available_at' => $event->available_at?->toIso8601String(),
                'sent_at' => $event->sent_at?->toIso8601String(),
                'tenant' => $event->connection?->tenant ? [
                    'id' => $event->connection->tenant->id,
                    'name' => $event->connection->tenant->name,
                ] : null,
            ])
            ->values()
            ->all();
    }

    private function recentConversationMessages(?Collection $schoolIds): array
    {
        return ConversationMessage::query()
            ->whereHas('thread', fn (Builder $query) => $this->scopeSchools($query, $schoolIds))
            ->with('thread.school')
            ->latest('sent_at')
            ->limit(8)
            ->get()
            ->map(fn (ConversationMessage $message): array => [
                'id' => $message->id,
                'thread_id' => $message->thread_id,
                'sender_type' => $message->sender_type,
                'sender_display_name' => $message->sender_display_name,
                'body' => $message->body,
                'sent_at' => $message->sent_at?->toIso8601String(),
                'thread' => $message->thread ? [
                    'id' => $message->thread->id,
                    'type' => $message->thread->type,
                    'title' => $message->thread->title,
                    'school' => $message->thread->school ? [
                        'id' => $message->thread->school->id,
                        'name' => $message->thread->school->name,
                    ] : null,
                ] : null,
            ])
            ->values()
            ->all();
    }

    private function tenantIdsForAdmin(AdminUser $admin): ?Collection
    {
        if ($admin->isSuperAdmin()) {
            return null;
        }

        if (!$admin->school_id) {
            return collect();
        }

        return School::query()
            ->where('source_system', 'legacy')
            ->where('source_id', (string) $admin->school_id)
            ->pluck('tenant_id')
            ->map(fn ($tenantId) => (int) $tenantId)
            ->unique()
            ->values();
    }

    private function schoolIdsForAdmin(AdminUser $admin): ?Collection
    {
        if ($admin->isSuperAdmin()) {
            return null;
        }

        if (!$admin->school_id) {
            return collect();
        }

        return School::query()
            ->where('source_system', 'legacy')
            ->where('source_id', (string) $admin->school_id)
            ->pluck('id')
            ->map(fn ($schoolId) => (int) $schoolId)
            ->unique()
            ->values();
    }

    private function tenantQuery(?Collection $tenantIds): Builder
    {
        $query = Tenant::query();

        if ($tenantIds !== null) {
            $query->whereIn('id', $tenantIds->all());
        }

        return $query;
    }

    private function schoolQuery(?Collection $schoolIds): Builder
    {
        $query = School::query();

        if ($schoolIds !== null) {
            $query->whereIn('id', $schoolIds->all());
        }

        return $query;
    }

    private function classQuery(?Collection $schoolIds): Builder
    {
        return $this->scopeSchools(AcademicClass::query(), $schoolIds);
    }

    private function studentQuery(?Collection $schoolIds): Builder
    {
        return $this->scopeSchools(Student::query(), $schoolIds);
    }

    private function parentQuery(?Collection $tenantIds, ?Collection $schoolIds): Builder
    {
        return ParentAccount::query()
            ->whereHas('studentLinks', function (Builder $query) use ($tenantIds, $schoolIds): void {
                $this->scopeTenants($query, $tenantIds);
                $this->scopeSchools($query, $schoolIds);
            });
    }

    private function deviceQuery(?Collection $schoolIds): Builder
    {
        return $this->scopeSchools(BiometricDevice::query(), $schoolIds);
    }

    private function attendanceQuery(?Collection $schoolIds): Builder
    {
        return $this->scopeSchools(AttendanceEvent::query(), $schoolIds);
    }

    private function conversationQuery(?Collection $schoolIds): Builder
    {
        return $this->scopeSchools(ConversationThread::query(), $schoolIds);
    }

    private function notificationQuery(?Collection $tenantIds, ?Collection $schoolIds): Builder
    {
        $query = MobileNotification::query();
        $this->scopeTenants($query, $tenantIds);

        if ($schoolIds !== null) {
            $query->where(function (Builder $nested) use ($schoolIds): void {
                $nested->whereNull('school_id')
                    ->orWhereIn('school_id', $schoolIds->all());
            });
        }

        return $query;
    }

    private function connectionQuery(?Collection $tenantIds): Builder
    {
        return $this->scopeTenants(IntegrationConnection::query(), $tenantIds);
    }

    private function syncRunQuery(?Collection $tenantIds): Builder
    {
        return IntegrationSyncRun::query()
            ->whereHas('connection', fn (Builder $query) => $this->scopeTenants($query, $tenantIds));
    }

    private function outboxQuery(?Collection $tenantIds): Builder
    {
        return IntegrationOutboxEvent::query()
            ->whereHas('connection', fn (Builder $query) => $this->scopeTenants($query, $tenantIds));
    }

    private function auditQuery(?Collection $tenantIds): Builder
    {
        return $this->scopeTenants(IntegrationAuditEvent::query(), $tenantIds);
    }

    private function scopeTenants(Builder $query, ?Collection $tenantIds): Builder
    {
        if ($tenantIds === null) {
            return $query;
        }

        return $query->whereIn('tenant_id', $tenantIds->all());
    }

    private function scopeSchools(Builder $query, ?Collection $schoolIds): Builder
    {
        if ($schoolIds === null) {
            return $query;
        }

        return $query->whereIn('school_id', $schoolIds->all());
    }
}
