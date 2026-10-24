<?php

namespace App\Jobs\V2\Notifications;

use App\Services\Notifications\PushNotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Queue\Queueable;

final class DispatchGateParentAlertsJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    public int $tries = 3;
    public int $uniqueFor = 60;
    public function handle(PushNotificationDispatcher $dispatcher): void
    {
        if (config('gate.parent_delivery_enabled') !== true || config('gate.parent_push_enabled') !== true) return;
        $dispatcher->enqueueGatePending(100);
        $dispatcher->dispatchGateQueued(100);
    }
}
