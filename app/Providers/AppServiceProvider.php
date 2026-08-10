<?php

namespace App\Providers;

use App\Models\V2\AttendanceEvent;
use App\Models\V2\MobileMessage;
use App\Models\V2\MobileNotification;
use App\Services\Notifications\AttendanceNotificationService;
use App\Services\Notifications\MobileMessagePublisher;
use App\Services\Notifications\PushNotificationDispatcher;
use App\Services\Realtime\MobileRealtimeBroadcaster;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        AttendanceEvent::created(function (AttendanceEvent $event): void {
            app(AttendanceNotificationService::class)->notify($event);
            app(MobileRealtimeBroadcaster::class)->attendanceRecorded($event);
        });

        MobileMessage::saved(function (MobileMessage $message): void {
            app(MobileMessagePublisher::class)->publish($message);
        });

        MobileNotification::created(function (MobileNotification $notification): void {
            app(MobileRealtimeBroadcaster::class)->notificationCreated($notification);
            $this->dispatchPushImmediately($notification);
        });
    }

    private function dispatchPushImmediately(MobileNotification $notification): void
    {
        $mode = strtolower((string) config('educonnect.notifications.push_dispatch_mode', 'disabled'));

        if ($mode !== 'inline') {
            return;
        }

        try {
            $dispatcher = app(PushNotificationDispatcher::class);
            $dispatcher->enqueueNotification($notification);
            $dispatcher->dispatchQueued(max(1, (int) config('educonnect.notifications.push_inline_limit', 25)));
        } catch (Throwable $exception) {
            Log::warning('EduConnect mobile push immediate dispatch failed.', [
                'notification_id' => $notification->id,
                'parent_account_id' => $notification->parent_account_id,
                'type' => $notification->type,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
