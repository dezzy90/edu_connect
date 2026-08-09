<?php

namespace App\Events\V2;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MobileRealtimeEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, string>  $channels
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $eventName,
        public readonly array $channels,
        public readonly array $payload = [],
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return collect($this->channels)
            ->map(fn (string $channel): string => $this->normalizeChannelName($channel))
            ->filter()
            ->unique()
            ->values()
            ->map(fn (string $channel): PrivateChannel => new PrivateChannel($channel))
            ->all();
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'event' => $this->eventName,
            'data' => $this->payload,
            'occurred_at' => now()->toIso8601String(),
        ];
    }

    private function normalizeChannelName(string $channel): string
    {
        $channel = trim($channel);

        if (str_starts_with($channel, 'private-')) {
            return substr($channel, strlen('private-'));
        }

        return $channel;
    }
}
