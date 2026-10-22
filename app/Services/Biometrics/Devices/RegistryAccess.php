<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Devices;

use App\Models\AdminUser;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class RegistryAccess
{
    public function enabled(): bool
    {
        $ids = config('device_registry.school_ids');
        $tenants = config('device_registry.tenant_ids');

        return config('device_registry.enabled') === true && is_array($ids) && array_is_list($ids) && $ids !== []
            && ! array_filter($ids, static fn ($id) => ! is_int($id) || $id < 1) && count($ids) === count(array_unique($ids))
            && is_array($tenants) && array_is_list($tenants) && $tenants !== []
            && ! array_filter($tenants, static fn ($id) => ! is_int($id) || $id < 1) && count($tenants) === count(array_unique($tenants));
    }

    public function actor(mixed $actor): AdminUser
    {
        abort_unless($actor instanceof AdminUser && $actor->is_active && ! $actor->test_recovery_only, 403);
        abort_unless($this->enabled(), 409, 'Device administration is disabled or its school scope is not configured.');

        return $actor;
    }

    public function schools(AdminUser $actor): Builder
    {
        return DB::table('ec_device_admin_assignments as a')->join('ec_tenants as t', 't.id', '=', 'a.tenant_id')
            ->join('ec_schools as s', 's.tenant_id', '=', 'a.tenant_id')->where('a.admin_id', $actor->id)->where('a.active', true)
            ->whereIn('a.tenant_id', config('device_registry.tenant_ids', []))->whereIn('s.id', config('device_registry.school_ids', []))
            ->where('s.status', 'active')->whereNull('s.deleted_at')
            ->where('t.status', 'active')->whereNull('t.deleted_at');
    }

    public function school(AdminUser $actor, int $school, bool $locked = false): object
    {
        $this->actor($actor);
        if ($locked) {
            $fresh = AdminUser::whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($fresh && $fresh->is_active && ! $fresh->test_recovery_only, 403);
        }
        $q = $this->schools($actor)->where('s.id', $school);
        if ($locked) {
            $q->lockForUpdate();
        }
        $row = $q->first(['a.tenant_id', 's.id as school_id', 's.name', 's.timezone', 's.source_system', 's.source_id', 't.name as complex_name']);
        abort_unless($row, 404);

        return $row;
    }

    public function devices(AdminUser $actor): Builder
    {
        return DB::table('ec_biometric_devices as d')->whereExists(function ($q) use ($actor): void {
            $q->selectRaw('1')->fromSub($this->schools($actor)->select('a.tenant_id', 's.id as school_id'), 'granted')
                ->whereColumn('granted.school_id', 'd.school_id')->whereColumn('granted.tenant_id', 'd.tenant_id');
        })->whereNotExists(function ($q) use ($actor): void {
            // A shared installation is an indivisible administrative scope. Never
            // leak a partial school list or fail the whole inventory on one row.
            $q->selectRaw('1')->from('ec_device_school_assignments as m')->whereColumn('m.device_id', 'd.id')
                ->where('m.active', true)->whereNull('m.revoked_at')->where(function ($invalid) use ($actor): void {
                    $invalid->whereColumn('m.tenant_id', '!=', 'd.tenant_id')->orWhereNotExists(function ($grant) use ($actor): void {
                        $grant->selectRaw('1')->fromSub($this->schools($actor)->select('a.tenant_id', 's.id as school_id'), 'member_grant')
                            ->whereColumn('member_grant.school_id', 'm.school_id')->whereColumn('member_grant.tenant_id', 'm.tenant_id');
                    });
                });
        })->whereNull('d.deleted_at');
    }

    public function device(AdminUser $actor, int $id): object
    {
        $this->actor($actor);
        $row = $this->devices($actor)->where('d.id', $id)->first();
        abort_unless($row, 404);

        return $row;
    }

    public function canEnter(mixed $actor): bool
    {
        return $this->enabled() && $actor instanceof AdminUser && $actor->is_active && ! $actor->test_recovery_only
            && $this->schools($actor)->exists();
    }
}
