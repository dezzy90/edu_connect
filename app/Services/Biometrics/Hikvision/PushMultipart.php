<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Hikvision;

use InvalidArgumentException;

/** Complete bounded PushSDK multipart envelope. No file operations or binary-part output. */
final class PushMultipart
{
    public static function metadataParts(string $payload, bool $allowOpenEndMarker = false): array
    {
        $end = strpos($payload, "\r\n\r\n");
        if ($end === false || $end > 8192) {
            throw new InvalidArgumentException('Missing multipart envelope headers.');
        }
        $headers = self::headers(substr($payload, 0, $end), true);
        $type = $headers['content-type'] ?? '';
        if (! preg_match('/^multipart\/(?:form-data|mixed)\s*;/i', $type)
            || ! preg_match('/\bboundary=(?:"([^"]+)"|([^;\s]+))/i', $type, $match)) {
            throw new InvalidArgumentException('Multipart boundary required.');
        }
        $boundary = $match[1] !== '' ? $match[1] : $match[2];
        if (! preg_match("/^[A-Za-z0-9'()+_,.\/:=?-]{1,70}$/D", $boundary)) {
            throw new InvalidArgumentException('Invalid boundary.');
        }
        $marker = '--'.$boundary;
        $remaining = substr($payload, $end + 4);
        // This terminal emits one empty preamble line. Ignore only bounded complete
        // CRLF lines, never arbitrary preamble bytes, lone linefeeds or payload data.
        for ($blank = 0; $blank < 8 && str_starts_with($remaining, "\r\n"); $blank++) {
            $remaining = substr($remaining, 2);
        }
        $parts = [];
        for ($i = 0; $i < 32; $i++) {
            if (! str_starts_with($remaining, $marker)) {
                throw new InvalidArgumentException('Invalid multipart delimiter.');
            }
            $remaining = substr($remaining, strlen($marker));
            // Observed only on the isolated V4.48.0 hardware test: an empty final
            // separator at EOF, not the RFC closing marker. Never opt in globally.
            if ($allowOpenEndMarker && $i > 0 && $parts !== [] && preg_match('/^(?:\r\n){1,2}$/D', $remaining)) {
                return $parts;
            }
            if (str_starts_with($remaining, '--')) {
                if (trim(substr($remaining, 2), "\r\n") !== '') {
                    throw new InvalidArgumentException('Trailing multipart data.');
                }

                return $parts;
            }
            if (! str_starts_with($remaining, "\r\n")) {
                throw new InvalidArgumentException('Invalid delimiter suffix.');
            }
            $remaining = substr($remaining, 2);
            $end = strpos($remaining, "\r\n\r\n");
            if ($end === false || $end > 8192) {
                throw new InvalidArgumentException('Invalid part headers.');
            }
            $headers = self::headers(substr($remaining, 0, $end));
            $remaining = substr($remaining, $end + 4);
            if (isset($headers['content-length'])) {
                if (! preg_match('/^\d{1,7}$/D', $headers['content-length'])) {
                    throw new InvalidArgumentException('Invalid part length.');
                }
                $length = (int) $headers['content-length'];
            } else {
                $length = strpos($remaining, "\r\n".$marker);
            }
            if ($length === false || $length > (int) config('hikvision_push.max_event_bytes')
                || ! str_starts_with(substr($remaining, $length), "\r\n".$marker)) {
                throw new InvalidArgumentException('Incomplete or oversized part.');
            }
            $contentType = strtolower(trim(explode(';', $headers['content-type'] ?? '')[0]));
            if (in_array($contentType, ['application/json', 'application/xml', 'text/xml'], true)) {
                $parts[] = ['dataFormat' => $contentType === 'application/json' ? 'jsonData' : 'xmlData', 'data' => substr($remaining, 0, $length)];
            }
            $remaining = substr($remaining, $length + 2);
        }
        throw new InvalidArgumentException('Too many multipart parts.');
    }

    private static function headers(string $block, bool $allowStatus = false): array
    {
        $lines = explode("\r\n", $block);
        if ($allowStatus && preg_match('/^HTTP\/1\.[01] \d{3}(?: .*)?$/D', $lines[0])) {
            array_shift($lines);
        }
        $headers = [];
        foreach ($lines as $line) {
            if (! preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/D', $line, $match) || isset($headers[strtolower($match[1])])) {
                throw new InvalidArgumentException('Invalid or repeated part header.');
            }
            $headers[strtolower($match[1])] = $match[2];
        }
        if (! isset($headers['content-type'])) {
            throw new InvalidArgumentException('Part type required.');
        }

        return $headers;
    }
}
