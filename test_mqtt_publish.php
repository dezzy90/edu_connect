<?php

require_once 'vendor/autoload.php';

echo "Testing MQTT Publishing from Laravel...\n";

try {
    $host = 'test.mosquitto.org';
    $port = 1883;
    
    echo "MQTT Config:\n";
    echo "  Host: $host\n";
    echo "  Port: $port\n";
    echo "  No authentication\n\n";
    
    // Create MQTT client
    $client = new PhpMqtt\Client\MqttClient($host, $port, 'laravel-test-' . time());
    
    // Connection settings
    $connectionSettings = new PhpMqtt\Client\ConnectionSettings();
    $connectionSettings->setKeepAliveInterval(60);
    
    // Connect
    echo "Connecting to MQTT broker...\n";
    $client->connect($connectionSettings, false);
    echo "Connected successfully!\n";
    
    // Publish test message
    $testMessage = [
        'operator' => 'TestConnection',
        'info' => [
            'source' => 'Laravel PersonnelManagementService',
            'timestamp' => date('c'),
            'message' => 'Test publish from Laravel application'
        ]
    ];
    
    $topic = 'mqtt/face/test_device_id';
    $client->publish($topic, json_encode($testMessage), 1);
    echo "Published test message to: $topic\n";
    
    // Disconnect
    $client->disconnect();
    echo "Disconnected.\n";
    
    echo "\nPublishing test completed successfully!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}