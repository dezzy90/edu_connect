<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "🔧 Laravel MQTT Config Debug\n";
echo "=============================\n\n";

echo "📋 Environment Variables:\n";
echo "MQTT_HOST: '" . env('MQTT_HOST') . "'\n";
echo "MQTT_PORT: '" . env('MQTT_PORT') . "'\n";
echo "MQTT_USERNAME: '" . env('MQTT_USERNAME') . "'\n";
echo "MQTT_PASSWORD: '" . env('MQTT_PASSWORD') . "'\n";
echo "MQTT_CLIENT_ID_PREFIX: '" . env('MQTT_CLIENT_ID_PREFIX') . "'\n\n";

echo "📋 Config Values:\n";
echo "config('mqtt.host'): '" . config('mqtt.host') . "'\n";
echo "config('mqtt.port'): '" . config('mqtt.port') . "'\n";
echo "config('mqtt.username'): '" . config('mqtt.username') . "'\n";
echo "config('mqtt.password'): '" . config('mqtt.password') . "'\n";
echo "config('mqtt.client_id_prefix'): '" . config('mqtt.client_id_prefix') . "'\n\n";

echo "📋 Last Will Config:\n";
$lastWill = config('mqtt.last_will');
if ($lastWill) {
    echo "Topic: '" . $lastWill['topic'] . "'\n";
    echo "Message: '" . $lastWill['message'] . "'\n";
    echo "QoS: '" . $lastWill['qos'] . "'\n";
} else {
    echo "❌ Last will config not found\n";
}

echo "\n📋 Topics Config:\n";
$topics = config('mqtt.topics');
if ($topics) {
    foreach ($topics as $name => $topic) {
        echo "{$name}: '{$topic}'\n";
    }
} else {
    echo "❌ Topics config not found\n";
}

echo "\n🔧 Simulating Laravel Command Connection:\n";
echo "==========================================\n";

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

$host = config('mqtt.host');
$port = config('mqtt.port');
$username = config('mqtt.username');  
$password = config('mqtt.password');
$clientIdPrefix = config('mqtt.client_id_prefix');

echo "Using values:\n";
echo "Host: '{$host}'\n";
echo "Port: '{$port}'\n";
echo "Username: '{$username}'\n";
echo "Password: '" . ($password ? str_repeat('*', strlen($password)) : 'null') . "'\n";
echo "Client ID Prefix: '{$clientIdPrefix}'\n\n";

if (!$host || !$username || !$password) {
    echo "❌ Missing required config values!\n";
    exit(1);
}

try {
    $settings = new ConnectionSettings();
    $settings->setKeepAliveInterval(config('mqtt.keep_alive_interval', 60));
    
    // Last will (this might be causing issues)
    $lastWill = config('mqtt.last_will');
    if ($lastWill) {
        $settings->setLastWillTopic($lastWill['topic']);
        $settings->setLastWillMessage($lastWill['message']);
        $settings->setLastWillQualityOfService($lastWill['qos']);
        echo "✅ Last Will configured\n";
    }
    
    $settings->setUsername($username)->setPassword($password);
    
    $clientId = $clientIdPrefix . '-' . uniqid();
    echo "Generated Client ID: '{$clientId}'\n";
    
    $mqtt = new MqttClient($host, $port, $clientId);
    $mqtt->connect($settings, true);
    
    echo "✅ SUCCESS: Laravel-style connection works!\n";
    $mqtt->disconnect();
    
} catch (Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
    echo "Error code: " . $e->getCode() . "\n";
}