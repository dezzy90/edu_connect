<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Immutable, explicitly configured school/day rules. No guessed school timetable or delays. */
final readonly class DayPolicy
{
    public DateTimeZone $zone;

    public function __construct(public array $values)
    {
        foreach (['tenant_id', 'school_id'] as $field) {
            if (! is_int($values[$field] ?? null) || $values[$field] < 1) {
                throw new InvalidArgumentException('Explicit positive gate scope required.');
            }
        }
        if (! preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $values['version'] ?? '')
            || ! is_bool($values['closed'] ?? null) || ! is_bool($values['late_at_cutoff'] ?? null)) {
            throw new InvalidArgumentException('Explicit version and day flags required.');
        }
        $this->zone = new DateTimeZone($values['timezone']);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values['day'], $this->zone);
        if (! $date || $date->format('Y-m-d') !== $values['day']) {
            throw new InvalidArgumentException('Invalid gate calendar date.');
        }
        foreach (['arrival_start', 'arrival_end', 'late_cutoff', 'departure_start', 'departure_end'] as $field) {
            if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $values[$field] ?? '')) {
                throw new InvalidArgumentException('Explicit HH:MM:SS schedule required.');
            }
        }
        if (! ($values['arrival_start'] < $values['arrival_end'] && $values['arrival_start'] <= $values['late_cutoff']
            && $values['late_cutoff'] <= $values['arrival_end'] && $values['arrival_end'] <= $values['departure_start']
            && $values['departure_start'] < $values['departure_end'])) {
            throw new InvalidArgumentException('Same-day ordered gate windows required.');
        }
        $normalEnd = $values['normal_arrival_end'] ?? null;
        if ($normalEnd !== null && (! is_string($normalEnd) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $normalEnd)
            || $normalEnd < $values['arrival_start'] || $normalEnd >= $values['late_cutoff'])) {
            throw new InvalidArgumentException('Normal arrival must precede the explicit grace/late boundary.');
        }
        foreach (['repeat_seconds', 'reversal_seconds', 'future_tolerance_seconds'] as $field) {
            if (! is_int($values[$field] ?? null) || $values[$field] < ($field === 'future_tolerance_seconds' ? 0 : 1)
                || $values[$field] > 86400) {
                throw new InvalidArgumentException('Explicit bounded timing rules required.');
            }
        }
        $return = $values['return_rules'] ?? null;
        if ($return !== null && (! is_array($return) || ! is_int($return['min_seconds'] ?? null)
            || ! is_int($return['max_seconds'] ?? null) || ! is_int($return['checkout_delay_seconds'] ?? null)
            || $return['min_seconds'] < max($values['repeat_seconds'], $values['reversal_seconds'])
            || $return['max_seconds'] < $return['min_seconds'] || $return['max_seconds'] > 86400
            || $return['checkout_delay_seconds'] < $values['reversal_seconds'] || $return['checkout_delay_seconds'] > 86400)) {
            throw new InvalidArgumentException('Complete school-derived return timing required.');
        }
    }
}
