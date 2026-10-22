<?php

return [
    'enabled' => (bool) env('DEVICE_REGISTRY_ENABLED', false),
    'read_only' => (bool) env('DEVICE_REGISTRY_READ_ONLY', true),
    'registration_enabled' => (bool) env('DEVICE_REGISTRY_REGISTRATION_ENABLED', false),
    'connection_enabled' => (bool) env('DEVICE_REGISTRY_CONNECTION_ENABLED', false),
    'shared_gate_enabled' => (bool) env('DEVICE_REGISTRY_SHARED_GATE_ENABLED', false),
    'tenant_ids' => (static function (): array {
        $text = trim((string) env('DEVICE_REGISTRY_TENANT_IDS', ''));
        if (! preg_match('/^[1-9]\d*(?:,[1-9]\d*)*$/D', $text)) {
            return [];
        }
        $ids = array_map('intval', explode(',', $text));

        return count($ids) === count(array_unique($ids)) && ! array_filter($ids, static fn ($id) => $id < 1) ? $ids : [];
    })(),
    'school_ids' => (static function (): array {
        $text = trim((string) env('DEVICE_REGISTRY_SCHOOL_IDS', ''));
        if (! preg_match('/^[1-9]\d*(?:,[1-9]\d*)*$/D', $text)) {
            return [];
        }
        $ids = array_map('intval', explode(',', $text));

        return count($ids) === count(array_unique($ids)) && ! array_filter($ids, static fn ($id) => $id < 1) ? $ids : [];
    })(),
    // Trusted host configuration, never a request-supplied URL or an SSRF connection probe.
    'server_host' => env('DEVICE_REGISTRY_SERVER_HOST', ''),
    'https_port' => (int) env('DEVICE_REGISTRY_HTTPS_PORT', 443),
    'websocket_port' => (int) env('DEVICE_REGISTRY_WEBSOCKET_PORT', 443),
    'receiver_mode' => env('DEVICE_REGISTRY_RECEIVER_MODE', 'unconfigured'),
];
