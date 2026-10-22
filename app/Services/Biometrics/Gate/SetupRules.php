<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use Illuminate\Validation\ValidationException;

/** Automatic eligibility is the new-draft default; timetable rules and activation are never inferred. */
final class SetupRules
{
    public static function blank(): array
    {
        return ['timezone' => null, 'weekdays' => [], 'closed_dates' => [], 'class_days' => [], 'roster_mode' => 'all_active', 'student_ids' => [], 'device_ids' => [],
            'arrival_start' => null, 'arrival_end' => null, 'normal_arrival_end' => null, 'late_cutoff' => null, 'late_at_cutoff' => null,
            'departure_start' => null, 'departure_end' => null, 'repeat_seconds' => null, 'reversal_seconds' => null,
            'future_tolerance_seconds' => null, 'correction_reason_codes' => [],
            'resolution_enabled' => false, 'resolution_reason_codes' => [], 'resolution_max_age_seconds' => null,
            'early_exit_enabled' => false, 'early_exit_roles' => [], 'early_exit_reason_codes' => [],
            'early_exit_required_checks' => [], 'early_exit_validity_seconds' => null,
            'return_enabled' => false, 'return_min_seconds' => null, 'return_max_seconds' => null, 'return_checkout_delay_seconds' => null];
    }

