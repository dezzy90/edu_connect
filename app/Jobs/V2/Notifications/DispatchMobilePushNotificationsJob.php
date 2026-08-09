<?php

namespace App\Jobs\V2\Notifications;

use App\Services\Notifications\PushNotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchMobilePushNotificationsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $limit = 50)
    {
    }

    public function handle(PushNotificationDispatcher $dispatcher): void
    {
        $limit = max(1, $this->limit);

        $dispatcher->enqueuePending($limit);
        $dispatcher->dispatchQueued($limit);
    }
}
