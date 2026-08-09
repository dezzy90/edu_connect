<?php

require_once __DIR__ . '/vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

echo "🇨🇲 Testing MQTT Connection for Real Device Integration\n";
echo "====================================================\n\n";

// MQTT Configuration from .env
$host = '172.17.31.181';
$port = 1883;
$username = 'rodadmin';
$password = 'YOUR_MQTT_PASSWORD';
$clientId = 'rod-connect-test-' . uniqid();

echo "📡 Connecting to MQTT Broker:\n";
echo "   Host: {$host}\n";
echo "   Port: {$port}\n";
echo "   Username: {$username}\n";
echo "   Client ID: {$clientId}\n\n";

try {
    // Create connection settings
    $connectionSettings = (new ConnectionSettings())
        ->setUsername($username)
        ->setPassword($password)
        ->setKeepAliveInterval(60)
        ->setLastWillTopic('rod-connect/status')
        ->setLastWillMessage('offline')
        ->setLastWillQualityOfService(1);

    // Create MQTT client
    $client = new MqttClient($host, $port, $clientId);
    
    echo "🔗 Attempting connection...\n";
    $client->connect($connectionSettings, true);
    
    echo "✅ Successfully connected to MQTT broker!\n\n";
    
    // Test publishing a message
    echo "📤 Testing message publishing...\n";
    $testTopic = 'rod-connect/test/connection';
    $testMessage = json_encode([
        'test' => true,
        'timestamp' => date('c'),
        'message' => 'Connection test from Laravel'
    ]);
    
    $client->publish($testTopic, $testMessage, 0);
    echo "✅ Test message published to: {$testTopic}\n";
    
    // Test subscribing (brief test)
    echo "📥 Testing subscription...\n";
    $client->subscribe('rod-connect/test/+', function ($topic, $message) {
        echo "✅ Received message on {$topic}: {$message}\n";
    }, 0);
    
    // Listen for a short time
    $client->loop(true, true, 2); // 2 second timeout
    
    $client->disconnect();
    echo "\n🎉 MQTT broker connection test completed successfully!\n";
    echo "\n🔧 Your system is ready for real device integration.\n";
    
} catch (Exception $e) {
    echo "❌ Connection failed: " . $e->getMessage() . "\n";
    echo "\nTroubleshooting tips:\n";
    echo "1. Verify Mosquitto is running on {$host}:{$port}\n";
    echo "2. Check firewall settings\n";
    echo "3. Verify credentials: {$username}/{$password}\n";
    echo "4. Test with: mosquitto_pub -h {$host} -p {$port} -u {$username} -P {$password} -t test -m 'hello'\n";
}
