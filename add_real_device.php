<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\BiometricDevice;
use App\Models\School;

echo "🇨🇲 Adding Real Biometric Device: 2581924_ipobexa\n";
echo "===============================================\n\n";

// Get the first school for testing (you can change this)
$school = School::first();

if (!$school) {
    echo "❌ No schools found. Please seed the database first.\n";
    exit(1);
}

echo "🏫 Assigning device to: {$school->name}\n";

// Check if device already exists
$existingDevice = BiometricDevice::where('device_id', '2581924_ipobexa')->first();

if ($existingDevice) {
    echo "ℹ️  Device already exists, updating...\n";
    $device = $existingDevice;
} else {
    echo "➕ Creating new device...\n";
    $device = new BiometricDevice();
}

// Configure the device
$device->device_id = '2581924_ipobexa';
$device->name = 'Real Biometric Device - 2581924_ipobexa';
$device->location = 'Test Location - Main Entrance';
$device->school_id = $school->id;
$device->device_type = 'face_recognition';
$device->is_active = true;
$device->ip_address = '172.17.31.181'; // Same as MQTT broker for now
$device->firmware_version = '1.0.0';
$device->mac_address = '00:00:00:00:00:00'; // Update if you know the MAC
$device->last_heartbeat = now();

$device->save();

echo "✅ Device successfully " . ($existingDevice ? 'updated' : 'created') . "!\n\n";

echo "📋 Device Details:\n";
echo "   ID: {$device->device_id}\n";
echo "   Name: {$device->name}\n";
echo "   School: {$school->name}\n";
echo "   Active: " . ($device->is_active ? 'Yes' : 'No') . "\n";
echo "   Type: {$device->device_type}\n";
echo "   Location: {$device->location}\n";
echo "   IP Address: {$device->ip_address}\n\n";

echo "📡 MQTT Topics for your device:\n";
echo "   Recognition: mqtt/face/2581924_ipobexa/Rec\n";
echo "   Photo: mqtt/face/2581924_ipobexa/Snap\n";
echo "   Heartbeat: mqtt/face/heartbeat\n";
echo "   Basic: mqtt/face/basic\n";

echo "\n🔧 Next Steps:\n";
echo "1. Configure your device to connect to MQTT broker: 172.17.31.181:1883\n";
echo "2. Set device to publish recognition messages to: mqtt/face/2581924_ipobexa/Rec\n";
echo "3. Test the connection with: php artisan mqtt:test --device=2581924_ipobexa\n";
echo "4. Start MQTT subscriber: php artisan mqtt:subscribe\n";