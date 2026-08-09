<?php

namespace App\Providers;

use App\Models\V2\AttendanceEvent;
use App\Models\V2\MobileMessage;
use App\Models\V2\MobileNotification;
use App\Services\Notifications\AttendanceNotificationService;
use App\Services\Notifications\MobileMessagePublisher;
use App\Services\Realtime\MobileRealtimeBroadcaster;
use Illuminate\Support\ServiceProvider;

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
        });
    }
}
