<?php

require_once 'vendor/autoload.php';
require_once 'mqtt_config_helper.php';

use PhpMqtt\Client\MqttClient;

$config = getMqttConfig();
displayMqttConfig($config);
use PhpMqtt\Client\ConnectionSettings;

echo "🔧 Testing MQTT Connect Parameters\n";
echo "===================================\n\n";

$host = $config['host'];
$port = $config['port'];
$username = $config['username'];
$password = $config['password'];

echo "🔸 Test: connect() with clean_session=TRUE (like Laravel)...\n";
try {
    $clientId = 'rod-laravel-test-' . time();
    $settings = new ConnectionSettings();
    $settings->setKeepAliveInterval(60);
    $settings->setUsername($username)->setPassword($password);
    
    $mqtt = new MqttClient($host, $port, $clientId);
    $mqtt->connect($settings, true);  // clean_session = TRUE (Laravel style)
    
    echo "✅ SUCCESS: connect() with clean_session=true works!\n";
    echo "Client ID used: {$clientId}\n";
    
    $mqtt->disconnect();
} catch (Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
    echo "Error Code: " . $e->getCode() . "\n";
}

echo "\n🔸 Let's try with the EXACT Laravel client ID format...\n";
try {
    $clientId = 'rod-connect-' . uniqid();  // Exact Laravel format
    $settings = new ConnectionSettings();
    $settings->setKeepAliveInterval(60);
    $settings->setUsername($username)->setPassword($password);
    
    // Add Last Will like Laravel does
    $settings->setLastWillTopic('clients/rod-connect/status');
    $settings->setLastWillMessage('offline');
    $settings->setLastWillQualityOfService(1);
    
    echo "Connecting with Client ID: {$clientId}\n";
    
    $mqtt = new MqttClient($host, $port, $clientId);
    $mqtt->connect($settings, true);  // clean_session = TRUE
    
    echo "✅ SUCCESS: Exact Laravel-style connection works!\n";
    
    $mqtt->disconnect();
} catch (Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
    echo "Error Code: " . $e->getCode() . "\n";
    
    if ($e->getCode() === 6) {
        echo "\n🤔 This is puzzling - same credentials work in other tests...\n";
        echo "Possible causes:\n";
        echo "1. Mosquitto broker has connection limits\n";
        echo "2. Client ID conflicts (though we use unique IDs)\n";
        echo "3. Last Will topic permissions issue\n";
        echo "4. Broker temporarily rejecting connections\n";
    }
}