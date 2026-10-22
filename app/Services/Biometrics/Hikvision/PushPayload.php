<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Hikvision;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;

final class PushPayload
{
    public static function text(array $envelope): string
    {
        $data = $envelope['data'] ?? null;
        if (! is_string($data) || strlen($data) > (int) config('hikvision_push.max_event_bytes')) {
            throw new InvalidArgumentException('Invalid or oversized event envelope.');
        }
        // The supplier demo uses Base64. Pure JSON/XML is also described by the specification.
        if (str_starts_with(ltrim($data), '{') || str_starts_with(ltrim($data), '<')
            || str_starts_with($data, 'HTTP/1.') || preg_match('/^Content-Type:/i', $data)) {
            return $data;
        }
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid Base64.');
        }

        return $decoded;
    }

    public static function event(array $envelope): array
    {
        $text = self::text($envelope);
        if (($envelope['dataFormat'] ?? null) === 'boundaryData') {
            $events = [];
            foreach (PushMultipart::metadataParts($text, (bool) config('hikvision_push.allow_open_multipart_end', false)) as $part) {
                try {
                    $events[] = self::event($part);
                } catch (InvalidArgumentException $error) {
                    // Status/other metadata is not a gate event. Binary parts never enter here.
                }
            }
            if (count($events) !== 1) {
                throw new InvalidArgumentException('One unambiguous access event per upload is required.');
            }

            return $events[0];
        }
        if (($envelope['dataFormat'] ?? null) === 'jsonData') {
            $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ($data['eventType'] ?? null) !== 'AccessControllerEvent'
                || ! is_array($data['AccessControllerEvent'] ?? null)) {
                throw new InvalidArgumentException('Not an access-controller event.');
            }
            $event = $data['AccessControllerEvent'];
            $event['time'] = $data['dateTime'] ?? null;
            $event['inactive'] = ($data['eventState'] ?? 'active') !== 'active';

            return $event;
        }
        if (($envelope['dataFormat'] ?? null) !== 'xmlData') {
            throw new InvalidArgumentException('Unsupported format; no binary media is persisted.');
        }
        $xpath = self::xml($text);
        $value = static fn (string $field): string => $xpath->evaluate('string(//*[local-name()="'.$field.'"])');
        if ($value('eventType') !== 'AccessControllerEvent') {
            throw new InvalidArgumentException('Not an access-controller event.');
        }
        $event = ['time' => $value('dateTime'), 'inactive' => ($value('eventState') ?: 'active') !== 'active'];
        foreach (['majorEventType', 'subEventType', 'serialNo', 'employeeNoString', 'employeeNo', 'remoteCheck'] as $key) {
            $text = $value($key);
            if ($text !== '') {
                $event[$key] = $key === 'remoteCheck' ? match ($text) {
                    'true' => true, 'false' => false,
                    default => throw new InvalidArgumentException('Invalid boolean.'),
                } : $text;
            }
        }

        return $event;
    }

    public static function xml(string $text): DOMXPath
    {
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $text)) {
            throw new InvalidArgumentException('DTD and entities are not allowed.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($text, LIBXML_NONET)) {
                throw new InvalidArgumentException('Malformed XML.');
            }

            return new DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
