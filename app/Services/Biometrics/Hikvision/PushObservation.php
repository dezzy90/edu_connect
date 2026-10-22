<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Hikvision;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class PushObservation
{
    public static function normalize(object $device, array $event): array
    {
        $major = self::number($event['majorEventType'] ?? null, 0);
        $minor = self::number($event['subEventType'] ?? null, 0);
        $serial = self::number($event['serialNo'] ?? null, 1);
        $person = $event['employeeNoString'] ?? (isset($event['employeeNo']) ? (string) $event['employeeNo'] : null);
        if (! is_string($person) || $person === '' || strlen($person) > 64 || str_contains($person, "\0")) {
            throw new InvalidArgumentException('A bounded string person identifier is required.');
        }
        $remote = $event['remoteCheck'] ?? false;
        if (! is_bool($remote)) {
            throw new InvalidArgumentException('Invalid remoteCheck.');
        }
        $metadata = [
            'device_id' => $device->device_id, 'deployment_epoch' => $device->deployment_epoch,
            'tenant_id' => $device->tenant_id, 'school_id' => $device->school_id,
            'serial_no' => $serial, 'employee_no' => $person,
            'occurred_at' => self::instant($event['time'] ?? null),
            'major' => $major, 'minor' => $minor, 'remote_check' => $remote,
            'inactive' => (bool) ($event['inactive'] ?? false),
        ];
        $key = array_intersect_key($metadata, array_flip([
            'device_id', 'deployment_epoch', 'tenant_id', 'school_id', 'serial_no', 'occurred_at',
        ]));
        $status = match (true) {
            $remote => 'held_remote_verification',
            $metadata['inactive'] => 'held_inactive',
            $major === 5 && in_array($minor, [75, 77], true) => 'recognition_candidate',
            $major === 5 && in_array($minor, [76, 78, 79, 80], true) => 'held_authentication_failed',
            default => 'held_unverified_code',
        };

        return ['event_key' => hash('sha256', json_encode($key, JSON_THROW_ON_ERROR)),
            'fingerprint' => hash('sha256', json_encode($metadata, JSON_THROW_ON_ERROR)),
            'metadata' => $metadata, 'status' => $status];
    }

    public static function instant(mixed $time): string
    {
        if (! is_string($time) || ! preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D', $time, $match)) {
            throw new InvalidArgumentException('Offset-aware event time is required.');
        }
        $offset = $match[3] === 'Z' ? '+00:00' : $match[3];
        if (substr($offset, 1, 2) === '14' && substr($offset, 4, 2) !== '00') {
            throw new InvalidArgumentException('Invalid offset.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $match[1].'.'.str_pad($match[2] ?? '', 6, '0').$offset);
        $errors = DateTimeImmutable::getLastErrors();
        if (! $date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new InvalidArgumentException('Invalid calendar time.');
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function number(mixed $value, int $minimum): int
    {
        if ((! is_int($value) && ! (is_string($value) && preg_match('/^\d{1,10}$/D', $value)))
            || (int) $value < $minimum || (int) $value > 2147483647) {
            throw new InvalidArgumentException('Invalid code or serial number.');
        }

        return (int) $value;
    }
}
