<?php

namespace App\Services\Notifications;

use App\Models\V2\MobileNotification;
use App\Models\V2\MobilePushToken;
use App\Models\V2\NotificationDelivery;
use App\Models\V2\NotificationPreference;
use App\Services\Notifications\Push\PushDeliveryResult;
use App\Services\Notifications\Push\PushTransportManager;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class PushNotificationDispatcher
{
    private const PUSH_CHANNELS = ['push', 'in_app_push', 'all'];

    public function __construct(private readonly PushTransportManager $transports)
    {
    }

    public function enqueuePending(int $limit = 50): array
    {
        $stats = [
            'notifications' => 0,
            'deliveries_queued' => 0,
            'skipped' => 0,
        ];

        MobileNotification::query()
            ->with(['parentAccount.pushTokens'])
            ->whereIn('channel', self::PUSH_CHANNELS)
            ->whereIn('delivery_status', ['queued', 'failed'])
            ->visible()
            ->oldest()
            ->limit($limit)
            ->get()
            ->each(function (MobileNotification $notification) use (&$stats): void {
                $stats['notifications']++;
                $queued = $this->enqueueNotification($notification);

                if ($queued === 0) {
                    $stats['skipped']++;
                }

                $stats['deliveries_queued'] += $queued;
            });

        return $stats;
    }

    public function enqueueNotification(MobileNotification $notification): int
    {
        $parent = $notification->parentAccount;

        if (!$parent || !$this->pushIsEnabled($notification)) {
            return 0;
        }

        $queued = 0;

        $parent->pushTokens()
            ->whereNull('revoked_at')
            ->get()
            ->each(function (MobilePushToken $pushToken) use ($notification, &$queued): void {
                $delivery = NotificationDelivery::query()->firstOrCreate(
                    [
                        'notification_id' => $notification->id,
                        'push_token_id' => $pushToken->id,
                    ],
                    [
                        'provider' => $pushToken->provider,
                        'status' => 'queued',
                        'queued_at' => now(),
                    ],
                );

                if ($delivery->wasRecentlyCreated || in_array($delivery->status, ['queued', 'failed'], true)) {
                    $queued++;
                }
            });

        return $queued;
    }

    public function dispatchQueued(int $limit = 100): array
    {
        $stats = [
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        NotificationDelivery::query()
            ->with(['notification', 'pushToken'])
            ->where(function (Builder $query): void {
                $query->where('status', 'queued')
                    ->orWhere(function (Builder $failed): void {
                        $failed->where('status', 'failed')
                            ->where('attempts', '<', $this->maxAttempts())
                            ->where(function (Builder $due): void {
                                $due->whereNull('next_attempt_at')
                                    ->orWhere('next_attempt_at', '<=', now());
                            });
                    });
            })
            ->oldest('queued_at')
            ->limit($limit)
            ->get()
            ->each(function (NotificationDelivery $delivery) use (&$stats): void {
                $this->dispatchDelivery($delivery, $stats);
            });

        return $stats;
    }

    private function dispatchDelivery(NotificationDelivery $delivery, array &$stats): void
    {
        $delivery->forceFill([
            'attempts' => $delivery->attempts + 1,
        ])->save();

        if (!$delivery->notification) {
            $this->skip($delivery, 'Notification is missing.');
            $stats['skipped']++;
            return;
        }

        if (!$delivery->pushToken || $delivery->pushToken->revoked_at) {
            $this->skip($delivery, 'Push token is missing or revoked.');
            $stats['skipped']++;
            return;
        }

        try {
            $result = $this->transports->resolve($delivery)->send($delivery);
        } catch (RuntimeException $exception) {
            $result = PushDeliveryResult::failed($exception->getMessage());
        }

        if ($result->successful) {
            $delivery->forceFill([
                'status' => 'sent',
                'provider_message_id' => $result->messageId,
                'provider_response' => $result->providerResponse,
                'sent_at' => now(),
                'failed_at' => null,
                'next_attempt_at' => null,
                'last_error' => null,
            ])->save();

            $stats['sent']++;
            $this->syncNotificationDeliveryStatus($delivery->notification);
            return;
        }

        if ($result->invalidToken) {
            $delivery->pushToken->forceFill(['revoked_at' => now()])->save();

            $this->skip($delivery, $result->error ?: 'Push token is invalid.', $result->providerResponse);
            $stats['skipped']++;
            return;
        }

        $delivery->forceFill([
            'status' => 'failed',
            'provider_response' => $result->providerResponse,
            'last_error' => $result->error ?: 'Push delivery failed.',
            'failed_at' => now(),
            'next_attempt_at' => $delivery->attempts < $this->maxAttempts()
                ? now()->addSeconds($this->retryDelaySeconds($delivery->attempts))
                : null,
        ])->save();

        $stats['failed']++;
        $this->syncNotificationDeliveryStatus($delivery->notification);
    }

    private function skip(NotificationDelivery $delivery, string $reason, array $providerResponse = []): void
    {
        $delivery->forceFill([
            'status' => 'skipped',
            'provider_response' => $providerResponse,
            'last_error' => $reason,
            'failed_at' => null,
            'next_attempt_at' => null,
        ])->save();

        if ($delivery->notification) {
            $this->syncNotificationDeliveryStatus($delivery->notification);
        }
    }

    private function syncNotificationDeliveryStatus(MobileNotification $notification): void
    {
        $counts = NotificationDelivery::query()
            ->where('notification_id', $notification->id)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        if ((int) ($counts['queued'] ?? 0) > 0) {
            return;
        }

        if ((int) ($counts['sent'] ?? 0) > 0) {
            $notification->forceFill([
                'delivery_status' => 'sent',
                'sent_at' => $notification->sent_at ?? now(),
            ])->save();

            return;
        }

        if ((int) ($counts['failed'] ?? 0) > 0) {
            $notification->forceFill(['delivery_status' => 'failed'])->save();
            return;
        }

        if ((int) ($counts['skipped'] ?? 0) > 0) {
            $notification->forceFill(['delivery_status' => 'skipped'])->save();
        }
    }

    private function pushIsEnabled(MobileNotification $notification): bool
    {
        $preference = NotificationPreference::query()
            ->where('parent_account_id', $notification->parent_account_id)
            ->where('category', $notification->type)
            ->first();

        return $preference?->push_enabled ?? true;
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('educonnect.notifications.push_max_attempts', 3));
    }

    private function retryDelaySeconds(int $attempts): int
    {
        $base = max(1, (int) config('educonnect.notifications.push_retry_backoff_seconds', 300));
        $max = max($base, (int) config('educonnect.notifications.push_max_retry_backoff_seconds', 3600));

        return min($max, $base * (2 ** max(0, $attempts - 1)));
    }
}
