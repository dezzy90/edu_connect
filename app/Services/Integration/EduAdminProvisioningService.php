<?php

namespace App\Services\Integration;

use App\Jobs\V2\Integration\RunEduAdminInitialSyncJob;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\School;
use App\Models\V2\Student;
use App\Models\V2\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class EduAdminProvisioningService
{
    public function connect(array $payload): IntegrationConnection
    {
        $baseUrl = $this->validatedBaseUrl((string) $payload['edu_admin_base_url']);
        $complex = $payload['complex'];
        $schoolIds = collect($payload['school_ids'])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
        $existing = IntegrationConnection::query()
            ->where('provider', 'edu_admin')
            ->where('remote_tenant_id', (string) $complex['id'])
            ->where('provisioning_request_id', $payload['request_id'])
            ->first();

        if ($existing) {
            return $existing;
        }

        $connection = DB::transaction(function () use ($payload, $baseUrl, $complex, $schoolIds): IntegrationConnection {
            $tenant = Tenant::withTrashed()->firstOrNew([
                'source_system' => 'edu_admin',
                'source_id' => (string) $complex['id'],
            ]);

            $tenant->fill([
                'name' => $complex['name'],
                'slug' => $this->tenantSlug($tenant, (string) ($complex['slug'] ?? $complex['name']), (int) $complex['id']),
                'status' => $complex['status'] ?? 'active',
                'settings' => array_merge($tenant->settings ?? [], [
                    'edu_admin_complex_id' => (int) $complex['id'],
                ]),
            ])->save();

            if ($tenant->trashed()) {
                $tenant->restore();
            }

            $connection = IntegrationConnection::query()->firstOrNew([
                'tenant_id' => $tenant->id,
                'provider' => 'edu_admin',
            ]);

            $connection->forceFill([
                'mode' => 'connected',
                'base_url' => $baseUrl,
                'api_version' => 'v1',
                'remote_tenant_id' => (string) $complex['id'],
                'enabled_school_ids' => $schoolIds,
                'provisioning_request_id' => $payload['request_id'],
                'status' => 'active',
                'scopes' => array_values(array_unique($payload['scopes'])),
                'feature_flags' => config('integrations.feature_defaults.connected', []),
                'encrypted_access_token' => Crypt::encryptString($payload['access_token']),
                'webhook_secret' => Crypt::encryptString($payload['webhook_secret']),
                'last_error' => null,
            ])->save();

            return $connection;
        });

        RunEduAdminInitialSyncJob::dispatch($connection->id)
            ->onQueue((string) config('integrations.provisioning.edu_admin.queue', 'edu-connect'))
            ->afterCommit();

        return $connection->refresh();
    }

    public function disconnect(int $complexId): ?IntegrationConnection
    {
        $connection = IntegrationConnection::query()
            ->where('provider', 'edu_admin')
            ->where('remote_tenant_id', (string) $complexId)
            ->first();

        if (! $connection) {
            return null;
        }

        $connection->forceFill([
            'status' => 'inactive',
            'encrypted_access_token' => null,
            'encrypted_refresh_token' => null,
            'webhook_secret' => null,
            'last_error' => null,
        ])->save();

        return $connection->refresh();
    }

    public function deactivateSchoolsOutsideScope(IntegrationConnection $connection): void
    {
        $enabled = collect($connection->enabled_school_ids ?? [])->map(fn ($id) => (string) $id)->all();

        if ($enabled === []) {
            return;
        }

        $schoolIds = School::withTrashed()
            ->where('tenant_id', $connection->tenant_id)
            ->where('source_system', 'edu_admin')
            ->whereNotIn('source_id', $enabled)
            ->pluck('id');

        if ($schoolIds->isEmpty()) {
            return;
        }

        Student::query()
            ->where('tenant_id', $connection->tenant_id)
            ->whereIn('school_id', $schoolIds)
            ->update(['mobile_visible' => false]);

        School::query()
            ->whereIn('id', $schoolIds)
            ->update(['status' => 'inactive']);
    }

    private function validatedBaseUrl(string $baseUrl): string
    {
        $normalized = rtrim(trim($baseUrl), '/');
        $allowed = collect(config('integrations.provisioning.edu_admin.allowed_base_urls', []))
            ->map(fn ($url) => rtrim((string) $url, '/'))
            ->filter()
            ->values();

        if (! str_starts_with($normalized, 'https://') || ! $allowed->contains($normalized)) {
            throw new RuntimeException('The EduAdmin API URL is not approved for automatic provisioning.');
        }

        return $normalized;
    }

    private function tenantSlug(Tenant $tenant, string $candidate, int $complexId): string
    {
        if ($tenant->exists && filled($tenant->slug)) {
            return $tenant->slug;
        }

        $base = Str::slug($candidate) ?: "edu-admin-{$complexId}";
        $slug = $base;
        $suffix = 0;

        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$complexId}".($suffix > 1 ? "-{$suffix}" : '');
        }

        return $slug;
    }
}
