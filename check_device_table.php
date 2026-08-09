<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Schema;
use App\Models\BiometricDevice;

echo "🔍 BiometricDevice Table Structure\n";
echo "=================================\n\n";

$columns = Schema::getColumnListing('biometric_devices');

echo "Available columns:\n";
foreach ($columns as $column) {
    echo "- {$column}\n";
}

echo "\nExisting devices:\n";
$devices = BiometricDevice::all();
foreach ($devices as $device) {
    echo "- {$device->device_id}: {$device->name}\n";
}