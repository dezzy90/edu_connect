<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use App\Models\AdminUser;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** An explicit preparation grant, not a live-policy or staff-permission grant. */
final class SetupAccess
{
    public function enabled(): bool
    {
        $ids = config('gate.setup_school_ids');

        return config('gate.setup_enabled') === true && is_array($ids) && array_is_list($ids) && $ids !== []
            && ! array_filter($ids, static fn ($id) => ! is_int($id) || $id < 1)
            && count($ids) === count(array_unique($ids));
    }

    public function actor(mixed $actor): AdminUser
    {
        abort_unless($actor instanceof AdminUser && $actor->is_active && ! $actor->test_recovery_only, 403);
        abort_if($actor->gate_source_only && app(SourceWorkspaceContext::class)->actorId !== (int) $actor->id, 403);
        abort_unless($this->enabled(), 409, 'School setup is disabled or its preparation scope is not configured.');

        return $actor;
    }

    public function schools(AdminUser $actor): Builder
    {
        $query = DB::table('ec_gate_setup_assignments as a')->join('ec_schools as s', function ($q): void {
            $q->on('s.id', '=', 'a.school_id')->on('s.tenant_id', '=', 'a.tenant_id');
        })->join('ec_tenants as t', 't.id', '=', 'a.tenant_id')
            ->where('a.admin_id', $actor->id)->where('a.active', true)->whereIn('a.school_id', config('gate.setup_school_ids', []))
            ->where('s.status', 'active')->whereNull('s.deleted_at')->where('t.status', 'active')->whereNull('t.deleted_at');
        if ($actor->gate_source_only) {
            $ids = array_keys(array_filter(app(SourceWorkspaceContext::class)->permissionsBySchool, fn ($p) => in_array('configure', $p, true)));
            $query->whereIn('a.school_id', app(SourceWorkspaceContext::class)->actorId === (int) $actor->id ? $ids : []);
        }
        return $query;
    }

    public function school(AdminUser $actor, int $school, bool $locked = false): object
    {
        $this->actor($actor);
        if ($locked) {
            $fresh = AdminUser::whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($fresh && $fresh->is_active && ! $fresh->test_recovery_only, 403);
        }
        $query = $this->schools($actor)->where('a.school_id', $school);
        if ($locked) {
            $query->lockForUpdate();
        }
        $row = $query->first(['a.tenant_id', 'a.school_id', 's.name', 's.timezone']);
        abort_unless($row, 404);

        return $row;
    }

    public function canEnter(mixed $actor): bool
    {
        return $this->enabled() && $actor instanceof AdminUser && $actor->is_active && ! $actor->test_recovery_only
            && $this->schools($actor)->exists();
    }
}
