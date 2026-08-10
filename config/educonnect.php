<?php

return [
    'mode' => env('EDUCONNECT_MODE', 'standalone'),
    'api_version' => env('EDUCONNECT_API_VERSION', 'v2'),
    'table_prefix' => env('EDUCONNECT_TABLE_PREFIX', 'ec_'),

    'mobile' => [
        'token_expiration_minutes' => (int) env('MOBILE_TOKEN_EXPIRATION', 43200),
        'default_locale' => env('MOBILE_DEFAULT_LOCALE', 'en'),
        'profile_refresh_ttl_seconds' => (int) env('MOBILE_PROFILE_REFRESH_TTL_SECONDS', 60),
    ],

    'attendance' => [
        'photo_retention_days' => (int) env('ATTENDANCE_PHOTO_RETENTION_DAYS', 7),
        'store_biometric_photos' => (bool) env('ATTENDANCE_STORE_BIOMETRIC_PHOTOS', false),
    ],

    'notifications' => [
        'push_provider' => env('PUSH_PROVIDER', 'fcm'),
        'push_transport' => env('PUSH_TRANSPORT', 'log'),
        'push_dispatch_mode' => env('PUSH_DISPATCH_MODE', env('APP_ENV') === 'testing' ? 'disabled' : 'inline'),
        'push_inline_limit' => (int) env('PUSH_INLINE_LIMIT', 25),
        'push_timeout_seconds' => (int) env('PUSH_TIMEOUT_SECONDS', 10),
        'push_max_attempts' => (int) env('PUSH_MAX_ATTEMPTS', 3),
        'push_retry_backoff_seconds' => (int) env('PUSH_RETRY_BACKOFF_SECONDS', 300),
        'push_max_retry_backoff_seconds' => (int) env('PUSH_MAX_RETRY_BACKOFF_SECONDS', 3600),
        'privacy_mode' => env('PUSH_PRIVACY_MODE', 'discreet'),
        'fcm' => [
            'project_id' => env('FCM_PROJECT_ID'),
            'access_token' => env('FCM_ACCESS_TOKEN'),
            'credentials_path' => env('FCM_CREDENTIALS_PATH'),
            'credentials_json' => env('FCM_CREDENTIALS_JSON'),
        ],
        'apns' => [
            'environment' => env('APNS_ENVIRONMENT', 'sandbox'),
            'team_id' => env('APNS_TEAM_ID'),
            'key_id' => env('APNS_KEY_ID'),
            'bundle_id' => env('APNS_BUNDLE_ID'),
            'private_key_path' => env('APNS_PRIVATE_KEY_PATH'),
            'private_key' => env('APNS_PRIVATE_KEY'),
            'bearer_token' => env('APNS_BEARER_TOKEN'),
        ],
    ],

    'realtime' => [
        'driver' => env('REALTIME_DRIVER', 'reverb'),
        'enabled' => (bool) env('REALTIME_ENABLED', true),
        'app_key' => env('REALTIME_APP_KEY', env('PUSHER_APP_KEY')),
        'app_secret' => env('REALTIME_APP_SECRET', env('PUSHER_APP_SECRET')),
        'host' => env('REALTIME_HOST', env('PUSHER_HOST', '127.0.0.1')),
        'port' => (int) env('REALTIME_PORT', env('PUSHER_PORT', 8080)),
        'scheme' => env('REALTIME_SCHEME', env('PUSHER_SCHEME', 'http')),
    ],
];
