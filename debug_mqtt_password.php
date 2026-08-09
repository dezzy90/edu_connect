<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "Password Debug\n";
echo str_repeat("=", 50) . "\n\n";

$password = config('mqtt.password');

echo "Password from config: [{$password}]\n";
echo "Length: " . strlen($password) . "\n";
echo "Bytes: ";
for($i=0; $i<strlen($password); $i++) {
    echo ord($password[$i]) . " ";
}
echo "\n\n";

echo "Expected: [YOUR_MQTT_PASSWORD]\n";
echo "Length: " . strlen('YOUR_MQTT_PASSWORD') . "\n";
echo "Bytes: ";
for($i=0; $i<strlen('YOUR_MQTT_PASSWORD'); $i++) {
    echo ord('YOUR_MQTT_PASSWORD'[$i]) . " ";
}
echo "\n\n";

if ($password === 'YOUR_MQTT_PASSWORD') {
    echo "✅ Passwords match exactly!\n";
} else {
    echo "❌ Passwords DO NOT match!\n";
    echo "Difference found\n";
}
