<?php

return [
    // Explicit opt-in only. No live attendance projection is implemented here.
    'enabled' => env('HIKVISION_PUSH_ENABLED', false),
    'allow_loopback_http' => env('HIKVISION_PUSH_ALLOW_LOOPBACK_HTTP', false),
    'allow_open_multipart_end' => false,
    'enrollment_enabled' => (bool) env('HIKVISION_ENROLLMENT_ENABLED', false),
    'enrollment_work_enabled' => (bool) env('HIKVISION_ENROLLMENT_WORK_ENABLED', false),
    // Preparation/status can run in TEST without issuing any terminal writes.
    'enrollment_device_writes_enabled' => (bool) env('HIKVISION_ENROLLMENT_DEVICE_WRITES_ENABLED', false),
    // Optional TEST connection scope; invalid IDs remain 0 (deny), never widen scope.
    'enrollment_connection_ids' => trim((string) env('HIKVISION_ENROLLMENT_CONNECTION_IDS', '')) === '' ? []
        : array_map('intval', explode(',', (string) env('HIKVISION_ENROLLMENT_CONNECTION_IDS', ''))),
    'enrollment_max_photo_bytes' => 120000,
    'enrollment_max_source_bytes' => 4 * 1024 * 1024,
    'max_body_bytes' => 1024 * 1024,
    'max_event_bytes' => 256 * 1024,
    'command_interval' => 5,
    'error_delay' => 30,
    'login_iterations' => 5000,
    'lock_seconds' => 60,
    'future_tolerance_seconds' => 300,
];
