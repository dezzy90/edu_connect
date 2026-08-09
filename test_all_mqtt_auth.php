<?php

require_once __DIR__ . '/vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

echo "🔧 Testing MQTT Connection Options\n";
echo "=================================\n\n";

$host = '172.17.31.181';
$port = 1883;
$configs = [
    'Anonymous' => [null, null],
    'Current Credentials' => ['rodadmin', 'YOUR_MQTT_PASSWORD'],
    'Empty Credentials' => ['', ''],
    'Admin/Admin' => ['admin', 'admin'],
    'Test/Test' => ['test', 'test']
];

foreach ($configs as $name => $config) {
    [$username, $password] = $config;
    echo "🔍 Testing: {$name}\n";
    
    try {
        $clientId = 'test-' . uniqid();
        $settings = new ConnectionSettings();
        
        if ($username && $password) {
            $settings->setUsername($username)->setPassword($password);
            echo "   Username: {$username}, Password: " . str_repeat('*', strlen($password)) . "\n";
        } else {
            echo "   Anonymous connection\n";
        }
        
        $client = new MqttClient($host, $port, $clientId);
        $client->connect($settings, true);
        
        echo "   ✅ SUCCESS!\n";
        
        // Test subscribe to your device topic
        $testTopic = 'mqtt/face/2581924_ipobexa/Rec';
        $client->subscribe($testTopic, function($topic, $message) {
            // Just for testing
        }, 0);
        
        echo "   ✅ Subscribe successful!\n";
        $client->disconnect();
        echo "   🎉 This configuration works!\n\n";
        
        // If we find a working config, save it to .env
        if ($username && $password) {
            echo "💡 Use these credentials in your .env:\n";
            echo "   MQTT_USERNAME={$username}\n";
            echo "   MQTT_PASSWORD={$password}\n";
        } else {
            echo "💡 Use anonymous connection (remove credentials from .env)\n";
        }
        break;
        
    } catch (Exception $e) {
        echo "   ❌ Failed: " . $e->getMessage() . "\n\n";
    }
}

echo "🔧 Try starting Laravel MQTT subscriber with working credentials:\n";
echo "   php artisan mqtt:subscribe -vvv\n";