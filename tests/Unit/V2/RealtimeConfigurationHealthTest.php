<?php

use App\Services\Realtime\RealtimeConfigurationHealth;

uses(Tests\TestCase::class);

it('reports realtime as ready when provider and websocket settings are complete', function (): void {
    config([
        'broadcasting.default' => 'pusher',
        'broadcasting.connections.pusher.app_id' => 'app-id',
        'broadcasting.connections.pusher.key' => 'app-key',
        'broadcasting.connections.pusher.secret' => 'app-secret',
        'broadcasting.connections.pusher.options.cluster' => 'mt1',
        'educonnect.realtime.enabled' => true,
        'educonnect.realtime.driver' => 'pusher',
        'educonnect.realtime.app_key' => 'app-key',
        'educonnect.realtime.app_secret' => 'app-secret',
        'educonnect.realtime.host' => 'ws-mt1.pusher.com',
        'educonnect.realtime.port' => 443,
        'educonnect.realtime.scheme' => 'https',
    ]);

    $health = app(RealtimeConfigurationHealth::class)->snapshot();

    expect($health['status'])->toBe('ready')
        ->and($health['ready'])->toBeTrue()
        ->and($health['websocket_url'])->toBe('wss://ws-mt1.pusher.com/app/{REALTIME_APP_KEY}')
        ->and($health['problems'])->toBe([]);
});

it('reports realtime as misconfigured when enabled without provider credentials', function (): void {
    config([
        'broadcasting.default' => 'log',
        'broadcasting.connections.pusher.app_id' => null,
        'broadcasting.connections.pusher.key' => null,
        'broadcasting.connections.pusher.secret' => null,
        'educonnect.realtime.enabled' => true,
        'educonnect.realtime.app_key' => null,
        'educonnect.realtime.app_secret' => null,
        'educonnect.realtime.host' => '',
        'educonnect.realtime.port' => 443,
        'educonnect.realtime.scheme' => 'https',
    ]);

    $health = app(RealtimeConfigurationHealth::class)->snapshot();

    expect($health['status'])->toBe('misconfigured')
        ->and($health['ready'])->toBeFalse()
        ->and($health['problems'])->toContain(
            'BROADCAST_CONNECTION must be pusher for mobile realtime events.',
            'PUSHER_APP_ID is missing.',
            'PUSHER_APP_KEY is missing.',
            'PUSHER_APP_SECRET is missing.',
            'REALTIME_APP_KEY or PUSHER_APP_KEY is missing.',
            'REALTIME_APP_SECRET or PUSHER_APP_SECRET is missing.',
            'REALTIME_HOST is missing.',
        );
});
