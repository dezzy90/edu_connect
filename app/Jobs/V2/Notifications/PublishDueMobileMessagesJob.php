<?php

namespace App\Jobs\V2\Notifications;

use App\Services\Notifications\MobileMessagePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishDueMobileMessagesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $limit = 50)
    {
    }

    public function handle(MobileMessagePublisher $publisher): void
    {
        $publisher->publishDue(max(1, $this->limit));
    }
}
