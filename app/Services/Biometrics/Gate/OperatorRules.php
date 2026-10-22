<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

/** Optional school-confirmed rules in the immutable day policy, never global guessed defaults. */
final class OperatorRules
{
    public static function codes(mixed $values): array
    {
        abort_unless(is_array($values) && array_is_list($values) && count($values) >= 1 && count($values) <= 50
            && count($values) === count(array_unique($values, SORT_REGULAR))
            && ! array_filter($values, static fn ($v) => ! is_string($v) || ! preg_match('/^[a-z0-9_]{1,64}$/D', $v)),
            409, 'School gate reason/check rules are not configured.');

        return $values;
    }

    public static function reason(array $policy, string $operation, string $reason): array
    {
        $rules = $policy['operator_rules'][$operation] ?? null;
        abort_unless(is_array($rules) && in_array($reason, self::codes($rules['reason_codes'] ?? null), true),
            409, 'Choose a reason configured for this school and operation.');

        return $rules;
    }

    public static function seconds(mixed $value): int
    {
        abort_unless(is_int($value) && $value >= 1 && $value <= 86400, 409, 'Explicit school timing is required.');

        return $value;
    }
}
