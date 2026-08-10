<?php

namespace App\Services\Realtime;

class RealtimeConfigurationHealth
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $enabled = (bool) config('educonnect.realtime.enabled');
        $driver = (string) config('educonnect.realtime.driver', 'pusher');
        $broadcastConnection = (string) config('broadcasting.default', 'log');
        $appId = (string) config('broadcasting.connections.pusher.app_id', '');
        $broadcastKey = (string) config('broadcasting.connections.pusher.key', '');
        $broadcastSecret = (string) config('broadcasting.connections.pusher.secret', '');
        $appKey = (string) config('educonnect.realtime.app_key', '');
        $appSecret = (string) config('educonnect.realtime.app_secret', '');
        $host = trim((string) config('educonnect.realtime.host', ''));
        $port = (int) config('educonnect.realtime.port', 443);
        $scheme = strtolower((string) config('educonnect.realtime.scheme', 'https'));
        $cluster = (string) config('broadcasting.connections.pusher.options.cluster', '');
        $problems = [];
        $warnings = [];

        if ($enabled) {
            if ($broadcastConnection !== 'pusher') {
                $problems[] = 'BROADCAST_CONNECTION must be pusher for mobile realtime events.';
            }

            if ($appId === '') {
                $problems[] = 'PUSHER_APP_ID is missing.';
            }

            if ($broadcastKey === '') {
                $problems[] = 'PUSHER_APP_KEY is missing.';
            }

            if ($broadcastSecret === '') {
                $problems[] = 'PUSHER_APP_SECRET is missing.';
            }

            if ($appKey === '') {
                $problems[] = 'REALTIME_APP_KEY or PUSHER_APP_KEY is missing.';
            }

            if ($appSecret === '') {
                $problems[] = 'REALTIME_APP_SECRET or PUSHER_APP_SECRET is missing.';
            }

            if ($host === '') {
                $problems[] = 'REALTIME_HOST is missing.';
            }

            if (str_contains($host, '://') || str_contains($host, '/')) {
                $problems[] = 'REALTIME_HOST must be only a hostname, without https:// or a path.';
            }

            if ($port < 1 || $port > 65535) {
                $problems[] = 'REALTIME_PORT must be a valid TCP port.';
            }

            if (! in_array($scheme, ['http', 'https', 'ws', 'wss'], true)) {
                $problems[] = 'REALTIME_SCHEME must be http, https, ws, or wss.';
            }

            if (app()->environment('production') && in_array($host, ['127.0.0.1', 'localhost'], true)) {
                $problems[] = 'REALTIME_HOST cannot be localhost in production mobile builds.';
            }

            if (app()->environment('production') && in_array($scheme, ['http', 'ws'], true)) {
                $warnings[] = 'Use TLS in production: set REALTIME_SCHEME=https or wss.';
            }
        }

        $ready = $enabled && $problems === [];

        return [
            'enabled' => $enabled,
            'ready' => $ready,
            'status' => $enabled ? ($ready ? 'ready' : 'misconfigured') : 'disabled',
            'driver' => $driver,
            'broadcast_connection' => $broadcastConnection,
            'app_id_present' => $appId !== '',
            'broadcast_key_present' => $broadcastKey !== '',
            'broadcast_secret_present' => $broadcastSecret !== '',
            'app_key_present' => $appKey !== '',
            'app_secret_present' => $appSecret !== '',
            'cluster' => $cluster,
            'host' => $host,
            'port' => $port,
            'scheme' => $scheme,
            'websocket_url' => $ready ? $this->websocketUrl($host, $port, $scheme) : null,
            'problems' => $problems,
            'warnings' => $warnings,
        ];
    }

    public function ready(): bool
    {
        return (bool) $this->snapshot()['ready'];
    }

    private function websocketUrl(string $host, int $port, string $scheme): string
    {
        $websocketScheme = in_array($scheme, ['https', 'wss'], true) ? 'wss' : 'ws';
        $defaultPort = $websocketScheme === 'wss' ? 443 : 80;
        $portSuffix = $port === $defaultPort ? '' : ":{$port}";

        return "{$websocketScheme}://{$host}{$portSuffix}/app/{REALTIME_APP_KEY}";
    }
}
