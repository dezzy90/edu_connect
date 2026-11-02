<?php

namespace App\Services\Auth;

use Illuminate\Database\Eloquent\Builder;

final class MobileOtpPhone
{
    public function normalize(#[\SensitiveParameter] ?string $phone): ?string
    {
        if ($phone === null || strlen($phone) > 64 || ! preg_match('/^[+0-9 ().-]+$/D', $phone)) {
            return null;
        }

        $phone = str_replace([' ', '(', ')', '.', '-'], '', $phone);
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        } elseif (preg_match('/^6[0-9]{8}$/D', $phone)) {
            $phone = '+237'.$phone;
        } elseif (preg_match('/^2376[0-9]{8}$/D', $phone)) {
            $phone = '+'.$phone;
        }

        if (! preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone)) {
            return null;
        }

        if (str_starts_with($phone, '+237') && ! preg_match('/^\+2376[0-9]{8}$/D', $phone)) {
            return null;
        }

        return $phone;
    }

    public function matching(Builder $query, string $column, #[\SensitiveParameter] string $phone): Builder
    {
        $column = $query->getQuery()->getGrammar()->wrap($column);
        foreach ([' ', '(', ')', '.', '-'] as $separator) {
            $column = "REPLACE($column, '$separator', '')";
        }

        $variants = [$phone, '00'.substr($phone, 1)];
        if (str_starts_with($phone, '+237')) {
            $variants[] = substr($phone, 1);
            $variants[] = substr($phone, 4);
        }

        return $query->whereIn($query->getQuery()->raw($column), $variants);
    }

    public function masked(#[\SensitiveParameter] string $phone): string
    {
        return '+'.str_repeat('*', strlen($phone) - 3).substr($phone, -2);
    }
}
