<?php

require_once __DIR__ . '/vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

echo "🔍 Testing Exact API Topic Structure\n";
echo "===================================\n\n";

$deviceId = '2581924_ipobexa';
$uplinkTopic = "mqtt/face/{$deviceId}/Rec";   // Device → Server
$downlinkTopic = "mqtt/face/{$deviceId}";      // Server → Device

echo "📡 API Topic Structure:\n";
echo "   Uplink (Device → Server): {$uplinkTopic}\n";
echo "   Downlink (Server → Device): {$downlinkTopic}\n\n";

// Test with your broker
$host = '172.17.31.181';
$port = 1883;
$username = 'rodadmin';
$password = 'YOUR_MQTT_PASSWORD';

try {
    $clientId = 'api-test-' . uniqid();
    $settings = new ConnectionSettings();
    $settings->setUsername($username)->setPassword($password);
    
    $client = new MqttClient($host, $port, $clientId);
    
    echo "🔗 Testing connection to {$host}:{$port}...\n";
    $client->connect($settings, true);
    echo "✅ Connected successfully!\n\n";
    
    // Test subscribing to downlink topic (for receiving acknowledgments)
    echo "📥 Testing subscription to downlink topic: {$downlinkTopic}\n";
    $client->subscribe($downlinkTopic, function($topic, $message) {
        echo "📨 Received on {$topic}: {$message}\n";
    }, 1);
    echo "✅ Subscribed successfully!\n\n";
    
    // Test publishing to uplink topic (simulating device)
    echo "📤 Testing publish to uplink topic: {$uplinkTopic}\n";
    $testMessage = json_encode([
        'PersonnelId' => '1',
        'VerifyStatus' => 1,
        'Timestamp' => now()->toISOString(),
        'MessageId' => 'test_' . uniqid(),
        'DeviceId' => $deviceId
    ]);
    
    $client->publish($uplinkTopic, $testMessage, 1);
    echo "✅ Published test message successfully!\n";
    echo "   Message: {$testMessage}\n\n";
    
    // Listen for a few seconds to see if there are any responses
    echo "👂 Listening for responses (5 seconds)...\n";
    $client->loop(true, true, 5);
    
    $client->disconnect();
    echo "✅ Test completed successfully!\n\n";
    
    echo "🎉 Your MQTT broker and topic structure are working!\n";
    echo "Ready to start Laravel MQTT subscriber.\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n\n";
    
    if (strpos($e->getMessage(), 'unauthorized') !== false) {
        echo "🔐 Authentication Issue Detected:\n";
        echo "The credentials 'rodadmin/YOUR_MQTT_PASSWORD' are being rejected.\n";
        echo "Please check:\n";
        echo "1. Are these the correct credentials?\n";
        echo "2. Is the user created on the MQTT broker?\n";
        echo "3. Does the broker allow these credentials?\n\n";
        
        echo "💡 Try these commands on the MQTT broker server:\n";
        echo "   sudo mosquitto_passwd /etc/mosquitto/passwd rodadmin\n";
        echo "   sudo systemctl restart mosquitto\n";
    }
}