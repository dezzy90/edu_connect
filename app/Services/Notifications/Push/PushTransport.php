<?php

namespace App\Services\Notifications\Push;

use App\Models\V2\NotificationDelivery;

interface PushTransport
{
    public function send(NotificationDelivery $delivery): PushDeliveryResult;
}
