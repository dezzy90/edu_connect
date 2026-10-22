<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Hikvision;

use InvalidArgumentException;

/** Bounded, value-allowlisted facts only; never retain raw capabilities, people or pictures. */
final class EnrollmentCapabilities
{
    public static function summary(array $entry, string $text, string $url): array
    {
        if (! in_array($url, PushCommands::ENROLLMENT_DISCOVERY_URLS, true)) {
            throw new InvalidArgumentException('Unknown enrollment discovery endpoint.');
        }
        if (($entry['dataFormat'] ?? null) === 'xmlData') {
            PushPayload::xml($text);

            return ['received' => true, 'failed' => true, 'response_error' => 'unexpected_response_format'];
        }
        if (($entry['dataFormat'] ?? null) !== 'jsonData') {
            throw new InvalidArgumentException('JSON capability response required.');
        }
        $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Capability object required.');
        }
        $status = is_array($data['ResponseStatus'] ?? null) ? $data['ResponseStatus'] : $data;
        $failed = (isset($status['statusCode']) && ! in_array($status['statusCode'], [1, '1'], true))
            || (isset($status['code']) && $status['code'] !== '0x00000000')
            || (is_numeric($status['status'] ?? null) && (int) $status['status'] !== 200);
        $summary = ['received' => true, 'failed' => $failed];
        if ($failed) {
            $summary['response_error'] = in_array($status['subStatusCode'] ?? null, ['invalidOperation', 'notSupport', 'methodNotAllowed'], true)
                ? $status['subStatusCode'] : 'other_error';

            return $summary;
        }
        $wrappers = str_contains($url, 'UserInfo/capabilities') ? ['UserInfoCap', 'UserInfo', 'Capabilities']
            : (str_contains($url, 'UserInfo/Count') ? ['UserInfoCount', 'UserInfo'] : ['FDLibCap', 'FaceRecognizeMode', 'Capabilities']);
        foreach ($wrappers as $wrapper) {
            if (is_array($data[$wrapper] ?? null) && ! array_is_list($data[$wrapper])) {
                $data = $data[$wrapper];
                break;
            }
        }
        $numericFields = match ($url) {
            PushCommands::ENROLLMENT_DISCOVERY_URLS[0] => ['maxRecordNum'],
            PushCommands::ENROLLMENT_DISCOVERY_URLS[1] => ['userNumber'],
            PushCommands::ENROLLMENT_DISCOVERY_URLS[2] => ['FDRecordDataMaxNum', 'FDMaxNum'],
            default => [],
        };
        foreach ($numericFields as $field) {
            if (is_int($data[$field] ?? null) && $data[$field] >= 0 && $data[$field] <= 1000000) {
                $summary[$field] = $data[$field];
            }
        }
        if (str_contains($url, '/capabilities?')) {
            foreach (['supportFunction', 'supportFDFunction'] as $field) {
                $options = $data[$field] ?? null;
                if (is_array($options) && ! array_is_list($options)) {
                    $options = $options['@opt'] ?? $options['opt'] ?? null;
                }
                $reported = is_string($options) && strlen($options) <= 256 ? array_map('trim', explode(',', $options)) : $options;
                if (is_array($reported) && array_is_list($reported) && count($reported) <= 32
                    && count(array_filter($reported, static fn ($value): bool => is_string($value) && strlen($value) <= 32)) === count($reported)) {
                    $summary[$field] = array_values(array_intersect(['get', 'post', 'put', 'delete', 'setUp'], $reported));
                }
            }
        }
        if ($url === PushCommands::ENROLLMENT_DISCOVERY_URLS[4]
            && in_array($data['mode'] ?? null, ['normalMode', 'deepMode'], true)) {
            $summary['mode'] = $data['mode'];
        }
        if ($url === PushCommands::ENROLLMENT_DISCOVERY_URLS[3]) {
            $libraries = $data['FDLib'] ?? null;
            if (is_array($libraries) && array_is_list($libraries) && count($libraries) <= 16) {
                $summary['libraries'] = [];
                foreach ($libraries as $library) {
                    if (! is_array($library) || ! is_string($library['FDID'] ?? null)
                        || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $library['FDID'])
                        || ! in_array($library['faceLibType'] ?? null, ['blackFD', 'staticFD', 'infraredFD'], true)) {
                        throw new InvalidArgumentException('Unverified face library identifier.');
                    }
                    $summary['libraries'][] = ['FDID' => $library['FDID'], 'faceLibType' => $library['faceLibType']];
                }
            }
        }
        $summary['recognized'] = count($summary) > 2;

        return $summary;
    }
}
