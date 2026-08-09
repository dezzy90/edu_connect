<?php

require_once 'vendor/autoload.php';
require_once 'mqtt_config_helper.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

echo "Testing MQTT Connection from Command Context\n";
echo str_repeat("=", 50) . "\n\n";

$config = getMqttConfig();
displayMqttConfig($config);

$host = $config['host'];
$port = $config['port'];
$username = $config['username'];
$password = $config['password'];
$clientId = $config['client_id_prefix'] . '-test-command-' . time();

echo "Attempting connection...\n";
echo "Client ID: {$clientId}\n\n";

try {
    // Create client
    $client = new MqttClient($host, $port, $clientId);
    
    // Create connection settings
    $settings = (new ConnectionSettings())
        ->setUsername($username)
        ->setPassword($password)
        ->setKeepAliveInterval(60);
    
    // Connect with clean session = false
    echo "Calling connect()...\n";
    $client->connect($settings, false);
    
    echo "✅ SUCCESS: Connected to MQTT broker!\n";
    echo "Now trying to subscribe to a topic...\n";
    
    $client->subscribe('mqtt/face/heartbeat', function($topic, $message) {
        echo "📨 Received message on {$topic}: {$message}\n";
    }, 1);
    
    echo "✅ Subscribed successfully!\n";
    echo "Listening for 5 seconds...\n";
    
    $start = time();
    while (time() - $start < 5) {
        $client->loop(false, true);
        usleep(100000);
    }
    
    $client->disconnect();
    echo "\n✅ Test completed successfully!\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
