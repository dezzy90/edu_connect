<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\V2\IntegrationConnection;
use App\Jobs\V2\Integration\PushEduAdminAttendanceOutboxJob;
use App\Jobs\V2\Integration\RunEduAdminIncrementalSyncJob;
use App\Jobs\V2\Notifications\DispatchMobilePushNotificationsJob;
use App\Jobs\V2\Notifications\PublishDueMobileMessagesJob;
use App\Services\Integration\AttendanceOutboxDispatcher;
use App\Services\Integration\EduAdminConnectorFactory;
use App\Services\Integration\SyncCoordinator;
use App\Services\Notifications\MobileMessagePublisher;
use App\Services\Notifications\PushNotificationDispatcher;
use App\Services\Realtime\RealtimeConfigurationHealth;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('educonnect:realtime-check {--json : Output machine-readable JSON}', function (
    RealtimeConfigurationHealth $realtimeHealth
) {
    $health = $realtimeHealth->snapshot();

    if ($this->option('json')) {
        $this->line(json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $health['enabled'] && ! $health['ready'] ? 1 : 0;
    }

    $this->line('EduConnect realtime status: ' . $health['status']);
    $this->line('Driver: ' . $health['driver']);
    $this->line('Broadcast connection: ' . $health['broadcast_connection']);
    $this->line('Websocket URL: ' . ($health['websocket_url'] ?? 'not ready'));

    foreach ($health['problems'] as $problem) {
        $this->error($problem);
    }

    foreach ($health['warnings'] as $warning) {
        $this->warn($warning);
    }

    if (! $health['enabled']) {
        $this->warn('Realtime is disabled. Set REALTIME_ENABLED=true when the provider is ready.');

        return 0;
    }

    if (! $health['ready']) {
        $this->error('Realtime is enabled but not ready.');

        return 1;
    }

    $this->info('Realtime is ready for mobile websocket connections.');

    return 0;
})->purpose('Validate EduConnect mobile realtime broadcast and websocket configuration');

Artisan::command('educonnect:sync-initial {connection_id : The ec_integration_connections ID} {--driver= : Connector driver to use} {--fixture-path= : Fixture path for local verification}', function (
    EduAdminConnectorFactory $connectors,
    SyncCoordinator $sync
) {
    $connection = IntegrationConnection::query()->findOrFail((int) $this->argument('connection_id'));

    $connector = $connectors->make($connection, [
        'driver' => $this->option('driver') ?: null,
        'fixture_path' => $this->option('fixture-path') ?: null,
    ]);

    $run = $sync->runInitialSync($connection, $connector, [
        'triggered_by_type' => 'console',
        'metadata' => [
            'command' => 'educonnect:sync-initial',
            'driver' => $this->option('driver') ?: config('integrations.providers.edu_admin.driver', 'fixture'),
        ],
    ]);

    $this->info(sprintf(
        'Initial sync #%d completed: read=%d created=%d updated=%d failed=%d',
        $run->id,
        $run->records_read,
        $run->records_created,
        $run->records_updated,
        $run->records_failed
    ));
})->purpose('Run the initial Edu-admin pull sync for an Edu-connect integration connection');

Artisan::command('educonnect:sync-incremental {connection_id : The ec_integration_connections ID} {--driver= : Connector driver to use} {--fixture-path= : Fixture path for local verification} {--updated-after= : ISO timestamp override for changed-record pulls} {--cursor= : Optional page cursor to resume from} {--resource=* : Limit sync to one or more resources}', function (
    EduAdminConnectorFactory $connectors,
    SyncCoordinator $sync
) {
    $connection = IntegrationConnection::query()->findOrFail((int) $this->argument('connection_id'));
    $resources = array_values(array_filter((array) $this->option('resource')));

    $connector = $connectors->make($connection, [
        'driver' => $this->option('driver') ?: null,
        'fixture_path' => $this->option('fixture-path') ?: null,
    ]);

    $run = $sync->runIncrementalSync($connection, $connector, [
        'triggered_by_type' => 'console',
        'updated_after' => $this->option('updated-after') ?: null,
        'cursor' => $this->option('cursor') ?: null,
        'resources' => $resources ?: null,
        'metadata' => [
            'command' => 'educonnect:sync-incremental',
            'driver' => $this->option('driver') ?: config('integrations.providers.edu_admin.driver', 'fixture'),
            'resources' => $resources ?: null,
        ],
    ]);

    $this->info(sprintf(
        'Incremental sync #%d completed: read=%d created=%d updated=%d failed=%d',
        $run->id,
        $run->records_read,
        $run->records_created,
        $run->records_updated,
        $run->records_failed
    ));
})->purpose('Run an incremental Edu-admin pull sync for an Edu-connect integration connection');

Artisan::command('educonnect:push-attendance {connection_id : The ec_integration_connections ID} {--driver= : Connector driver to use} {--fixture-path= : Fixture path for local verification} {--limit=50 : Maximum attendance events to push}', function (
    EduAdminConnectorFactory $connectors,
    AttendanceOutboxDispatcher $dispatcher
) {
    $connection = IntegrationConnection::query()->findOrFail((int) $this->argument('connection_id'));
    $limit = max(1, (int) $this->option('limit'));

    $connector = $connectors->make($connection, [
        'driver' => $this->option('driver') ?: null,
        'fixture_path' => $this->option('fixture-path') ?: null,
    ]);

    $queued = $dispatcher->enqueuePending($connection, $limit);
    $pushed = $dispatcher->dispatchPending($connection, $connector, $limit);

    $this->info(sprintf(
        'Attendance push completed: queued=%d skipped=%d sent=%d duplicates=%d failed=%d',
        $queued['queued'],
        $queued['skipped'],
        $pushed['sent'],
        $pushed['duplicates'],
        $queued['failed'] + $pushed['failed']
    ));
})->purpose('Push queued Edu-connect attendance events to Edu-admin');

Artisan::command('educonnect:dispatch-push-notifications {--limit=50 : Maximum notifications or deliveries to process}', function (
    PushNotificationDispatcher $dispatcher
) {
    $limit = max(1, (int) $this->option('limit'));

    $queued = $dispatcher->enqueuePending($limit);
    $sent = $dispatcher->dispatchQueued($limit);

    $this->info(sprintf(
        'Mobile push dispatch completed: notifications=%d deliveries_queued=%d sent=%d skipped=%d failed=%d',
        $queued['notifications'],
        $queued['deliveries_queued'],
        $sent['sent'],
        $queued['skipped'] + $sent['skipped'],
        $sent['failed']
    ));
})->purpose('Create and dispatch mobile push notification delivery rows');

Artisan::command('educonnect:publish-mobile-messages {--limit=50 : Maximum published messages to expand into recipients}', function (
    MobileMessagePublisher $publisher
) {
    $limit = max(1, (int) $this->option('limit'));
    $published = $publisher->publishDue($limit);

    $this->info(sprintf(
        'Mobile message publishing completed: messages=%d recipients_created=%d notifications_created=%d skipped=%d',
        $published['messages'],
        $published['recipients_created'],
        $published['notifications_created'],
        $published['skipped']
    ));
})->purpose('Expand published mobile messages into parent recipients and notifications');

Artisan::command('educonnect:dispatch-scheduled-work {--only=* : Limit to sync, attendance, messages, or push} {--connection=* : Limit connection-scoped jobs to specific ec_integration_connections IDs} {--connection-limit= : Maximum active connections to scan} {--queue= : Queue name for dispatched jobs} {--driver= : Connector driver override} {--fixture-path= : Fixture path for local verification} {--sync-resource=* : Limit incremental sync to one or more resources} {--attendance-limit= : Maximum attendance events per connection} {--message-limit= : Maximum mobile messages to publish} {--push-limit= : Maximum push notifications or deliveries to process}', function () {
    $validTasks = collect(['sync', 'attendance', 'messages', 'push']);
    $requestedTasks = collect((array) $this->option('only'))
        ->map(fn ($task) => strtolower(trim((string) $task)))
        ->filter()
        ->values();

    $tasks = $requestedTasks->isEmpty() ? $validTasks : $requestedTasks;
    $invalidTasks = $tasks->diff($validTasks);

    if ($invalidTasks->isNotEmpty()) {
        $this->error('Invalid scheduled task(s): ' . $invalidTasks->implode(', '));

        return 1;
    }

    $queue = (string) ($this->option('queue') ?: config('integrations.scheduler.queue', 'edu-connect'));
    $driver = $this->option('driver') ?: null;
    $fixturePath = $this->option('fixture-path') ?: null;
    $connectionLimit = max(1, (int) ($this->option('connection-limit') ?: config('integrations.scheduler.connection_batch_size', 25)));
    $attendanceLimit = max(1, (int) ($this->option('attendance-limit') ?: config('integrations.scheduler.attendance_push_limit', 50)));
    $messageLimit = max(1, (int) ($this->option('message-limit') ?: config('integrations.scheduler.mobile_message_publish_limit', 50)));
    $pushLimit = max(1, (int) ($this->option('push-limit') ?: config('integrations.scheduler.push_dispatch_limit', 50)));
    $resources = collect((array) $this->option('sync-resource'))
        ->map(fn ($resource) => trim((string) $resource))
        ->filter()
        ->values()
        ->all();
    $connectionIds = collect((array) $this->option('connection'))
        ->map(fn ($id) => (int) $id)
        ->filter(fn ($id) => $id > 0)
        ->values();

    $connections = IntegrationConnection::query()
        ->where('provider', 'edu_admin')
        ->where('mode', 'connected')
        ->where('status', 'active')
        ->when($connectionIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $connectionIds->all()))
        ->orderBy('id')
        ->limit($connectionLimit)
        ->get(['id']);

    $stats = [
        'sync' => 0,
        'attendance' => 0,
        'messages' => 0,
        'push' => 0,
    ];

    if ($tasks->contains('sync')) {
        $connections->each(function (IntegrationConnection $connection) use ($driver, $fixturePath, $resources, $queue, &$stats): void {
            RunEduAdminIncrementalSyncJob::dispatch($connection->id, $driver, $fixturePath, null, $resources)
                ->onQueue($queue);

            $stats['sync']++;
        });
    }

    if ($tasks->contains('attendance')) {
        $connections->each(function (IntegrationConnection $connection) use ($attendanceLimit, $driver, $fixturePath, $queue, &$stats): void {
            PushEduAdminAttendanceOutboxJob::dispatch($connection->id, $attendanceLimit, $driver, $fixturePath)
                ->onQueue($queue);

            $stats['attendance']++;
        });
    }

    if ($tasks->contains('messages')) {
        PublishDueMobileMessagesJob::dispatch($messageLimit)->onQueue($queue);
        $stats['messages']++;
    }

    if ($tasks->contains('push')) {
        DispatchMobilePushNotificationsJob::dispatch($pushLimit)->onQueue($queue);
        $stats['push']++;
    }

    $this->info(sprintf(
        'Scheduled work dispatched on [%s]: sync=%d attendance=%d messages=%d push=%d active_connections=%d',
        $queue,
        $stats['sync'],
        $stats['attendance'],
        $stats['messages'],
        $stats['push'],
        $connections->count(),
    ));

    return 0;
})->purpose('Dispatch queued Edu-connect scheduled jobs for active Edu-admin connections and mobile notifications');

