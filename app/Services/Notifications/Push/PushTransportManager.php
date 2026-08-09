<?php

namespace App\Services\Notifications\Push;

use App\Models\V2\NotificationDelivery;
use RuntimeException;

class PushTransportManager
{
    public function __construct(
        private readonly LogPushTransport $log,
        private readonly FcmPushTransport $fcm,
        private readonly ApnsPushTransport $apns,
    ) {
    }

    public function resolve(NotificationDelivery $delivery): PushTransport
    {
        $transport = strtolower((string) config('educonnect.notifications.push_transport', 'log'));

        return match ($transport) {
            'log' => $this->log,
            'provider', 'remote' => $this->forProvider($delivery->provider),
            'fcm' => $this->fcm,
            'apns' => $this->apns,
            default => throw new RuntimeException("Push transport [{$transport}] is not supported."),
        };
    }

    private function forProvider(?string $provider): PushTransport
    {
        return match (strtolower((string) $provider)) {
            'fcm' => $this->fcm,
            'apns' => $this->apns,
            default => throw new RuntimeException("Push provider [{$provider}] is not supported by remote transports."),
        };
    }
}
