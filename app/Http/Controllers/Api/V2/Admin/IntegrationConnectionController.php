<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\School;
use App\Models\V2\Tenant;
use App\Services\Integration\EduAdminConnectorFactory;
use App\Services\Integration\SyncCoordinator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class IntegrationConnectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = IntegrationConnection::query()
            ->with('tenant')
            ->withCount(['mappings', 'syncRuns'])
            ->latest();

        $this->scopeToAdmin($query, $this->admin($request));

        if ($request->filled('provider')) {
            $query->where('provider', $request->string('provider')->toString());
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->get()->map(fn (IntegrationConnection $connection) => $this->connectionPayload($connection)),
        ]);
    }

    public function store(Request $request, EduAdminConnectorFactory $connectors): JsonResponse
    {
        $admin = $this->admin($request);
        $validated = $this->validateStore($request);
        $tenant = $this->resolveTenantForStore($admin, $validated, $connectors);
        $tenantId = (int) $tenant['tenant_id'];

        if (empty($validated['remote_tenant_id']) && !empty($tenant['remote_tenant_id'])) {
            $validated['remote_tenant_id'] = $tenant['remote_tenant_id'];
        }

        $this->ensureProviderIsAvailable($tenantId);

        $connection = IntegrationConnection::query()->create($this->connectionAttributes($validated, $tenantId));

        return response()->json([
            'status' => 'success',
            'data' => $this->connectionPayload($this->loadConnectionForPayload($connection)),
        ], 201);
    }

    public function show(Request $request, IntegrationConnection $connection): JsonResponse
    {
        $this->authorizeConnection($this->admin($request), $connection);

        return response()->json([
            'status' => 'success',
            'data' => $this->connectionPayload($this->loadConnectionForPayload($connection)),
        ]);
    }

    public function update(Request $request, IntegrationConnection $connection): JsonResponse
    {
        $this->authorizeConnection($this->admin($request), $connection);

        $validated = $this->validateUpdate($request);
        $connection->fill($this->connectionAttributes($validated, partial: true));
        $connection->save();

        return response()->json([
            'status' => 'success',
            'data' => $this->connectionPayload($this->loadConnectionForPayload($connection)),
        ]);
    }

    public function destroy(Request $request, IntegrationConnection $connection): JsonResponse
    {
        $this->authorizeConnection($this->admin($request), $connection);

        if ($connection->mappings()->exists() || $connection->syncRuns()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Connection history exists. Set the connection status to inactive instead of deleting it.',
            ], 409);
        }

        $connection->delete();

        return response()->json([
            'status' => 'success',
            'data' => null,
        ]);
    }

    public function syncInitial(
        Request $request,
        IntegrationConnection $connection,
        EduAdminConnectorFactory $connectors,
        SyncCoordinator $sync
    ): JsonResponse {
        $admin = $this->admin($request);
        $this->authorizeConnection($admin, $connection);

        if ($connection->mode !== 'connected' || $connection->status !== 'active') {
            return response()->json([
                'status' => 'error',
                'message' => 'The connection must be active and in connected mode before initial sync can run.',
            ], 409);
        }

        $validated = $request->validate([
            'driver' => ['nullable', 'string', Rule::in(['fixture', 'http'])],
            'fixture_path' => ['nullable', 'string', 'max:1024'],
        ]);

        try {
            $connector = $connectors->make($connection, $validated);
            $run = $sync->runInitialSync($connection, $connector, [
                'triggered_by_type' => AdminUser::class,
                'triggered_by_id' => $admin->id,
                'metadata' => [
                    'source' => 'admin_api',
                    'driver' => $validated['driver'] ?? config('integrations.providers.edu_admin.driver', 'fixture'),
                ],
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'connection' => $this->connectionPayload($this->loadConnectionForPayload($connection->refresh())),
                'sync_run' => $this->syncRunPayload($run),
            ],
        ]);
    }

    public function syncIncremental(
        Request $request,
        IntegrationConnection $connection,
        EduAdminConnectorFactory $connectors,
        SyncCoordinator $sync
    ): JsonResponse {
        $admin = $this->admin($request);
        $this->authorizeConnection($admin, $connection);

        if ($connection->mode !== 'connected' || $connection->status !== 'active') {
            return response()->json([
                'status' => 'error',
                'message' => 'The connection must be active and in connected mode before incremental sync can run.',
            ], 409);
        }

        $validated = $request->validate([
            'driver' => ['nullable', 'string', Rule::in(['fixture', 'http'])],
            'fixture_path' => ['nullable', 'string', 'max:1024'],
            'updated_after' => ['nullable', 'date'],
            'cursor' => ['nullable', 'string', 'max:191'],
            'resources' => ['nullable', 'array'],
            'resources.*' => ['string', Rule::in([
                'schools',
                'academic_years',
                'sections',
                'education_options',
                'streams',
                'classes',
                'students',
                'parent_links',
                'mobile_messages',
                'student_mobile_profiles',
            ])],
        ]);

        try {
            $connector = $connectors->make($connection, $validated);
            $run = $sync->runIncrementalSync($connection, $connector, [
                'triggered_by_type' => AdminUser::class,
                'triggered_by_id' => $admin->id,
                'updated_after' => $validated['updated_after'] ?? null,
                'cursor' => $validated['cursor'] ?? null,
                'resources' => $validated['resources'] ?? null,
                'metadata' => [
                    'source' => 'admin_api',
                    'driver' => $validated['driver'] ?? config('integrations.providers.edu_admin.driver', 'fixture'),
                    'resources' => $validated['resources'] ?? null,
                ],
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'connection' => $this->connectionPayload($this->loadConnectionForPayload($connection->refresh())),
                'sync_run' => $this->syncRunPayload($run),
            ],
        ]);
    }

    public function syncRuns(Request $request, IntegrationConnection $connection): JsonResponse
    {
        $this->authorizeConnection($this->admin($request), $connection);

        $limit = min(max($request->integer('limit', 20), 1), 100);

        return response()->json([
            'status' => 'success',
            'data' => $connection->syncRuns()
                ->latest('started_at')
                ->limit($limit)
                ->get()
                ->map(fn ($run) => $this->syncRunPayload($run)),
        ]);
    }

    private function validateStore(Request $request): array
    {
        return $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:ec_tenants,id'],
            'mode' => ['nullable', 'string', Rule::in(['standalone', 'connected'])],
            'base_url' => ['nullable', 'url', 'max:2048'],
            'api_version' => ['nullable', 'string', 'max:30'],
            'remote_tenant_id' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in(['inactive', 'active', 'paused', 'error'])],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string', 'max:100'],
            'feature_flags' => ['nullable', 'array'],
            'feature_flags.*' => ['boolean'],
            'access_token' => ['nullable', 'string', 'max:4096'],
            'refresh_token' => ['nullable', 'string', 'max:4096'],
            'webhook_secret' => ['nullable', 'string', 'max:4096'],
        ]);
    }

    private function validateUpdate(Request $request): array
    {
        return $request->validate([
            'mode' => ['sometimes', 'string', Rule::in(['standalone', 'connected'])],
            'base_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'api_version' => ['sometimes', 'string', 'max:30'],
            'remote_tenant_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'string', Rule::in(['inactive', 'active', 'paused', 'error'])],
            'scopes' => ['sometimes', 'nullable', 'array'],
            'scopes.*' => ['string', 'max:100'],
            'feature_flags' => ['sometimes', 'nullable', 'array'],
            'feature_flags.*' => ['boolean'],
            'access_token' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'refresh_token' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'clear_access_token' => ['sometimes', 'boolean'],
            'clear_refresh_token' => ['sometimes', 'boolean'],
            'clear_webhook_secret' => ['sometimes', 'boolean'],
        ]);
    }

    private function admin(Request $request): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = $request->user();

        return $admin;
    }

    private function connectionAttributes(array $validated, ?int $tenantId = null, bool $partial = false): array
    {
        $attributes = $partial ? [] : [
            'provider' => 'edu_admin',
            'mode' => 'connected',
            'base_url' => null,
            'api_version' => config('integrations.providers.edu_admin.api_version'),
            'remote_tenant_id' => null,
            'status' => 'inactive',
            'scopes' => null,
            'feature_flags' => null,
        ];

        if ($tenantId !== null) {
            $attributes['tenant_id'] = $tenantId;
        }

        foreach ([
            'mode',
            'base_url',
            'api_version',
            'remote_tenant_id',
            'status',
            'scopes',
            'feature_flags',
        ] as $key) {
            if (array_key_exists($key, $validated)) {
                $attributes[$key] = $validated[$key];
            }
        }

        foreach ([
            'access_token' => 'encrypted_access_token',
            'refresh_token' => 'encrypted_refresh_token',
            'webhook_secret' => 'webhook_secret',
        ] as $inputKey => $column) {
            $clearKey = 'clear_' . $inputKey;

            if (($validated[$clearKey] ?? false) === true) {
                $attributes[$column] = null;
            } elseif (array_key_exists($inputKey, $validated) && filled($validated[$inputKey])) {
                $attributes[$column] = Crypt::encryptString($validated[$inputKey]);
            }
        }

        return $attributes;
    }

    private function resolveTenantForStore(AdminUser $admin, array $validated, EduAdminConnectorFactory $connectors): array
    {
        if ($admin->isSuperAdmin()) {
            if (!empty($validated['tenant_id'])) {
                return ['tenant_id' => (int) $validated['tenant_id']];
            }

            return $this->createTenantFromEduAdminBootstrap($validated, $connectors);
        }

        $tenantIds = $this->tenantIdsForSchoolAdmin($admin);

        if ($tenantIds->isEmpty()) {
            abort(403, 'This school admin is not mapped to a v2 tenant yet.');
        }

        if (!empty($validated['tenant_id']) && !$tenantIds->contains((int) $validated['tenant_id'])) {
            abort(403, 'You cannot create an integration connection for this tenant.');
        }

        if ($tenantIds->count() > 1 && empty($validated['tenant_id'])) {
            throw ValidationException::withMessages([
                'tenant_id' => 'A tenant is required because this school admin can access multiple v2 tenants.',
            ]);
        }

        return ['tenant_id' => (int) ($validated['tenant_id'] ?? $tenantIds->first())];
    }

    private function createTenantFromEduAdminBootstrap(array $validated, EduAdminConnectorFactory $connectors): array
    {
        if (($validated['mode'] ?? 'connected') !== 'connected') {
            throw ValidationException::withMessages([
                'tenant_id' => 'Choose an existing tenant when creating a standalone connection.',
            ]);
        }

        if (blank($validated['base_url'] ?? null)) {
            throw ValidationException::withMessages([
                'base_url' => 'The Edu-admin API root URL is required when creating a tenant automatically.',
            ]);
        }

        $temporaryConnection = new IntegrationConnection([
            'provider' => 'edu_admin',
            'mode' => $validated['mode'] ?? 'connected',
            'status' => $validated['status'] ?? 'active',
            'base_url' => $validated['base_url'],
            'api_version' => $validated['api_version'] ?? config('integrations.providers.edu_admin.api_version'),
            'remote_tenant_id' => $validated['remote_tenant_id'] ?? null,
            'encrypted_access_token' => filled($validated['access_token'] ?? null)
                ? Crypt::encryptString($validated['access_token'])
                : null,
            'webhook_secret' => filled($validated['webhook_secret'] ?? null)
                ? Crypt::encryptString($validated['webhook_secret'])
                : null,
        ]);

        try {
            $bootstrap = $connectors->make($temporaryConnection)->bootstrap();
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'access_token' => $exception->getMessage(),
            ]);
        }

        $complex = $bootstrap['complex'] ?? [];

        if (empty($complex['id'])) {
            throw ValidationException::withMessages([
                'base_url' => 'Edu-admin did not return a valid academic complex for this credential.',
            ]);
        }

        $remoteTenantId = (string) $complex['id'];

        if (!empty($validated['remote_tenant_id']) && (string) $validated['remote_tenant_id'] !== $remoteTenantId) {
            throw ValidationException::withMessages([
                'remote_tenant_id' => 'The remote tenant ID does not match the Edu-admin credential complex.',
            ]);
        }

        $tenant = Tenant::withTrashed()
            ->where('source_system', 'edu_admin')
            ->where('source_id', $remoteTenantId)
            ->first();

        $attributes = [
            'name' => $complex['name'] ?? 'Edu-admin Complex',
            'slug' => $this->uniqueTenantSlug((string) ($complex['slug'] ?? $complex['name'] ?? 'edu-admin-complex'), $tenant?->id),
            'code' => $complex['code'] ?? null,
            'status' => $complex['status'] ?? 'active',
            'source_system' => 'edu_admin',
            'source_id' => $remoteTenantId,
            'settings' => [
                'linked_from' => 'edu_admin',
                'linked_at' => now()->toIso8601String(),
            ],
        ];

        if ($tenant instanceof Tenant) {
            $tenant->restore();
            $tenant->fill($attributes)->save();
        } else {
            $tenant = Tenant::query()->create($attributes);
        }

        return [
            'tenant_id' => (int) $tenant->id,
            'remote_tenant_id' => $remoteTenantId,
        ];
    }

    private function uniqueTenantSlug(string $value, ?int $ignoreTenantId = null): string
    {
        $base = Str::slug($value) ?: 'edu-admin-complex';
        $slug = $base;
        $counter = 2;

        while (
            Tenant::withTrashed()
                ->where('slug', $slug)
                ->when($ignoreTenantId, fn (Builder $query) => $query->whereKeyNot($ignoreTenantId))
                ->exists()
        ) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function ensureProviderIsAvailable(int $tenantId): void
    {
        if (IntegrationConnection::query()->where('tenant_id', $tenantId)->where('provider', 'edu_admin')->exists()) {
            throw ValidationException::withMessages([
                'provider' => 'This tenant already has an Edu-admin integration connection.',
            ]);
        }
    }

    private function scopeToAdmin(Builder $query, AdminUser $admin): void
    {
        if ($admin->isSuperAdmin()) {
            return;
        }

        $tenantIds = $this->tenantIdsForSchoolAdmin($admin);

        $query->whereIn('tenant_id', $tenantIds->all());
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

        return School::query()
            ->where('source_system', 'legacy')
            ->where('source_id', (string) $admin->school_id)
            ->pluck('tenant_id')
            ->map(fn ($tenantId) => (int) $tenantId)
            ->unique()
            ->values();
    }

    private function loadConnectionForPayload(IntegrationConnection $connection): IntegrationConnection
    {
        return $connection->load('tenant')->loadCount(['mappings', 'syncRuns']);
    }

    private function connectionPayload(IntegrationConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'tenant_id' => $connection->tenant_id,
            'tenant' => $connection->tenant ? [
                'id' => $connection->tenant->id,
                'name' => $connection->tenant->name,
                'slug' => $connection->tenant->slug,
                'status' => $connection->tenant->status,
            ] : null,
            'provider' => $connection->provider,
            'mode' => $connection->mode,
            'base_url' => $connection->base_url,
            'api_version' => $connection->api_version,
            'remote_tenant_id' => $connection->remote_tenant_id,
            'status' => $connection->status,
            'scopes' => $connection->scopes,
            'feature_flags' => $connection->feature_flags,
            'last_successful_sync_at' => $connection->last_successful_sync_at?->toIso8601String(),
            'last_failed_sync_at' => $connection->last_failed_sync_at?->toIso8601String(),
            'last_error' => $connection->last_error,
            'mappings_count' => $connection->mappings_count ?? $connection->mappings()->count(),
            'sync_runs_count' => $connection->sync_runs_count ?? $connection->syncRuns()->count(),
            'created_at' => $connection->created_at?->toIso8601String(),
            'updated_at' => $connection->updated_at?->toIso8601String(),
        ];
    }

    private function syncRunPayload($run): array
    {
        return [
            'id' => $run->id,
            'connection_id' => $run->connection_id,
            'sync_type' => $run->sync_type,
            'direction' => $run->direction,
            'status' => $run->status,
            'triggered_by_type' => $run->triggered_by_type,
            'triggered_by_id' => $run->triggered_by_id,
            'records_read' => $run->records_read,
            'records_created' => $run->records_created,
            'records_updated' => $run->records_updated,
            'records_deleted' => $run->records_deleted,
            'records_failed' => $run->records_failed,
            'error_message' => $run->error_message,
            'metadata' => $run->metadata,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }
}
