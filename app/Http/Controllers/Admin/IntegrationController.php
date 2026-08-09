<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\V2\IntegrationAuditEvent;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\IntegrationOutboxEvent;
use App\Models\V2\IntegrationSyncItem;
use App\Models\V2\School as V2School;
use App\Models\V2\Tenant;
use App\Services\Integration\AttendanceOutboxDispatcher;
use App\Services\Integration\EduAdminConnectorFactory;
use App\Services\Integration\IntegrationAuditLogger;
use App\Services\Integration\SyncCoordinator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use RuntimeException;
use Throwable;

class IntegrationController extends Controller
{
    private const DEFAULT_CONNECTOR_SCOPES = [
        'foundation:read',
        'messages:read',
        'attendance:write',
    ];

    private const CONNECTOR_SCOPES = [
        'foundation:read',
        'messages:read',
        'attendance:write',
        'connector:*',
    ];

    public function index(): InertiaResponse
    {
        $admin = $this->admin()->load('school');
        $connections = $this->scopedConnectionQuery($admin)
            ->with('tenant')
            ->withCount(['mappings', 'syncRuns', 'outboxEvents', 'auditEvents'])
            ->latest()
            ->get();

        return Inertia::render('Admin/Integrations/Index', [
            'admin' => $admin,
            'isSuper' => $admin->isSuperAdmin(),
            'summary' => $this->summaryPayload($connections),
            'connections' => $connections->map(fn (IntegrationConnection $connection) => $this->connectionPayload($connection))->values(),
            'availableTenants' => $admin->isSuperAdmin() ? $this->availableTenantPayload() : [],
            'connectorScopes' => self::CONNECTOR_SCOPES,
            'connectorDefaultScopes' => self::DEFAULT_CONNECTOR_SCOPES,
            'recentOutboxEvents' => $this->recentOutboxPayload($connections),
            'recentAuditEvents' => $this->recentAuditPayload($connections),
            'recentSyncItems' => $this->recentSyncItemPayload($connections),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $admin = $this->admin();

        if (!$admin->isSuperAdmin()) {
            abort(403, 'Only super admins can create Edu-admin integration credentials.');
        }

        $validated = $this->validateCredentials($request, creating: true);
        $this->ensureProviderIsAvailable((int) $validated['tenant_id']);
        $this->ensureCredentialReadiness($validated);

        IntegrationConnection::query()->create(array_merge([
            'tenant_id' => (int) $validated['tenant_id'],
            'provider' => 'edu_admin',
            'mode' => 'connected',
        ], $this->credentialAttributes($validated)));
        $connection = IntegrationConnection::query()
            ->where('tenant_id', (int) $validated['tenant_id'])
            ->where('provider', 'edu_admin')
            ->firstOrFail();

        $this->recordCredentialAudit($connection, $admin, 'credentials.created', 'Edu-admin connection credentials created.', $validated, [], $this->credentialSnapshot($connection));

        return back()->with('success', 'Edu-admin connection credentials saved.');
    }

    public function updateCredentials(Request $request, IntegrationConnection $connection): RedirectResponse
    {
        $admin = $this->admin();

        if (!$admin->isSuperAdmin()) {
            abort(403, 'Only super admins can update Edu-admin integration credentials.');
        }

        $this->authorizeConnection($admin, $connection);

        $validated = $this->validateCredentials($request);
        $this->ensureCredentialReadiness($validated, $connection);
        $before = $this->credentialSnapshot($connection);

        $connection->fill($this->credentialAttributes($validated, $connection));
        $connection->save();
        $connection->refresh();

        $eventType = (($validated['clear_access_token'] ?? false) || ($validated['clear_webhook_secret'] ?? false))
            ? 'credentials.cleared'
            : 'credentials.updated';

        $this->recordCredentialAudit(
            $connection,
            $admin,
            $eventType,
            $eventType === 'credentials.cleared'
                ? 'Edu-admin connection credentials cleared.'
                : 'Edu-admin connection credentials updated.',
            $validated,
            $before,
            $this->credentialSnapshot($connection),
        );

        return back()->with('success', 'Edu-admin connection credentials updated.');
    }

    public function syncInitial(
        IntegrationConnection $connection,
        EduAdminConnectorFactory $connectors,
        SyncCoordinator $sync
    ): RedirectResponse {
        $this->authorizeConnection($this->admin(), $connection);

        if ($connection->mode !== 'connected' || $connection->status !== 'active') {
            return back()->withErrors([
                'integration' => 'The connection must be active and in connected mode before initial sync can run.',
            ]);
        }

        try {
            $connector = $connectors->make($connection);
            $run = $sync->runInitialSync($connection, $connector, [
                'triggered_by_type' => AdminUser::class,
                'triggered_by_id' => $this->admin()->id,
                'metadata' => [
                    'source' => 'admin_web',
                    'driver' => config('integrations.providers.edu_admin.driver', 'fixture'),
                ],
            ]);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['integration' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['integration' => 'Initial sync failed: ' . $exception->getMessage()]);
        }

        return back()->with(
            'success',
            sprintf(
                'Initial sync completed: read=%d created=%d updated=%d failed=%d.',
                $run->records_read,
                $run->records_created,
                $run->records_updated,
                $run->records_failed
            )
        );
    }

    public function syncIncremental(
        IntegrationConnection $connection,
        EduAdminConnectorFactory $connectors,
        SyncCoordinator $sync
    ): RedirectResponse {
        $this->authorizeConnection($this->admin(), $connection);

        if ($connection->mode !== 'connected' || $connection->status !== 'active') {
            return back()->withErrors([
                'integration' => 'The connection must be active and in connected mode before incremental sync can run.',
            ]);
        }

        try {
            $connector = $connectors->make($connection);
            $run = $sync->runIncrementalSync($connection, $connector, [
                'triggered_by_type' => AdminUser::class,
                'triggered_by_id' => $this->admin()->id,
                'metadata' => [
                    'source' => 'admin_web',
                    'driver' => config('integrations.providers.edu_admin.driver', 'fixture'),
                ],
            ]);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['integration' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['integration' => 'Incremental sync failed: ' . $exception->getMessage()]);
        }

        return back()->with(
            'success',
            sprintf(
                'Incremental sync completed: read=%d created=%d updated=%d failed=%d.',
                $run->records_read,
                $run->records_created,
                $run->records_updated,
                $run->records_failed
            )
        );
    }

    public function pushAttendance(
        IntegrationConnection $connection,
        EduAdminConnectorFactory $connectors,
        AttendanceOutboxDispatcher $dispatcher
    ): RedirectResponse {
        $this->authorizeConnection($this->admin(), $connection);

        if ($connection->mode !== 'connected' || $connection->status !== 'active') {
            return back()->withErrors([
                'integration' => 'The connection must be active and in connected mode before attendance can be pushed.',
            ]);
        }

        try {
            $connector = $connectors->make($connection);
            $queued = $dispatcher->enqueuePending($connection);
            $pushed = $dispatcher->dispatchPending($connection, $connector);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['integration' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['integration' => 'Attendance push failed: ' . $exception->getMessage()]);
        }

        return back()->with(
            'success',
            sprintf(
                'Attendance push completed: queued=%d sent=%d duplicates=%d failed=%d.',
                $queued['queued'],
                $pushed['sent'],
                $pushed['duplicates'],
                $queued['failed'] + $pushed['failed']
            )
        );
    }

    private function summaryPayload(Collection $connections): array
    {
        $connectionIds = $connections->pluck('id')->all();
        $outboxCounts = $this->outboxCounts($connectionIds);

        return [
            'total_connections' => $connections->count(),
            'active_connections' => $connections->where('status', 'active')->count(),
            'total_mappings' => $connections->sum('mappings_count'),
            'pending_outbox' => $outboxCounts['pending'] ?? 0,
            'failed_outbox' => $outboxCounts['failed'] ?? 0,
            'sent_outbox' => $outboxCounts['sent'] ?? 0,
            'last_successful_sync_at' => $connections
                ->pluck('last_successful_sync_at')
                ->filter()
                ->sortDesc()
                ->first()?->toIso8601String(),
        ];
    }

    private function connectionPayload(IntegrationConnection $connection): array
    {
        $outboxCounts = $this->outboxCounts([$connection->id]);

        return [
            'id' => $connection->id,
            'tenant' => $connection->tenant ? [
                'id' => $connection->tenant->id,
                'name' => $connection->tenant->name,
                'slug' => $connection->tenant->slug,
            ] : null,
            'provider' => $connection->provider,
            'mode' => $connection->mode,
            'status' => $connection->status,
            'base_url' => $connection->base_url,
            'api_version' => $connection->api_version,
            'remote_tenant_id' => $connection->remote_tenant_id,
            'scopes' => $connection->scopes ?? [],
            'feature_flags' => $connection->feature_flags ?? [],
            'has_access_token' => filled($connection->encrypted_access_token),
            'has_webhook_secret' => filled($connection->webhook_secret),
            'last_successful_sync_at' => $connection->last_successful_sync_at?->toIso8601String(),
            'last_failed_sync_at' => $connection->last_failed_sync_at?->toIso8601String(),
            'last_error' => $connection->last_error,
            'mappings_count' => $connection->mappings_count,
            'sync_runs_count' => $connection->sync_runs_count,
            'outbox_events_count' => $connection->outbox_events_count,
            'audit_events_count' => $connection->audit_events_count,
            'outbox_summary' => [
                'pending' => $outboxCounts['pending'] ?? 0,
                'failed' => $outboxCounts['failed'] ?? 0,
                'sent' => $outboxCounts['sent'] ?? 0,
            ],
            'recent_sync_runs' => $connection->syncRuns()
                ->latest('started_at')
                ->limit(5)
                ->get()
                ->map(fn ($run) => [
                    'id' => $run->id,
                    'sync_type' => $run->sync_type,
                    'direction' => $run->direction,
                    'status' => $run->status,
                    'records_read' => $run->records_read,
                    'records_created' => $run->records_created,
                    'records_updated' => $run->records_updated,
                    'records_failed' => $run->records_failed,
                    'started_at' => $run->started_at?->toIso8601String(),
                    'finished_at' => $run->finished_at?->toIso8601String(),
                    'error_message' => $run->error_message,
                ])
                ->values(),
        ];
    }

    private function recentOutboxPayload(Collection $connections): array
    {
        $connectionIds = $connections->pluck('id')->all();

        if (empty($connectionIds)) {
            return [];
        }

        return IntegrationOutboxEvent::query()
            ->with('connection.tenant')
            ->whereIn('connection_id', $connectionIds)
            ->latest()
            ->limit(12)
            ->get()
            ->map(fn (IntegrationOutboxEvent $event) => [
                'id' => $event->id,
                'connection_id' => $event->connection_id,
                'tenant_name' => $event->connection?->tenant?->name,
                'event_type' => $event->event_type,
                'event_key' => $event->event_key,
                'status' => $event->status,
                'attempts' => $event->attempts,
                'available_at' => $event->available_at?->toIso8601String(),
                'sent_at' => $event->sent_at?->toIso8601String(),
                'last_error' => $event->last_error,
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function recentAuditPayload(Collection $connections): array
    {
        $connectionIds = $connections->pluck('id')->all();

        if (empty($connectionIds)) {
            return [];
        }

        return IntegrationAuditEvent::query()
            ->with('connection.tenant')
            ->whereIn('connection_id', $connectionIds)
            ->latest('occurred_at')
            ->limit(20)
            ->get()
            ->map(fn (IntegrationAuditEvent $event) => [
                'id' => $event->id,
                'connection_id' => $event->connection_id,
                'tenant_name' => $event->connection?->tenant?->name,
                'category' => $event->category,
                'event_type' => $event->event_type,
                'severity' => $event->severity,
                'status' => $event->status,
                'summary' => $event->summary,
                'metadata' => $event->metadata ?? [],
                'actor_type' => $event->actor_type,
                'actor_id' => $event->actor_id,
                'related_type' => $event->related_type,
                'related_id' => $event->related_id,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function recentSyncItemPayload(Collection $connections): array
    {
        $connectionIds = $connections->pluck('id')->all();

        if (empty($connectionIds)) {
            return [];
        }

        return IntegrationSyncItem::query()
            ->with('syncRun.connection.tenant')
            ->whereHas('syncRun', fn (Builder $query) => $query->whereIn('connection_id', $connectionIds))
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (IntegrationSyncItem $item) => [
                'id' => $item->id,
                'sync_run_id' => $item->sync_run_id,
                'connection_id' => $item->syncRun?->connection_id,
                'tenant_name' => $item->syncRun?->connection?->tenant?->name,
                'sync_type' => $item->syncRun?->sync_type,
                'local_type' => $item->local_type,
                'local_id' => $item->local_id,
                'external_type' => $item->external_type,
                'external_id' => $item->external_id,
                'action' => $item->action,
                'status' => $item->status,
                'error_message' => $item->error_message,
                'created_at' => $item->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function availableTenantPayload(): array
    {
        $connectedTenantIds = IntegrationConnection::query()
            ->where('provider', 'edu_admin')
            ->pluck('tenant_id')
            ->all();

        return Tenant::query()
            ->when(!empty($connectedTenantIds), fn (Builder $query) => $query->whereNotIn('id', $connectedTenantIds))
            ->orderBy('name')
            ->get()
            ->map(fn (Tenant $tenant) => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
            ])
            ->values()
            ->all();
    }

    private function validateCredentials(Request $request, bool $creating = false): array
    {
        return $request->validate([
            'tenant_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:ec_tenants,id'],
            'base_url' => ['nullable', 'url', 'max:2048'],
            'api_version' => ['required', 'string', 'max:30'],
            'remote_tenant_id' => ['nullable', 'string', 'max:120'],
            'status' => ['required', 'string', Rule::in(['inactive', 'active', 'paused', 'error'])],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string', Rule::in(self::CONNECTOR_SCOPES)],
            'access_token' => ['nullable', 'string', 'max:4096'],
            'webhook_secret' => ['nullable', 'string', 'max:4096'],
            'clear_access_token' => ['sometimes', 'boolean'],
            'clear_webhook_secret' => ['sometimes', 'boolean'],
        ]);
    }

    private function credentialAttributes(array $validated, ?IntegrationConnection $connection = null): array
    {
        $attributes = [
            'base_url' => $validated['base_url'] ?? null,
            'api_version' => $validated['api_version'] ?? config('integrations.providers.edu_admin.api_version', 'v1'),
            'remote_tenant_id' => $validated['remote_tenant_id'] ?? null,
            'status' => $validated['status'],
            'scopes' => array_values($validated['scopes'] ?? self::DEFAULT_CONNECTOR_SCOPES),
        ];

        foreach ([
            'access_token' => 'encrypted_access_token',
            'webhook_secret' => 'webhook_secret',
        ] as $inputKey => $column) {
            $clearKey = 'clear_' . $inputKey;

            if (($validated[$clearKey] ?? false) === true) {
                $attributes[$column] = null;
            } elseif (filled($validated[$inputKey] ?? null)) {
                $attributes[$column] = Crypt::encryptString($validated[$inputKey]);
            } elseif (!$connection) {
                $attributes[$column] = null;
            }
        }

        return $attributes;
    }

    private function ensureCredentialReadiness(array $validated, ?IntegrationConnection $connection = null): void
    {
        if (($validated['status'] ?? $connection?->status) !== 'active') {
            return;
        }

        $baseUrl = $validated['base_url'] ?? $connection?->base_url;
        $hasAccessToken = filled($validated['access_token'] ?? null)
            || (!(bool) ($validated['clear_access_token'] ?? false) && filled($connection?->encrypted_access_token));
        $hasWebhookSecret = filled($validated['webhook_secret'] ?? null)
            || (!(bool) ($validated['clear_webhook_secret'] ?? false) && filled($connection?->webhook_secret));

        $errors = [];

        if (blank($baseUrl)) {
            $errors['base_url'] = 'A base URL is required before activating the Edu-admin connection.';
        }

        if (!$hasAccessToken) {
            $errors['access_token'] = 'An access token is required before activating the Edu-admin connection.';
        }

        if (!$hasWebhookSecret) {
            $errors['webhook_secret'] = 'A webhook secret is required before activating the Edu-admin connection.';
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function ensureProviderIsAvailable(int $tenantId): void
    {
        if (IntegrationConnection::query()->where('tenant_id', $tenantId)->where('provider', 'edu_admin')->exists()) {
            throw ValidationException::withMessages([
                'tenant_id' => 'This tenant already has an Edu-admin integration connection.',
            ]);
        }
    }

    private function recordCredentialAudit(
        IntegrationConnection $connection,
        AdminUser $actor,
        string $eventType,
        string $summary,
        array $validated,
        array $before,
        array $after,
    ): void {
        app(IntegrationAuditLogger::class)->record(
            $connection,
            'credentials',
            $eventType,
            $summary,
            [
                'before' => $before,
                'after' => $after,
                'changed_fields' => $this->credentialChangedFields($before, $after, $validated),
                'access_token_action' => $this->secretAction($validated, $before, 'access_token'),
                'webhook_secret_action' => $this->secretAction($validated, $before, 'webhook_secret'),
            ],
            'info',
            'completed',
            $actor,
            $connection,
        );
    }

    private function credentialSnapshot(IntegrationConnection $connection): array
    {
        return [
            'base_url' => $connection->base_url,
            'api_version' => $connection->api_version,
            'remote_tenant_id' => $connection->remote_tenant_id,
            'status' => $connection->status,
            'scopes' => array_values($connection->scopes ?? []),
            'has_access_token' => filled($connection->encrypted_access_token),
            'has_webhook_secret' => filled($connection->webhook_secret),
        ];
    }

    private function credentialChangedFields(array $before, array $after, array $validated): array
    {
        $fields = collect([
            'base_url',
            'api_version',
            'remote_tenant_id',
            'status',
            'scopes',
        ])
            ->filter(fn (string $field) => ($before[$field] ?? null) !== ($after[$field] ?? null))
            ->values()
            ->all();

        if ($this->secretAction($validated, $before, 'access_token') !== 'unchanged') {
            $fields[] = 'access_token';
        }

        if ($this->secretAction($validated, $before, 'webhook_secret') !== 'unchanged') {
            $fields[] = 'webhook_secret';
        }

        return array_values(array_unique($fields));
    }

    private function secretAction(array $validated, array $before, string $name): string
    {
        if (($validated['clear_' . $name] ?? false) === true) {
            return 'cleared';
        }

        if (!filled($validated[$name] ?? null)) {
            return 'unchanged';
        }

        $snapshotKey = $name === 'access_token' ? 'has_access_token' : 'has_webhook_secret';

        return ($before[$snapshotKey] ?? false) ? 'replaced' : 'set';
    }

    private function outboxCounts(array $connectionIds): array
    {
        if (empty($connectionIds)) {
            return [];
        }

        return IntegrationOutboxEvent::query()
            ->whereIn('connection_id', $connectionIds)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function scopedConnectionQuery(AdminUser $admin): Builder
    {
        $query = IntegrationConnection::query();

        if ($admin->isSuperAdmin()) {
            return $query;
        }

        return $query->whereIn('tenant_id', $this->tenantIdsForSchoolAdmin($admin)->all());
    }

    private function authorizeConnection(AdminUser $admin, IntegrationConnection $connection): void
    {
        if ($admin->isSuperAdmin()) {
            return;
        }

        if (!$this->tenantIdsForSchoolAdmin($admin)->contains((int) $connection->tenant_id)) {
            abort(403, 'You cannot access this integration connection.');
        }
    }

    private function tenantIdsForSchoolAdmin(AdminUser $admin): Collection
    {
        if (!$admin->school_id) {
            return collect();
        }

        return V2School::query()
            ->where('source_system', 'legacy')
            ->where('source_id', (string) $admin->school_id)
            ->pluck('tenant_id')
            ->map(fn ($tenantId) => (int) $tenantId)
            ->unique()
            ->values();
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }
}
