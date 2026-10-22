<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Compile only confirmed, immutable school rules; never invent a timetable. */
final class PublishedCalendar
{
    public static function policy(object $publication, string $day): array
    {
        $stored = json_decode($publication->configuration, true, 16, JSON_THROW_ON_ERROR);
        $c = SetupRules::normalize($stored, stored: true);
        if (SetupRules::missing($c) !== []) {
            throw new InvalidArgumentException('Published configuration is incomplete.');
        }
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $day, $c['timezone']);
        if ($start->format('Y-m-d') !== $day || $day < $publication->starts_on) {
            throw new InvalidArgumentException('Date is outside publication.');
        }
        $values = array_intersect_key($c, array_flip(['timezone', 'late_at_cutoff', 'arrival_start', 'arrival_end', 'late_cutoff',
            'normal_arrival_end', 'departure_start', 'departure_end', 'repeat_seconds', 'reversal_seconds', 'future_tolerance_seconds']));
        if (! array_key_exists('normal_arrival_end', $stored)) {
            unset($values['normal_arrival_end']); // Do not rewrite already published legacy snapshots.
        }
        $special = ! in_array($start->dayOfWeekIso, $c['weekdays'], true)
            ? collect($c['class_days'])->firstWhere('date', $day) : null;
        $values += ['tenant_id' => (int) $publication->tenant_id, 'school_id' => (int) $publication->school_id,
            'roster_mode' => $c['roster_mode'],
            'day' => $day, 'version' => 'P'.$publication->id.'-D'.str_replace('-', '', $day),
            'closed' => (! in_array($start->dayOfWeekIso, $c['weekdays'], true) && $special === null) || in_array($day, $c['closed_dates'], true),
            'operator_rules' => ['correction' => ['reason_codes' => $c['correction_reason_codes']]],
            'return_rules' => $c['return_enabled'] ? ['min_seconds' => $c['return_min_seconds'],
                'max_seconds' => $c['return_max_seconds'], 'checkout_delay_seconds' => $c['return_checkout_delay_seconds']] : null];
        if ($special !== null) {
            $values['class_ids'] = $special['class_ids'];
        }
        if ($c['resolution_enabled']) {
            $values['operator_rules']['resolution'] = ['reason_codes' => $c['resolution_reason_codes'], 'max_age_seconds' => $c['resolution_max_age_seconds']];
        }
        if ($c['early_exit_enabled']) {
            $values['operator_rules']['early_exit'] = ['reason_codes' => $c['early_exit_reason_codes'],
                'required_checks' => $c['early_exit_required_checks'], 'validity_seconds' => $c['early_exit_validity_seconds']];
        }
        new DayPolicy($values);
        $from = $start->utc()->max(CarbonImmutable::parse($publication->activated_at, 'UTC'));
        // Calendar arithmetic in the SCHOOL zone, not a guessed 24-hour UTC day (DST).
        $until = $start->addDay()->utc();

        return ['id' => hash('sha256', 'published-gate|'.$publication->id.'|'.$day),
            'tenant_id' => $publication->tenant_id, 'school_id' => $publication->school_id, 'day' => $day,
            'timezone' => $c['timezone'], 'version' => $values['version'], 'snapshot' => json_encode($values, JSON_THROW_ON_ERROR),
            'student_ids' => json_encode($c['roster_mode'] === 'all_active' ? [] : $c['student_ids'], JSON_THROW_ON_ERROR),
            'device_ids' => json_encode($c['device_ids'], JSON_THROW_ON_ERROR),
            'effective_from' => $from->format('Y-m-d H:i:s'), 'effective_until' => $until->format('Y-m-d H:i:s'),
            'enabled' => true, 'confirmed_by' => $publication->activated_by, 'created_at' => now()->utc()];
    }
}
