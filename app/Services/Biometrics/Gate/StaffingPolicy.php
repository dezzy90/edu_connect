<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Explicit complex staffing model; it never provisions accounts or grants publication authority. */
final class StaffingPolicy
{
    public static function secretaryComplexes(): ?array
    {
        $ids = config('gate.secretary_complex_ids', []);

        return is_array($ids) && array_is_list($ids) && count($ids) === count(array_unique($ids))
            && ! array_filter($ids, static fn ($id) => ! is_int($id) || $id < 1) ? $ids : null;
    }

    /** The caller supplies scoped assignment/school aliases a and s. */
    public static function restrict(Builder $query): Builder
    {
        $ids = self::secretaryComplexes();
        if ($ids === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn ($q) => $q->where('a.role', '!=', 'secretary')->orWhereIn('a.tenant_id', $ids))
            ->where(fn ($q) => $q->where('a.role', '!=', 'discipline_master')
                ->orWhereNotIn('a.tenant_id', $ids)->orWhere('s.type', 'secondary'));
    }

    public static function staff(int $tenant, int $school): Builder
    {
        $query = self::restrict(DB::table('ec_gate_operator_assignments as a')
            ->join('ec_schools as s', fn ($q) => $q->on('s.id', '=', 'a.school_id')->on('s.tenant_id', '=', 'a.tenant_id'))
            ->join('admin_users as u', 'u.id', '=', 'a.admin_id')
            ->where('a.tenant_id', $tenant)->where('a.school_id', $school)->where('a.active', true)
            ->where('u.is_active', true)->where('u.test_recovery_only', false)->whereIn('a.role', OperatorAccess::ROLES));
        if (Schema::hasTable('ec_gate_source_actors')) {
            $query->where(fn ($q) => $q->where('u.gate_source_only', false)->orWhereExists(fn ($s) => $s->selectRaw('1')
                ->from('ec_gate_source_actors as sa')->whereColumn('sa.admin_id', 'u.id')->where('sa.authority_until', '>', now()->utc())));
        }
        return $query;
    }

    public static function requirement(int $tenant, int $school): array
    {
        $ids = self::secretaryComplexes();
        $type = DB::table('ec_schools')->where('tenant_id', $tenant)->where('id', $school)->value('type');
        if ($ids === null || (in_array($tenant, $ids, true) && ! in_array($type, ['secondary', 'primary', 'nursery'], true))) {
            return ['roles' => [], 'message' => 'An explicit, valid complex staffing model and school level are required.'];
        }
        if (in_array($tenant, $ids, true)) {
            return ['roles' => $type === 'secondary' ? ['secretary', 'discipline_master'] : ['secretary'],
                'message' => $type === 'secondary' ? 'An active secretary and secondary-school discipline master require explicit gate assignments.'
                    : 'An active secretary requires an explicit gate assignment. Discipline-master access is restricted to secondary school.'];
        }

        return ['roles' => ['guard', 'discipline_master'], 'message' => 'A guard and discipline master need explicit active gate assignments.'];
    }

    public static function satisfied(array $requirement, array $roles): bool
    {
        return $requirement['roles'] !== [] && array_diff($requirement['roles'], $roles) === [];
    }
}
