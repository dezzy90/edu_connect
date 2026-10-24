<?php

namespace App\Services\Biometrics\Gate;

use App\Models\AdminUser;
use App\Services\Biometrics\Hikvision\EnrollmentRoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** School-level notification consent is independent of the immutable attendance timetable. */
final class ParentAlertSettings
{
    public function read(object $school): array
    {
        $row = Schema::hasTable('ec_gate_parent_alert_settings') ? DB::table('ec_gate_parent_alert_settings')
            ->where('tenant_id', $school->tenant_id)->where('school_id', $school->school_id)->first() : null;
        $links = DB::table('ec_parent_student_links as l')->join('ec_parent_accounts as p', 'p.id', '=', 'l.parent_account_id')
            ->where('l.tenant_id', $school->tenant_id)->where('l.school_id', $school->school_id)->where('l.status', 'active')->whereNull('l.deleted_at')
            ->where('p.status', 'active')->whereNull('p.deleted_at')->whereIn('l.student_id', EnrollmentRoster::eligibleStudents((int) $school->tenant_id, (int) $school->school_id)->select('id'));
        $parents = (clone $links)->distinct()->pluck('p.id');
        $phoneParents = DB::table('ec_mobile_push_tokens')->whereIn('parent_account_id', $parents)->whereNull('revoked_at')->distinct()->count('parent_account_id');
        return ['revision' => (int) ($row?->revision ?? 0), 'arrivals_enabled' => (bool) ($row?->arrivals_enabled ?? false),
            'departures_enabled' => (bool) ($row?->departures_enabled ?? false), 'linked_students' => (clone $links)->distinct()->count('l.student_id'),
            'linked_parents' => $parents->count(), 'parents_with_phone_alerts' => $phoneParents,
            'delivery_enabled' => config('gate.parent_delivery_enabled') === true,
            'phone_delivery_enabled' => config('gate.parent_push_enabled') === true,
            'effective_from' => $row?->effective_from];
    }

    public function save(AdminUser $actor, int $schoolId, array $body): array
    {
        $school = app(SetupAccess::class)->school($actor, $schoolId);
        abort_unless(Schema::hasTable('ec_gate_parent_alert_settings'), 409, 'Parent alerts are temporarily unavailable.');
        abort_if(array_diff(array_keys($body), ['expected_revision', 'idempotency_key', 'arrivals_enabled', 'departures_enabled']) !== [], 422);
        $input = validator($body, ['expected_revision' => ['required', 'integer', 'min:0'],
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,80}$/D'],
            'arrivals_enabled' => ['required', 'boolean'], 'departures_enabled' => ['required', 'boolean']])->validate();
        abort_unless(is_int($input['expected_revision']) && is_bool($input['arrivals_enabled']) && is_bool($input['departures_enabled']), 422);
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        $key = hash('sha256', $school->tenant_id.'|'.$schoolId.'|'.$actor->id.'|'.$input['idempotency_key']);
        return DB::transaction(function () use ($actor, $schoolId, $school, $input, $hash, $key): array {
            DB::table('ec_schools')->where('id', $schoolId)->where('tenant_id', $school->tenant_id)->lockForUpdate()->sole();
            app(SetupAccess::class)->school($actor, $schoolId, true);
            if ($old = DB::table('ec_gate_parent_alert_changes')->where('request_id', $key)->first()) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'This retry contains different settings.');
                return $this->read($school);
            }
            $row = DB::table('ec_gate_parent_alert_settings')->where('tenant_id', $school->tenant_id)->where('school_id', $schoolId)->lockForUpdate()->first();
            abort_unless((int) ($row?->revision ?? 0) === $input['expected_revision'], 409, 'Parent alert settings changed. Refresh before saving.');
            $values = ['arrivals_enabled' => $input['arrivals_enabled'], 'departures_enabled' => $input['departures_enabled'],
                'revision' => $input['expected_revision'] + 1, 'updated_by' => $actor->id, 'updated_at' => now()->utc(),
                // A new opt-in never replays arrivals from before that opt-in.
                'effective_from' => ! $row || (! $row->arrivals_enabled && $input['arrivals_enabled']) || (! $row->departures_enabled && $input['departures_enabled'])
                    ? now()->utc() : $row->effective_from];
            if ($row) DB::table('ec_gate_parent_alert_settings')->where('id', $row->id)->update($values);
            else DB::table('ec_gate_parent_alert_settings')->insert($values + ['tenant_id' => $school->tenant_id, 'school_id' => $schoolId, 'created_at' => now()->utc()]);
            DB::table('ec_gate_parent_alert_changes')->insert(['request_id' => $key, 'request_hash' => $hash, 'tenant_id' => $school->tenant_id,
                'school_id' => $schoolId, 'actor_id' => $actor->id, 'revision' => $values['revision'],
                'configuration' => json_encode($values, JSON_THROW_ON_ERROR), 'created_at' => now()->utc()]);
            return $this->read($school);
        }, 3);
    }

    public static function permits(array $event): bool
    {
        // Older isolated releases remain in-app only; production migrations require explicit school settings.
        if (! Schema::hasTable('ec_gate_parent_alert_settings')) return config('gate.parent_push_enabled') !== true;
        $row = DB::table('ec_gate_parent_alert_settings')->where('tenant_id', $event['tenant_id'])->where('school_id', $event['school_id'])->first();
        return $row && ($event['movement'] === 'check_in' ? $row->arrivals_enabled : $row->departures_enabled)
            && CarbonImmutable::parse($event['occurred_at'])->greaterThanOrEqualTo(CarbonImmutable::parse($row->effective_from, 'UTC'))
            && EnrollmentRoster::eligibleStudents((int) $event['tenant_id'], (int) $event['school_id'])->where('id', $event['student_id'])->exists();
    }
}
