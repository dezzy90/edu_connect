<?php

return [
    'enabled' => (bool) env('REVIEWER_DEMO_ENABLED', false),
    'phone' => env('REVIEWER_DEMO_PHONE', ''),
    'parent_id' => (int) env('REVIEWER_DEMO_PARENT_ID', 0),
    'tenant_id' => (int) env('REVIEWER_DEMO_TENANT_ID', 0),
    'marker' => env('REVIEWER_DEMO_MARKER', ''),
    'pin_hash' => env('REVIEWER_DEMO_PIN_HASH', ''),
];
