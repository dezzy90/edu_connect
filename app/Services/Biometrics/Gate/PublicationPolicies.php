<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/** Rotating bounded maintenance, independent of source sync, portraits and terminal requests. */
final class PublicationPolicies
{
    public function work(int $limit = 100): void
    {
        if (config('gate.enabled') !== true || ! Schema::hasTable('ec_gate_publications')) {
            return;
        }
        $schools = config('gate.school_ids');
        if (! is_array($schools) || ! array_is_list($schools) || $schools === []
            || array_filter($schools, static fn ($id) => ! is_int($id) || $id < 1)
            || count($schools) !== count(array_unique($schools))) {
            return;
        }
        $rows = DB::table('ec_gate_publications as p')->join('ec_schools as s', function ($q): void {
            $q->on('s.id', '=', 'p.school_id')->on('s.tenant_id', '=', 'p.tenant_id');
        })->join('ec_tenants as t', 't.id', '=', 'p.tenant_id')->where('p.state', 'active')->whereIn('p.school_id', $schools)
            ->where('s.status', 'active')->whereNull('s.deleted_at')->where('t.status', 'active')->whereNull('t.deleted_at')
            ->orderByRaw('case when p.last_materialized_at is null then 0 else 1 end')->orderBy('p.last_materialized_at')->orderBy('p.id')
            ->limit(max(1, min(500, $limit)))->get(['p.id', 'p.school_id', 'p.tenant_id']);
        $failed = false;
        foreach ($rows as $row) {
            try {
                DB::transaction(function () use ($row): void {
                    $school = DB::table('ec_schools')->where('id', $row->school_id)->where('tenant_id', $row->tenant_id)->lockForUpdate()->first();
                    $p = DB::table('ec_gate_publications')->where('id', $row->id)->lockForUpdate()->sole();
                    if ($p->state !== 'active' || ! $school || $school->status !== 'active' || $school->deleted_at
                        || ! DB::table('ec_tenants')->where('id', $row->tenant_id)->where('status', 'active')->whereNull('deleted_at')->exists()) {
                        return;
                    }
                    $this->materialize($p);
                }, 3);
            } catch (Throwable) {
                $failed = true;
                DB::table('ec_gate_publications')->where('id', $row->id)->update(['last_materialized_at' => now()->utc(), 'materialization_error' => 'policy_materialization_failed']);
            }
        }
        if ($failed) {
            throw new RuntimeException('Published policy maintenance failed.');
        }
    }

    /** Caller holds school then publication locks. Activation creates its first policies atomically. */
    public function materialize(object $p): void
    {
        if (DB::transactionLevel() < 1 || $p->state !== 'active') {
            throw new RuntimeException('Active publication transaction required.');
        }
        $c = json_decode($p->configuration, true, 16, JSON_THROW_ON_ERROR);
        $today = CarbonImmutable::now($c['timezone'])->startOfDay();
        // Yesterday recovers an already-approved day after a scheduler outage. Never pre-activation.
        foreach ([-1, 0, 1] as $offset) {
            $day = $today->addDays($offset)->format('Y-m-d');
            if ($day < $p->starts_on || $day < CarbonImmutable::parse($p->activated_at, 'UTC')->setTimezone($c['timezone'])->format('Y-m-d')) {
                continue;
            }
            $values = PublishedCalendar::policy($p, $day);
            $old = DB::table('ec_gate_policies')->where('id', $values['id'])->first();
            if ($old) {
                $link = DB::table('ec_gate_policy_publications')->where('policy_id', $old->id)->where('publication_id', $p->id)->where('day', $day)->exists();
                foreach (['tenant_id', 'school_id', 'day', 'timezone', 'version', 'snapshot', 'student_ids', 'device_ids', 'effective_from', 'effective_until', 'confirmed_by'] as $field) {
                    // Database JSON may have a different whitespace/key representation.
                    $same = in_array($field, ['snapshot', 'student_ids', 'device_ids'], true)
                        ? json_decode($old->$field, true) == json_decode($values[$field], true) : (string) $old->$field === (string) $values[$field];
                    if (! $same) {
                        throw new RuntimeException('Published policy changed.');
                    }
                }
                if (! $link || ! $old->enabled) {
                    throw new RuntimeException('Published policy link changed.');
                }

                continue;
            }
            $overlap = DB::table('ec_gate_policies')->where('tenant_id', $p->tenant_id)->where('school_id', $p->school_id)
                ->where('enabled', true)->where('effective_from', '<', $values['effective_until'])
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $values['effective_from']))->exists();
            if ($overlap) {
                throw new RuntimeException('Conflicting gate policy exists.');
            }
            DB::table('ec_gate_policies')->insert($values);
            DB::table('ec_gate_policy_publications')->insert(['policy_id' => $values['id'], 'publication_id' => $p->id, 'day' => $day]);
        }
        DB::table('ec_gate_publications')->where('id', $p->id)->update(['last_materialized_at' => now()->utc(), 'materialization_error' => null]);
    }
}
