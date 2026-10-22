<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use App\Models\AdminUser;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class OperatorAccess
{
    public const ROLES = ['guard', 'discipline_master', 'correction_approver'];

    public function actor(mixed $actor): AdminUser
    {
        abort_unless($actor instanceof AdminUser && $actor->is_active && ! $actor->test_recovery_only, 403);
        abort_unless(config('gate.enabled') && config('gate.operator_enabled'), 409, 'Gate workspace is disabled.');
        $schools = config('gate.school_ids');
        abort_unless(is_array($schools) && array_is_list($schools) && $schools !== []
            && ! array_filter($schools, static fn ($id) => ! is_int($id) || $id < 1), 409, 'Gate scope is not configured.');

        return $actor;
    }

    public function assignments(AdminUser $actor): Builder
    {
        return DB::table('ec_gate_operator_assignments as a')->join('ec_schools as s', function ($q): void {
            $q->on('s.id', '=', 'a.school_id')->on('s.tenant_id', '=', 'a.tenant_id');
        })->join('ec_tenants as t', 't.id', '=', 'a.tenant_id')
            ->where('a.admin_id', $actor->id)->where('a.active', true)->whereIn('a.role', self::ROLES)
            ->whereIn('a.school_id', config('gate.school_ids'))->where('s.status', 'active')->whereNull('s.deleted_at')
            ->where('t.status', 'active')->whereNull('t.deleted_at');
    }

    public function roles(AdminUser $actor, object $day, bool $locked = false): array
    {
        $this->actor($actor);
        if ($locked) {
            // The caller locks the day first, as does the asynchronous projector.
            $fresh = AdminUser::whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($fresh && $fresh->is_active && ! $fresh->test_recovery_only, 403);
        }
        $query = $this->assignments($actor)->where('a.tenant_id', $day->tenant_id)->where('a.school_id', $day->school_id);
        if ($locked) {
            $query->lockForUpdate();
        }
        $roles = $query->pluck('a.role')->all();
        abort_unless($roles !== [], 404);

        return $roles;
    }
}
