<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use App\Models\AdminUser;
use App\Models\V2\IntegrationConnection;
use App\Models\V2\School;
use App\Models\V2\Tenant;
use App\Services\Integration\MappingService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Server-operator preparation only: no HTTP, jobs, portraits, people, device commands or publication. */
final class InstallationPreparation
{
    public function prepare(array $source, string $email, string $requestId): array
    {
        $strict = static function ($attribute, $value, $fail): void {
            if (! is_int($value) || $value < 1) {
                $fail('Use a positive source ID.');
            }
        };
        validator(['source' => $source, 'email' => $email, 'request_id' => $requestId], [
            'request_id' => ['required', 'uuid'], 'email' => ['required', 'email', 'max:255'],
            'source' => ['required', 'array:origin,complex,schools,access_token,webhook_secret,credential_id'],
            'source.origin' => ['required', 'string', 'max:253'],
            'source.complex' => ['required', 'array:id,name,slug,status'], 'source.complex.id' => ['required', $strict],
            'source.complex.name' => ['required', 'string', 'max:200'], 'source.complex.slug' => ['required', 'string', 'regex:/^[a-z0-9-]{1,180}$/D'],
            'source.complex.status' => ['required', 'in:active'], 'source.schools' => ['required', 'array', 'min:1', 'max:20'],
            'source.schools.*' => ['array:id,complex_id,name,type,status'], 'source.schools.*.id' => ['required', 'distinct', $strict],
            'source.schools.*.complex_id' => ['required', $strict], 'source.schools.*.name' => ['required', 'string', 'max:200'],
            'source.schools.*.type' => ['required', 'string', 'max:50'], 'source.schools.*.status' => ['required', 'in:active'],
            'source.access_token' => ['required', 'string', 'min:40', 'max:255'], 'source.webhook_secret' => ['required', 'string', 'min:40', 'max:255'],
            'source.credential_id' => ['required', $strict],
        ])->validate();
        $origin = rtrim($source['origin'], '/');
        $parts = parse_url($origin);
        if (! array_is_list($source['schools']) || ! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || ! in_array($origin, config('integrations.provisioning.edu_admin.allowed_base_urls', []), true)
            || array_filter($source['schools'], fn ($school) => $school['complex_id'] !== $source['complex']['id'])) {
            throw new RuntimeException('Preparation requires one approved source complex and its exact active schools.');
        }
        foreach (['gate.enabled', 'gate.activation_enabled', 'gate.activation_release_ready', 'gate.parent_delivery_enabled',
            'gate.mirror_delivery_enabled', 'hikvision_push.enabled', 'hikvision_push.enrollment_enabled', 'hikvision_push.enrollment_device_writes_enabled'] as $flag) {
            if (config($flag) !== false) {
                throw new RuntimeException('Live processing must remain disabled during installation preparation.');
            }
        }

        return DB::transaction(function () use ($source, $email, $requestId, $origin): array {
            $tenant = Tenant::withTrashed()->where('source_system', 'edu_admin')->where('source_id', (string) $source['complex']['id'])->lockForUpdate()->first();
            if ($tenant && ($tenant->trashed() || $tenant->status !== 'active' || ($tenant->settings['gate_preparation_request_id'] ?? null) !== $requestId)) {
                throw new RuntimeException('An existing complex requires explicit migration, not a new preparation.');
            }
            if (! $tenant) {
                $tenant = Tenant::create(['source_system' => 'edu_admin', 'source_id' => (string) $source['complex']['id'],
                    'name' => $source['complex']['name'], 'slug' => 'edu-admin-'.$source['complex']['id'].'-'.substr($requestId, 0, 8),
                    'status' => 'active', 'settings' => ['gate_preparation_request_id' => $requestId]]);
            }
            $account = AdminUser::where('email', mb_strtolower($email))->lockForUpdate()->first();
            if ($account && (($tenant->settings['gate_setup_owner_id'] ?? null) !== $account->id || ! $account->gate_only
                || $account->test_recovery_only || $account->role !== 'school_admin' || $account->school_id !== null)) {
                throw new RuntimeException('An existing account cannot be repurposed by installation preparation.');
            }
            if (! $account) {
                $account = AdminUser::create(['name' => 'School installation coordinator', 'email' => mb_strtolower($email),
                    'password' => bin2hex(random_bytes(32)), 'role' => 'school_admin', 'is_active' => false]);
                $account->forceFill(['gate_only' => true])->save();
                $tenant->update(['settings' => $tenant->settings + ['gate_setup_owner_id' => $account->id]]);
            }
            $connection = IntegrationConnection::where('tenant_id', $tenant->id)->where('provider', 'edu_admin')->lockForUpdate()->first();
            if ($connection && ($connection->provisioning_request_id !== $requestId || $connection->status !== 'inactive'
                || $connection->base_url !== $origin || ($connection->feature_flags['source_credential_id'] ?? null) !== $source['credential_id']
                || array_filter(array_intersect_key($connection->feature_flags ?? [], array_flip(['attendance', 'messages', 'push_notifications', 'realtime_updates', 'two_way_chat'])))
                || Crypt::decryptString($connection->encrypted_access_token) !== $source['access_token']
                || Crypt::decryptString($connection->webhook_secret) !== $source['webhook_secret'])) {
                throw new RuntimeException('Connection identity or live state changed; do not overwrite it.');
            }
            $connection ??= IntegrationConnection::create(['tenant_id' => $tenant->id, 'provider' => 'edu_admin', 'mode' => 'connected',
                'status' => 'inactive', 'base_url' => $origin, 'api_version' => 'v1', 'remote_tenant_id' => (string) $source['complex']['id'],
                'provisioning_request_id' => $requestId, 'scopes' => ['foundation:read', 'gate:read', 'gate:write'],
                'feature_flags' => ['attendance' => false, 'messages' => false, 'push_notifications' => false, 'realtime_updates' => false,
                    'two_way_chat' => false, 'gate_preparation_request_id' => $requestId, 'source_credential_id' => $source['credential_id']],
                'encrypted_access_token' => Crypt::encryptString($source['access_token']), 'webhook_secret' => Crypt::encryptString($source['webhook_secret'])]);
            $mapped = [];
            foreach ($source['schools'] as $remote) {
                $school = School::withTrashed()->where('source_system', 'edu_admin')->where('source_id', (string) $remote['id'])->lockForUpdate()->first();
                if ($school && ((int) $school->tenant_id !== $tenant->id || $school->trashed() || $school->status !== 'active')) {
                    throw new RuntimeException('A source school is already assigned to another installation state.');
                }
                $school ??= School::create(['tenant_id' => $tenant->id, 'source_system' => 'edu_admin', 'source_id' => (string) $remote['id'],
                    'name' => $remote['name'], 'slug' => 'edu-admin-school-'.$remote['id'].'-'.substr($requestId, 0, 8), 'type' => $remote['type'],
                    'status' => 'active', 'timezone' => 'Africa/Douala']);
                app(MappingService::class)->upsert($connection, 'school', $school->id, 'school', (string) $remote['id']);
                $binding = DB::table('ec_gate_mirror_bindings')->where('school_id', $school->id)->first();
                if ($binding && ($binding->enabled || (int) $binding->tenant_id !== $tenant->id || (int) $binding->connection_id !== $connection->id
                    || (int) $binding->remote_school_id !== $remote['id'])) {
                    throw new RuntimeException('An existing mirror binding cannot be replaced by preparation.');
                }
                if (! $binding) {
                    DB::table('ec_gate_mirror_bindings')->insert(['tenant_id' => $tenant->id, 'school_id' => $school->id,
                        'connection_id' => $connection->id, 'remote_school_id' => $remote['id'], 'source_id' => (string) Str::uuid(), 'enabled' => false]);
                    $binding = DB::table('ec_gate_mirror_bindings')->where('school_id', $school->id)->sole();
                }
                $this->grant('ec_gate_setup_assignments', ['tenant_id' => $tenant->id, 'school_id' => $school->id, 'admin_id' => $account->id]);
                $mapped[] = ['local_id' => $school->id, 'source_id' => $remote['id'], 'source_uuid' => $binding->source_id];
            }
            $this->grant('ec_device_admin_assignments', ['tenant_id' => $tenant->id, 'admin_id' => $account->id]);
            $ids = array_column($mapped, 'local_id');
            sort($ids);
            if ($connection->enabled_school_ids !== null && $connection->enabled_school_ids !== $ids) {
                throw new RuntimeException('The prepared school membership changed; review it explicitly.');
            }
            $connection->update(['enabled_school_ids' => $ids]);

            return ['tenant_id' => $tenant->id, 'admin_id' => $account->id, 'account_active' => $account->is_active,
                'connection_id' => $connection->id, 'source_credential_id' => $source['credential_id'], 'schools' => $mapped,
                'connection_active' => false, 'student_import_started' => false, 'terminal_registered' => false];
        });
    }

    private function grant(string $table, array $keys): void
    {
        $existing = DB::table($table)->where($keys)->lockForUpdate()->first();
        if ($existing && ! $existing->active) {
            throw new RuntimeException('A revoked preparation grant requires separate authorization.');
        }
        if (! $existing) {
            DB::table($table)->insert($keys + ['active' => true]);
        }
    }
}
