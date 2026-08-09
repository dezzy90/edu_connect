<?php

namespace App\Services\Notifications\Push;

use App\Models\V2\NotificationDelivery;
use Illuminate\Support\Facades\Log;

class LogPushTransport implements PushTransport
{
    public function send(NotificationDelivery $delivery): PushDeliveryResult
    {
        $delivery->loadMissing(['notification', 'pushToken']);

        Log::info('Edu-connect mobile push notification queued for provider delivery', [
            'notification_id' => $delivery->notification_id,
            'delivery_id' => $delivery->id,
            'provider' => $delivery->provider,
            'platform' => $delivery->pushToken?->platform,
            'type' => $delivery->notification?->type,
            'priority' => $delivery->notification?->priority,
            'transport' => 'log',
        ]);

        return PushDeliveryResult::sent('log-' . $delivery->id, [
            'transport' => 'log',
            'delivery_id' => $delivery->id,
        ]);
    }
}
