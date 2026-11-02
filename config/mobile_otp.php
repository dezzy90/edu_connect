<?php

return [
    'enabled' => (bool) env('MOBILE_OTP_ENABLED', false),
    'cache_store' => env('MOBILE_OTP_CACHE_STORE', env('CACHE_STORE', 'database')),
    'hmac_key' => env('MOBILE_OTP_HMAC_KEY', env('APP_KEY')),
    'validity_format' => env('MOBILE_OTP_VALIDITY_FORMAT', '{minutes}'),
    'requests_per_phone_hour' => 5,
    'requests_per_ip_window' => 20,
    'verifications_per_ip_window' => 60,
    'ip_window_seconds' => 600,
];
