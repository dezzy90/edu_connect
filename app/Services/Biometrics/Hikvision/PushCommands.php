<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Hikvision;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PushCommands
{
    public const DISCOVERY_URLS = [
        'GET /ISAPI/System/deviceInfo', 'GET /ISAPI/System/capabilities', 'GET /ISAPI/System/time',
        'GET /ISAPI/AccessControl/capabilities', 'GET /ISAPI/AccessControl/AcsEvent/capabilities?format=json',
    ];

    public const SECURITY_URLS = ['GET /ISAPI/System/Network/PUSHCfg?format=json'];

    // A conventional capability-URI inquiry, not a documented guarantee of model support.
    public const SECURITY_CAPABILITY_URLS = ['GET /ISAPI/System/Network/PUSHCfg/capabilities?format=json'];

    public const ENROLLMENT_DISCOVERY_URLS = [
        'GET /ISAPI/AccessControl/UserInfo/capabilities?format=json',
        'GET /ISAPI/AccessControl/UserInfo/Count?format=json',
        'GET /ISAPI/Intelligent/FDLib/capabilities?format=json',
        'GET /ISAPI/Intelligent/FDLib?format=json',
        'GET /ISAPI/AccessControl/FaceRecognizeMode?format=json',
    ];

    public const READ_ONLY_URLS = [...self::DISCOVERY_URLS, ...self::SECURITY_URLS, ...self::SECURITY_CAPABILITY_URLS, ...self::ENROLLMENT_DISCOVERY_URLS];

    public function queue(int $deviceId, string $url): string
    {
        if (! in_array($url, self::READ_ONLY_URLS, true)) {
            throw new InvalidArgumentException('Only allowlisted read-only discovery commands are enabled.');
        }

        return DB::transaction(function () use ($deviceId, $url): string {
            $device = DB::table('ec_hikvision_push_devices')->where('id', $deviceId)->where('enabled', true)->lockForUpdate()->first();
            if (! $device) {
                throw new InvalidArgumentException('An enabled registered terminal is required.');
            }
            $uuid = (string) Str::uuid();
            DB::table('ec_hikvision_push_commands')->insert([
                'push_device_id' => $deviceId, 'command_uuid' => $uuid, 'url' => $url, 'created_at' => now(),
            ]);

            return $uuid;
        });
    }

    public function poll(object $device): array
    {
        $enrollment = app(EnrollmentCommands::class)->poll($device);
        if ($enrollment !== []) {
            return ['commandNum' => count($enrollment), 'commandList' => $enrollment];
        }
        $rows = DB::table('ec_hikvision_push_commands')->where('push_device_id', $device->id)
            ->where(function ($query): void {
                $query->where('state', 'queued')->orWhere(function ($query): void {
                    $query->where('state', 'sent')->where('sent_at', '<=', now()->subSeconds(30));
                });
            })->where('attempts', '<', 5)->orderBy('id')->limit(20)->get();
        $commands = [];
        foreach ($rows as $row) {
            if (! in_array($row->url, self::READ_ONLY_URLS, true)) {
                throw new InvalidArgumentException('Command no longer allowlisted.');
            }
            $commands[] = ['UUID' => $row->command_uuid, 'URL' => $row->url, 'dataFormat' => 'noData', 'data' => ''];
            DB::table('ec_hikvision_push_commands')->where('id', $row->id)->update([
                'state' => 'sent', 'attempts' => $row->attempts + 1, 'sent_at' => now(),
            ]);
        }

        return ['commandNum' => count($commands), 'commandList' => $commands];
    }

    public function results(object $device, array $body): array
    {
        // The supplier's 2026-08-27 demo documents firmware omitting this field.
        // Infer only a missing format on command replies, never on access events.
        if (is_array($body['commandList'] ?? null)) {
            foreach ($body['commandList'] as &$entry) {
                if (is_array($entry) && ! array_key_exists('dataFormat', $entry)) {
                    $text = ltrim(PushPayload::text($entry));
                    $entry['dataFormat'] = match ($text[0] ?? '') {
                        '<' => 'xmlData',
                        '{' => 'jsonData',
                        default => throw new InvalidArgumentException('Unrecognized command result format.'),
                    };
                }
            }
            unset($entry);
        }
        foreach (PushReceiver::batch($body, 'commandNum', 'commandList') as $entry) {
            $text = PushPayload::text($entry);
            if (app(EnrollmentCommands::class)->handles($device, $entry, $text)) {
                continue;
            }
            $command = DB::table('ec_hikvision_push_commands')->where('push_device_id', $device->id)->where('command_uuid', $entry['UUID'])->first();
            if (! $command || $command->state === 'queued') {
                throw new InvalidArgumentException('Unknown or unsent command result.');
            }
            $text = PushPayload::text($entry);
            $hash = hash('sha256', $text);
            if ($command->result_hash !== null) {
                if (! hash_equals($command->result_hash, $hash)) {
                    throw new InvalidArgumentException('Conflicting command result.');
                }

                continue;
            }
            $summary = $this->summary($entry, $text, $command->url);
            DB::table('ec_hikvision_push_commands')->where('id', $command->id)->update([
                'state' => ($summary['failed'] ?? false) ? 'failed' : 'completed', 'result_hash' => $hash,
                'result_summary' => json_encode($summary, JSON_THROW_ON_ERROR), 'completed_at' => now(),
            ]);
        }

        $enrollmentPending = config('hikvision_push.enrollment_enabled') && DB::table('ec_hikvision_enrollment_commands as c')
            ->join('ec_hikvision_enrollments as e', 'e.id', '=', 'c.enrollment_id')
            ->where('e.push_device_id', $device->id)->where('c.state', 'queued')->exists();

        return ['isPendingCommand' => $enrollmentPending || DB::table('ec_hikvision_push_commands')->where('push_device_id', $device->id)->where('state', 'queued')->exists()];
    }

    private function summary(array $entry, string $text, string $url): array
    {
        if (in_array($url, self::ENROLLMENT_DISCOVERY_URLS, true)) {
            return EnrollmentCapabilities::summary($entry, $text, $url);
        }
        if (in_array($url, self::SECURITY_CAPABILITY_URLS, true)) {
            return $this->securityCapabilitySummary($entry, $text);
        }
        if (in_array($url, self::SECURITY_URLS, true)) {
            return $this->securitySummary($entry, $text);
        }
        $fields = ['model', 'serialNumber', 'firmwareVersion', 'statusCode', 'status', 'code'];
        $summary = [];
        if (($entry['dataFormat'] ?? null) === 'xmlData') {
            $xpath = PushPayload::xml($text);
            foreach ($fields as $field) {
                $value = $xpath->evaluate('string(//*[local-name()="'.$field.'"])');
                if ($value !== '') {
                    $summary[$field] = mb_substr($value, 0, 128);
                }
            }
        } elseif (($entry['dataFormat'] ?? null) === 'jsonData') {
            $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new InvalidArgumentException('Invalid command JSON.');
            }
            foreach ($fields as $field) {
                if (is_scalar($data[$field] ?? null)) {
                    $summary[$field] = mb_substr((string) $data[$field], 0, 128);
                }
            }
        } else {
            throw new InvalidArgumentException('Unsupported command result format.');
        }
        $summary['failed'] = (isset($summary['statusCode']) && $summary['statusCode'] !== '1')
            || (isset($summary['status']) && $summary['status'] !== '200')
            || (isset($summary['code']) && $summary['code'] !== '0x00000000');
        $summary['received'] = true;

        return $summary;
    }

    private function securitySummary(array $entry, string $text): array
    {
        // This endpoint may include credentials. Never persist arbitrary strings, the raw
        // document, or even the generic identity/status summary for a configuration read.
        if (($entry['dataFormat'] ?? null) === 'xmlData') {
            PushPayload::xml($text);

            return ['configuration_recognized' => false, 'received' => true, 'failed' => true,
                'reason' => 'unexpected_response_format'];
        }
        if (($entry['dataFormat'] ?? null) !== 'jsonData') {
            throw new InvalidArgumentException('Unsupported security configuration format.');
        }
        $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('Invalid security configuration object.');
        }
        $settings = is_array($data['PUSHCfg'] ?? null) ? $data['PUSHCfg'] : $data;
        $recognized = is_bool($settings['enabled'] ?? null)
            && in_array($settings['protocolType'] ?? null, ['http', 'https'], true);
        $deviceError = (isset($data['statusCode']) && (string) $data['statusCode'] !== '1')
            || (isset($data['code']) && $data['code'] !== '0x00000000')
            || (is_numeric($data['status'] ?? null) && (int) $data['status'] !== 200);
        $summary = ['configuration_recognized' => $recognized && ! $deviceError,
            'received' => true, 'failed' => ! $recognized || $deviceError];
        if (! $summary['configuration_recognized']) {
            return $summary;
        }
        $summary['enabled'] = $settings['enabled'];
        $summary['protocolType'] = $settings['protocolType'];
        $summary['certificate_setting_present'] = array_key_exists('httpsCertificateEnabled', $settings);
        $summary['certificate_setting_is_boolean'] = is_bool($settings['httpsCertificateEnabled'] ?? null);
        foreach (['httpsCertificateEnabled', 'notCertifiedEnabled'] as $field) {
            if (is_bool($settings[$field] ?? null)) {
                $summary[$field] = $settings[$field];
            }
        }
        if (in_array($settings['status'] ?? null, ['online', 'offline'], true)) {
            $summary['connection_state'] = $settings['status'];
        }

        return $summary;
    }

    private function securityCapabilitySummary(array $entry, string $text): array
    {
        $field = null;
        $present = false;
        $error = false;
        $statusCode = null;
        $subStatus = null;
        if (($entry['dataFormat'] ?? null) === 'xmlData') {
            $xpath = PushPayload::xml($text);
            $nodes = $xpath->query('//*[local-name()="httpsCertificateEnabled"]');
            if ($nodes->length > 1) {
                throw new InvalidArgumentException('Ambiguous certificate capability.');
            }
            $present = $nodes->length === 1;
            if ($present) {
                $node = $nodes->item(0);
                $field = ['opt' => $node->getAttribute('opt'), 'value' => trim($node->textContent)];
            }
            $statusCode = $xpath->evaluate('string(//*[local-name()="statusCode"])');
            $subStatus = $xpath->evaluate('string(//*[local-name()="subStatusCode"])');
            $error = $statusCode !== '' && $statusCode !== '1';
        } elseif (($entry['dataFormat'] ?? null) === 'jsonData') {
            $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data) || array_is_list($data)) {
                throw new InvalidArgumentException('Invalid capability object.');
            }
            $scope = $data;
            foreach (['PUSHCfgCap', 'PUSHCfg', 'Capabilities'] as $wrapper) {
                if (is_array($data[$wrapper] ?? null)) {
                    $scope = $data[$wrapper];
                    break;
                }
            }
            $present = array_key_exists('httpsCertificateEnabled', $scope);
            $field = $scope['httpsCertificateEnabled'] ?? null;
            $statusCode = $data['statusCode'] ?? null;
            $subStatus = $data['subStatusCode'] ?? null;
            $error = ($statusCode !== null && ! in_array($statusCode, [1, '1'], true))
                || (isset($data['code']) && $data['code'] !== '0x00000000')
                || (is_numeric($data['status'] ?? null) && (int) $data['status'] !== 200);
        } else {
            throw new InvalidArgumentException('Unsupported capability format.');
        }
        // Only a bounded status number, fixed labels and typed booleans are retained.
        // No raw configuration, unknown node names/strings, addresses or credentials.
        $summary = ['received' => true, 'failed' => $error, 'certificate_field_present' => ! $error && $present];
        if (in_array($statusCode, [1, '1', 2, '2', 3, '3', 4, '4', 5, '5', 6, '6', 7, '7'], true)) {
            $summary['response_status_code'] = (int) $statusCode;
        }
        if ($error) {
            $summary['response_error'] = in_array($subStatus, ['invalidOperation', 'notSupport', 'methodNotAllowed'], true)
                ? $subStatus : 'other_error';

            return $summary;
        }
        if ($present) {
            $value = is_array($field) ? ($field['value'] ?? null) : $field;
            if (in_array($value, [true, false, 'true', 'false'], true)) {
                $summary['reported_capability_boolean'] = $value === true || $value === 'true';
            }
            $options = is_array($field) ? ($field['@opt'] ?? $field['opt'] ?? null) : null;
            $options = is_string($options) ? array_map('trim', explode(',', $options)) : $options;
            if (is_array($options) && array_is_list($options) && count($options) <= 2
                && count(array_filter($options, static fn ($option): bool => in_array($option, [true, false, 'true', 'false'], true))) === count($options)) {
                $options = array_map(static fn ($option): bool => $option === true || $option === 'true', $options);
                $summary['certificate_boolean_options'] = array_values(array_filter([false, true], static fn (bool $value): bool => in_array($value, $options, true)));
            }
        }

        return $summary;
    }
}
