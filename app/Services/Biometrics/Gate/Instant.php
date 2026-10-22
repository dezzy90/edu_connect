<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Gate;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Gate metadata must not depend on a transport service being installed in the main application. */
final class Instant
{
    public static function normalize(mixed $time): string
    {
        if (! is_string($time) || ! preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D', $time, $match)) {
            throw new InvalidArgumentException('Offset-aware gate event time required.');
        }
        $offset = $match[3] === 'Z' ? '+00:00' : $match[3];
        if (substr($offset, 1, 2) === '14' && substr($offset, 4, 2) !== '00') {
            throw new InvalidArgumentException('Invalid gate offset.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $match[1].'.'.str_pad($match[2] ?? '', 6, '0').$offset);
        $errors = DateTimeImmutable::getLastErrors();
        if (! $date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new InvalidArgumentException('Invalid gate calendar time.');
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
