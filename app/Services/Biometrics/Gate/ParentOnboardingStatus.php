<?php

namespace App\Services\Biometrics\Gate;

use App\Services\Biometrics\Hikvision\EnrollmentRoster;
use Illuminate\Support\Facades\DB;

/** Read-only readiness, scoped by the freshly signed source school/class grants. */
final class ParentOnboardingStatus
{
    public function read(object $connection, int $school): array
    {
        $context = app(SourceWorkspaceContext::class);
        $students = EnrollmentRoster::eligibleStudents((int) $connection->tenant_id, $school)
            ->when(array_key_exists($school, $context->studentsBySchool), fn ($q) => $q->whereIn('ec_students.id', $context->studentsBySchool[$school]))
            ->join('ec_integration_mappings as m', fn ($q) => $q->on('m.local_id', '=', 'ec_students.id')
                ->where('m.connection_id', $connection->id)->where('m.local_type', 'student')->where('m.external_type', 'student'))
            ->whereColumn('m.external_id', 'ec_students.source_id')->limit(20001)->get(['ec_students.id', 'ec_students.mobile_visible', 'm.external_id']);
        abort_if($students->count() > 20000, 409, 'Please narrow the school roster.');
        abort_if($students->pluck('external_id')->duplicates()->isNotEmpty() || $students->pluck('id')->duplicates()->isNotEmpty(), 409, 'Student identity requires review.');
        $links = DB::table('ec_parent_student_links as l')->join('ec_parent_accounts as p', 'p.id', '=', 'l.parent_account_id')
            ->where('l.tenant_id', $connection->tenant_id)->where('l.school_id', $school)->whereIn('l.student_id', $students->pluck('id'))
            ->where('l.status', 'active')->whereNull('l.deleted_at')->where('p.status', 'active')->whereNull('p.deleted_at')
            ->leftJoin('ec_notification_preferences as n', fn ($q) => $q->on('n.parent_account_id', '=', 'p.id')->where('n.category', 'attendance'))
            ->select('l.student_id', 'p.id as parent_id', 'n.push_enabled')->get();
        $phones = DB::table('ec_mobile_push_tokens')->whereIn('parent_account_id', $links->pluck('parent_id'))->whereNull('revoked_at')
            ->distinct()->pluck('parent_account_id')->map(fn ($id) => (int) $id)->all();
        $setting = DB::table('ec_gate_parent_alert_settings')->where('tenant_id', $connection->tenant_id)->where('school_id', $school)->first();
        $enabled = config('gate.parent_delivery_enabled') === true && config('gate.parent_push_enabled') === true
            && in_array($school, config('gate.school_ids', []), true)
            && in_array(config('educonnect.notifications.push_transport'), ['provider', 'remote', 'fcm', 'apns'], true);
        $grouped = $links->groupBy('student_id');

        return ['checked_at' => now()->utc()->toIso8601String(), 'source_synced_at' => $connection->last_successful_sync_at,
            'arrivals_enabled' => $enabled && (bool) ($setting?->arrivals_enabled ?? false),
            'departures_enabled' => $enabled && (bool) ($setting?->departures_enabled ?? false),
            'students' => $students->map(function ($s) use ($grouped, $phones): array {
                $parents = ($grouped[$s->id] ?? collect())->unique('parent_id');

                return ['student_id' => (int) $s->external_id, 'mobile_available' => (bool) $s->mobile_visible, 'linked_parents' => $parents->count(),
                    'phone_ready_parents' => $parents->filter(fn ($p) => ($p->push_enabled === null || (bool) $p->push_enabled)
                        && in_array((int) $p->parent_id, $phones, true))->count()];
            })->values()->all()];
    }
}
