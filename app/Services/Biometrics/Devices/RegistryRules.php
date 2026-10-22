<?php

declare(strict_types=1);

namespace App\Services\Biometrics\Devices;

use Illuminate\Validation\ValidationException;

final class RegistryRules
{
    public static function input(array $body, bool $create): array
    {
        $operation = $create ? 'register' : ($body['operation'] ?? '');
        $extra = match ($operation) {
            'register' => ['tenant_id', 'school_ids', 'name', 'location', 'serial', 'firmware'],
            'edit' => ['name', 'location'],
            'reassign' => ['school_ids', 'confirmation'],
            'enable', 'pause', 'rotate', 'retire' => ['confirmation'],
            'verify' => [],
            default => [],
        };
        if (array_diff(array_keys($body), ['operation', 'expected_revision', 'idempotency_key', 'note', ...$extra]) !== []) {
            throw ValidationException::withMessages(['operation' => 'Unexpected device fields. Identity, credentials and activation cannot be supplied or forged.']);
        }
        foreach (['name', 'location', 'note'] as $field) {
            if (is_string($body[$field] ?? null)) {
                $body[$field] = trim($body[$field]);
            }
        }
        $strictInt = static function ($attribute, $value, $fail): void {
            if (! is_int($value)) {
                $fail('Use a whole JSON number.');
            }
        };
        $rules = ['expected_revision' => ['required', 'integer', 'min:0', $strictInt],
            'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,80}$/D'],
            'note' => ['required', 'string', 'min:8', 'max:500']];
        if (! $create) {
            $rules['operation'] = ['required', 'in:edit,reassign,enable,pause,rotate,retire,verify'];
        } else {
            $rules['operation'] = ['sometimes', 'in:register'];
        }
        foreach ($extra as $field) {
            $rules[$field] = match ($field) {
                'tenant_id' => ['required', 'integer', 'min:1', $strictInt],
                'school_ids' => ['required', 'array', 'min:1', 'max:20', static function ($attribute, $value, $fail): void {
                    if (! is_array($value) || ! array_is_list($value)) {
                        $fail('Choose a list of participating schools.');
                    }
                }],
                'name' => ['required', 'string', 'max:100'],
                'location' => ['required', 'string', 'max:120'],
                'serial', 'confirmation' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,128}$/D'],
                'firmware' => ['required', 'string', 'max:128', 'regex:/^V\d+\.\d+\.\d+(?:[ _]build\d{6,8})$/D'],
            };
        }
        if (in_array('school_ids', $extra, true)) {
            $rules['school_ids.*'] = ['required', 'integer', 'min:1', 'distinct', $strictInt];
        }
        $input = validator($body, $rules)->validate();
        $input['operation'] = $operation;
        foreach (['name', 'location', 'note'] as $field) {
            if (isset($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }
        if (isset($input['serial'])) {
            $input['serial'] = strtoupper($input['serial']);
        }
        if (isset($input['firmware'])) {
            $input['firmware'] = str_replace(' build', '_build', $input['firmware']);
        }
        if (isset($input['school_ids'])) {
            sort($input['school_ids']);
        }
        ksort($input);

        return $input;
    }

    public static function connection(): array
    {
        $host = config('device_registry.server_host');
        $port = config('device_registry.https_port');
        $ws = config('device_registry.websocket_port');
        $hostValid = is_string($host) && strlen($host) <= 253
            && (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                || preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/D', $host));
        $configured = $hostValid && is_int($port) && $port > 0 && $port <= 65535 && is_int($ws) && $ws > 0 && $ws <= 65535;
        $application = config('device_registry.receiver_mode') === 'application';

        return ['configured' => (bool) $configured, 'server_host' => $configured ? $host : null,
            'protocol' => 'HTTPS', 'https_port' => $configured ? $port : null, 'websocket_port' => $configured ? $ws : null,
            'receiver_mode' => in_array(config('device_registry.receiver_mode'), ['application', 'isolated'], true) ? config('device_registry.receiver_mode') : 'unconfigured',
            'may_enable' => (bool) ($configured && $application && config('device_registry.read_only') === false && config('device_registry.connection_enabled') === true && config('hikvision_push.enabled') === true),
            'message' => $configured && $application ? 'Only authenticated inbound PushSDK contact verifies a connection. No IP probe is performed.'
                : 'This host has no approved application receiver configuration. An isolated test receiver is not a fleet onboarding endpoint.'];
    }
}
