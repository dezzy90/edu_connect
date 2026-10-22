<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use App\Services\Biometrics\Hikvision\EnrollmentRoster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PolicyRoster
{
    public static function includes(object $policy, int $student, ?string $occurredAt = null): bool
    {
        $ids = json_decode($policy->student_ids, true, 16, JSON_THROW_ON_ERROR);
        $snapshot = json_decode($policy->snapshot, true, 16, JSON_THROW_ON_ERROR);
        if (array_key_exists('class_ids', $snapshot)) {
            $classes = $snapshot['class_ids'];
            if (! is_array($classes) || ! array_is_list($classes) || $classes === [] || count($classes) !== count(array_unique($classes))
                || array_filter($classes, static fn ($id) => ! is_int($id) || $id < 1)
                || ! DB::table('ec_students as s')->join('ec_classes as c', function ($q): void {
                    $q->on('c.id', '=', 's.class_id')->on('c.school_id', '=', 's.school_id')->on('c.tenant_id', '=', 's.tenant_id');
                })->where('s.id', $student)->where('s.tenant_id', $policy->tenant_id)->where('s.school_id', $policy->school_id)
                    ->whereIn('c.id', $classes)->where('c.status', 'active')->whereNull('c.deleted_at')
                    ->where('c.source_system', 'edu_admin')->whereNotNull('c.source_id')->where('c.source_id', '!=', '')
                    ->when($occurredAt !== null, fn ($q) => $q->whereNotNull('s.source_updated_at')
                        ->where('s.source_updated_at', '<=', \Carbon\CarbonImmutable::parse($occurredAt)->utc()->format('Y-m-d H:i:s')))->exists()) {
                return false;
            }
        }
        if (! is_array($ids) || ! array_is_list($ids) || count($ids) !== count(array_unique($ids))
            || array_filter($ids, static fn ($id) => ! is_int($id) || $id < 1)) {
            return false;
        }
        if ($ids !== []) {
            return in_array($student, $ids, true);
        }
        // Legacy empty lists still deny everybody. Automatic scope requires an actual
        // backend publication, not just an empty list or a terminal-supplied marker.
        if (($snapshot['roster_mode'] ?? null) !== 'all_active' || ! Schema::hasTable('ec_gate_policy_publications')) {
            return false;
        }
        $p = DB::table('ec_gate_policy_publications as l')->join('ec_gate_publications as p', 'p.id', '=', 'l.publication_id')
            ->where('l.policy_id', $policy->id)->where('l.day', $policy->day)->where('p.tenant_id', $policy->tenant_id)
            ->where('p.school_id', $policy->school_id)->first(['p.id', 'p.configuration']);
        $configuration = $p ? json_decode($p->configuration, true, 16, JSON_THROW_ON_ERROR) : [];

        return $p && $policy->id === hash('sha256', 'published-gate|'.$p->id.'|'.$policy->day)
            && ($configuration['roster_mode'] ?? null) === 'all_active' && ($configuration['student_ids'] ?? null) === []
            && EnrollmentRoster::eligibleStudents((int) $policy->tenant_id, (int) $policy->school_id)->where('id', $student)->exists();
    }
}
