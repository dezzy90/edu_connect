<?php

use Illuminate\Support\Facades\Schedule;

if (config('gate.parent_delivery_enabled') === true && config('gate.parent_push_enabled') === true) {
    Schedule::job(new \App\Jobs\V2\Notifications\DispatchGateParentAlertsJob, (string) config('integrations.scheduler.queue', 'edu-connect'))
        ->everyMinute()->name('gate.parent-alerts')->onOneServer();
}

if (config('gate.enabled') === true) {
    Schedule::command('gate:work --limit=10 --seconds=50 --interval-ms=2000 --json')->everyMinute()
        ->name('gate.projection-delivery')->withoutOverlapping(5)->onOneServer();
}
if (config('hikvision_push.enrollment_enabled') === true && config('hikvision_push.enrollment_work_enabled') === true) {
    Schedule::command('hikvision:enrollments-work --limit=20')->everyMinute()
        ->name('hikvision.enrollment-work')->withoutOverlapping(5)->onOneServer();
}