    public static function normalize(mixed $input, bool $stored = false): array
    {
        if (! is_array($input) || ($input !== [] && array_is_list($input)) || array_diff(array_keys($input), array_keys(self::blank())) !== []) {
            throw ValidationException::withMessages(['configuration' => 'Use only the supported draft fields; activation and permissions are not editable here.']);
        }
        $c = array_replace(self::blank(), $input);
        // Old saved drafts/clients were explicit pilots. Never widen their scope during a read or retry.
        if (! array_key_exists('roster_mode', $input) && ($stored || array_key_exists('student_ids', $input))) {
            $c['roster_mode'] = 'selected';
        }
        $strictInt = static function ($attribute, $value, $fail): void {
            if (! is_int($value)) {
                $fail('Use a whole JSON number.');
            }
        };
        $strictBool = static function ($attribute, $value, $fail): void {
            if (! is_bool($value)) {
                $fail('Use an explicit true or false value.');
            }
        };
        $list = static function ($attribute, $value, $fail): void {
            if (! is_array($value) || ! array_is_list($value)) {
                $fail('Use a list of values.');
            }
        };
        $rules = ['roster_mode' => ['required', 'string', 'in:all_active,selected'],
            'timezone' => ['nullable', 'string', 'timezone'], 'weekdays' => ['array', 'max:7', $list],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct', $strictInt],
            'closed_dates' => ['array', 'max:366', $list], 'closed_dates.*' => ['string', 'date_format:Y-m-d', 'distinct'],
            'class_days' => ['array', 'max:366', $list], 'class_days.*' => ['array:date,class_ids'],
            'class_days.*.date' => ['required', 'string', 'date_format:Y-m-d', 'distinct'],
            'class_days.*.class_ids' => ['required', 'array', 'min:1', 'max:200', $list],
            'class_days.*.class_ids.*' => ['integer', 'min:1', 'distinct', $strictInt],
            'late_at_cutoff' => ['nullable', $strictBool], 'early_exit_roles' => ['array', 'max:2', $list],
            'early_exit_roles.*' => ['string', 'in:guard,discipline_master', 'distinct']];
        foreach (['student_ids', 'device_ids'] as $key) {
            $rules[$key] = ['array', 'max:200', $list];
            $rules[$key.'.*'] = ['integer', 'min:1', 'distinct', $strictInt];
        }
        foreach (['arrival_start', 'arrival_end', 'normal_arrival_end', 'late_cutoff', 'departure_start', 'departure_end'] as $key) {
            $rules[$key] = ['nullable', 'string', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D'];
        }
        foreach (['resolution_enabled', 'early_exit_enabled', 'return_enabled'] as $key) {
            $rules[$key] = ['required', 'boolean', $strictBool];
        }
        foreach (['repeat_seconds', 'reversal_seconds', 'future_tolerance_seconds', 'resolution_max_age_seconds',
            'early_exit_validity_seconds', 'return_min_seconds', 'return_max_seconds', 'return_checkout_delay_seconds'] as $key) {
            $rules[$key] = ['nullable', 'integer', 'between:'.($key === 'future_tolerance_seconds' ? '0' : '1').',86400', $strictInt];
        }
        foreach (['correction_reason_codes', 'resolution_reason_codes', 'early_exit_reason_codes', 'early_exit_required_checks'] as $key) {
            $rules[$key] = ['array', 'max:50', $list];
            $rules[$key.'.*'] = ['string', 'distinct', 'regex:/^[a-z0-9_]{1,64}$/D'];
        }
        validator(['configuration' => $c], collect($rules)->mapWithKeys(static fn ($value, $key) => ['configuration.'.$key => $value])->all())->validate();
        $errors = [];
        if ($c['normal_arrival_end'] !== null && (($c['arrival_start'] !== null && $c['normal_arrival_end'] < $c['arrival_start'])
            || ($c['late_cutoff'] !== null && $c['normal_arrival_end'] >= $c['late_cutoff']))) {
            $errors['configuration.normal_arrival_end'] = 'Normal arrival must end before the late cutoff and not before arrival opens; the interval between is a non-late grace period.';
        }
        if ($c['roster_mode'] === 'all_active' && $c['student_ids'] !== []) {
            $errors['configuration.student_ids'] = 'Automatic eligibility uses the growing school roster, not a frozen student selection. Clear the list or choose a restricted pilot.';
        }
        foreach ([['arrival_start', 'arrival_end', true], ['arrival_end', 'departure_start', false],
            ['departure_start', 'departure_end', true], ['arrival_start', 'late_cutoff', false], ['late_cutoff', 'arrival_end', false]] as [$a, $b, $strict]) {
            if ($c[$a] !== null && $c[$b] !== null && ($strict ? $c[$a] >= $c[$b] : $c[$a] > $c[$b])) {
                $errors['configuration.'.$b] = 'Use ordered, same-day arrival, lateness and departure times.';
            }
        }
        if ($c['return_min_seconds'] !== null && $c['return_max_seconds'] !== null && $c['return_min_seconds'] > $c['return_max_seconds']) {
            $errors['configuration.return_max_seconds'] = 'Maximum return time cannot precede minimum return time.';
        }
        if ($c['return_min_seconds'] !== null && max($c['repeat_seconds'] ?? 0, $c['reversal_seconds'] ?? 0) > $c['return_min_seconds']) {
            $errors['configuration.return_min_seconds'] = 'Return timing must respect repeat and reversal delays.';
        }
        if ($c['return_checkout_delay_seconds'] !== null && $c['reversal_seconds'] !== null && $c['return_checkout_delay_seconds'] < $c['reversal_seconds']) {
            $errors['configuration.return_checkout_delay_seconds'] = 'Post-return checkout delay cannot be shorter than the reversal delay.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        foreach ($c['class_days'] as &$entry) {
            sort($entry['class_ids']);
        }
        unset($entry);
        foreach ($c as &$value) {
            if (is_array($value)) {
                sort($value);
            }
        }
        unset($value);

        return $c;
    }

    public static function missing(array $c): array
    {
        $missing = [];
        $require = static function (string $field, string $message) use ($c, &$missing): void {
            if ($c[$field] === null || $c[$field] === []) {
                $missing[] = ['field' => $field, 'message' => $message];
            }
        };
        foreach (['timezone', 'weekdays', 'device_ids', 'arrival_start', 'arrival_end', 'late_cutoff', 'late_at_cutoff',
            'departure_start', 'departure_end', 'repeat_seconds', 'reversal_seconds', 'future_tolerance_seconds', 'correction_reason_codes'] as $field) {
            $require($field, 'Confirm '.str_replace('_', ' ', $field).'.');
        }
        if ($c['roster_mode'] === 'selected') {
            $require('student_ids', 'Select the students for this restricted pilot. An empty pilot includes nobody.');
        }
        if ($c['resolution_enabled']) {
            $require('resolution_reason_codes', 'Provide direction-resolution reasons.');
            $require('resolution_max_age_seconds', 'Confirm the maximum age of a scan for direction resolution.');
        }
        if ($c['early_exit_enabled']) {
            foreach (['early_exit_roles', 'early_exit_reason_codes', 'early_exit_required_checks', 'early_exit_validity_seconds'] as $field) {
                $require($field, 'Confirm '.str_replace('_', ' ', $field).'.');
            }
            // Draft intent may be narrower than the existing runtime. Never claim that is enforced already.
            if ($c['early_exit_roles'] !== [] && $c['early_exit_roles'] !== ['discipline_master', 'guard']) {
                $missing[] = ['field' => 'early_exit_roles', 'message' => 'The existing runtime allows both guard and discipline master. This narrower draft needs a separate runtime change before activation.'];
            }
        }
        if ($c['return_enabled']) {
            if (! $c['early_exit_enabled']) {
                $missing[] = ['field' => 'return_enabled', 'message' => 'Return requires a configured supervised early-exit flow.'];
            }
            foreach (['return_min_seconds', 'return_max_seconds', 'return_checkout_delay_seconds'] as $field) {
                $require($field, 'Confirm '.str_replace('_', ' ', $field).'.');
            }
        }

        return $missing;
    }
}
