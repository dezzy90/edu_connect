<?php

return [
    'providers' => [
        'edu_admin' => [
            'driver' => env('EDU_ADMIN_CONNECTOR_DRIVER', 'fixture'),
            'fixture_path' => env('EDU_ADMIN_CONNECTOR_FIXTURE_PATH'),
            'api_version' => env('EDU_ADMIN_CONNECTOR_API_VERSION', 'v1'),
            'timeout_seconds' => (int) env('EDU_ADMIN_CONNECTOR_TIMEOUT', 15),
            'retry_attempts' => (int) env('EDU_ADMIN_CONNECTOR_RETRIES', 3),
        ],
    ],

    'feature_defaults' => [
        'standalone' => [
            'attendance' => true,
            'messages' => true,
            'push_notifications' => true,
            'realtime_updates' => true,
            'results' => false,
            'fees' => false,
            'timetable' => false,
            'discipline' => false,
            'two_way_chat' => false,
        ],
        'connected' => [
            'attendance' => true,
            'messages' => true,
            'push_notifications' => true,
            'realtime_updates' => true,
            'results' => true,
            'fees' => true,
            'timetable' => true,
            'discipline' => true,
            'two_way_chat' => false,
        ],
    ],

    'sync' => [
        'default_batch_size' => (int) env('INTEGRATION_SYNC_BATCH_SIZE', 250),
        'outbox_retry_minutes' => (int) env('INTEGRATION_OUTBOX_RETRY_MINUTES', 5),
        'outbox_max_attempts' => (int) env('INTEGRATION_OUTBOX_MAX_ATTEMPTS', 5),
    ],

    'scheduler' => [
        'enabled' => (bool) env('EDUCONNECT_SCHEDULER_ENABLED', true),
        'queue' => env('EDUCONNECT_SCHEDULER_QUEUE', 'edu-connect'),
        'connection_batch_size' => (int) env('EDUCONNECT_SCHEDULED_CONNECTION_BATCH_SIZE', 25),
        'incremental_sync_every_minutes' => (int) env('EDUCONNECT_INCREMENTAL_SYNC_EVERY_MINUTES', 5),
        'attendance_push_every_minutes' => (int) env('EDUCONNECT_ATTENDANCE_PUSH_EVERY_MINUTES', 1),
        'mobile_message_publish_every_minutes' => (int) env('EDUCONNECT_MOBILE_MESSAGE_PUBLISH_EVERY_MINUTES', 1),
        'push_dispatch_every_minutes' => (int) env('EDUCONNECT_PUSH_DISPATCH_EVERY_MINUTES', 1),
        'attendance_push_limit' => (int) env('EDUCONNECT_ATTENDANCE_PUSH_LIMIT', 50),
        'mobile_message_publish_limit' => (int) env('EDUCONNECT_MOBILE_MESSAGE_PUBLISH_LIMIT', 50),
        'push_dispatch_limit' => (int) env('EDUCONNECT_PUSH_DISPATCH_LIMIT', 50),
        'overlap_expiration_minutes' => (int) env('EDUCONNECT_SCHEDULE_OVERLAP_EXPIRATION_MINUTES', 30),
    ],
];
