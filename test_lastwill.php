<?php

require_once 'vendor/autoload.php';
require_once 'mqtt_config_helper.php';

use PhpMqtt\Client\MqttClient;

$config = getMqttConfig();
displayMqttConfig($config);
use PhpMqtt\Client\ConnectionSettings;

echo "🔧 Testing Laravel MQTT Connection Without Last Will\n";
echo "====================================================\n\n";

$host = $config['host'];
$port = $config['port'];
$username = $config['username'];
$password = $config['password'];
$clientId = 'rod-connect-' . uniqid();

echo "🔸 Test 1: Connection WITHOUT Last Will...\n";
try {
    $settings1 = new ConnectionSettings();
    $settings1->setKeepAliveInterval(60);
    $settings1->setUsername($username)->setPassword($password);
    // No Last Will configured
    
    $mqtt1 = new MqttClient($host, $port, $clientId . '-nolw');
    $mqtt1->connect($settings1, true);
    echo "✅ SUCCESS: Connection without Last Will works!\n";
    $mqtt1->disconnect();
} catch (Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
}

echo "\n🔸 Test 2: Connection WITH Last Will...\n";
try {
    $settings2 = new ConnectionSettings();
    $settings2->setKeepAliveInterval(60);
    $settings2->setUsername($username)->setPassword($password);
    
    // Add Last Will (this might be the problem)
    $settings2->setLastWillTopic('clients/rod-connect/status');
    $settings2->setLastWillMessage('offline');
    $settings2->setLastWillQualityOfService(1);
    
    $mqtt2 = new MqttClient($host, $port, $clientId . '-withlw');
    $mqtt2->connect($settings2, true);
    echo "✅ SUCCESS: Connection with Last Will works!\n";
    $mqtt2->disconnect();
} catch (Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
}

echo "\n🔸 Test 3: Connection with clean session FALSE...\n";
try {
    $settings3 = new ConnectionSettings();
    $settings3->setKeepAliveInterval(60);
    $settings3->setUsername($username)->setPassword($password);
    
    $mqtt3 = new MqttClient($host, $port, $clientId . '-noclean');
    $mqtt3->connect($settings3, false);  // clean session = false
    echo "✅ SUCCESS: Connection with clean session=false works!\n";
    $mqtt3->disconnect();
} catch (Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
}

echo "\n🔧 Summary: Different connection approaches tested.\n";