if (config('integrations.scheduler.enabled', true)) {
    $scheduleEveryMinutes = static function (string $task, int $minutes, array $options = []): void {
        $minutes = max(1, min(59, $minutes));
        $queue = (string) config('integrations.scheduler.queue', 'edu-connect');
        $connectionLimit = max(1, (int) config('integrations.scheduler.connection_batch_size', 25));
        $overlapExpiration = max(1, (int) config('integrations.scheduler.overlap_expiration_minutes', 30));
        $command = 'educonnect:dispatch-scheduled-work'
            . " --only={$task}"
            . " --queue={$queue}"
            . " --connection-limit={$connectionLimit}";

        foreach ($options as $name => $value) {
            $command .= " --{$name}={$value}";
        }

        $event = Schedule::command($command)
            ->cron($minutes === 1 ? '* * * * *' : "*/{$minutes} * * * *")
            ->name("edu-connect.{$task}")
            ->withoutOverlapping($overlapExpiration);

        $event->onOneServer();
    };

    $scheduleEveryMinutes('sync', (int) config('integrations.scheduler.incremental_sync_every_minutes', 5));
    $scheduleEveryMinutes('attendance', (int) config('integrations.scheduler.attendance_push_every_minutes', 1), [
        'attendance-limit' => max(1, (int) config('integrations.scheduler.attendance_push_limit', 50)),
    ]);
    $scheduleEveryMinutes('messages', (int) config('integrations.scheduler.mobile_message_publish_every_minutes', 1), [
        'message-limit' => max(1, (int) config('integrations.scheduler.mobile_message_publish_limit', 50)),
    ]);
    $scheduleEveryMinutes('push', (int) config('integrations.scheduler.push_dispatch_every_minutes', 1), [
        'push-limit' => max(1, (int) config('integrations.scheduler.push_dispatch_limit', 50)),
    ]);
}
