<?php

require_once 'vendor/autoload.php';
require_once 'mqtt_config_helper.php';

use PhpMqtt\Client\MqttClient;

$config = getMqttConfig();
displayMqttConfig($config);
use PhpMqtt\Client\ConnectionSettings;

echo "🔍 MQTT Clean Session Test\n";
echo "==========================\n\n";

$host = $config['host'];
$port = $config['port'];
$username = $config['username'];
$password = $config['password'];

echo "🔸 Test A: clean_session = FALSE\n";
try {
    $clientId = 'clean-session-test-false-' . time();
    $settings = new ConnectionSettings();
    $settings->setUsername($username)->setPassword($password);
    
    $mqtt = new MqttClient($host, $port, $clientId);
    $mqtt->connect($settings, false);  // clean_session = FALSE
    
    echo "✅ SUCCESS: clean_session=false works!\n";
    $mqtt->disconnect();
} catch (Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
}

echo "\n🔸 Test B: clean_session = TRUE\n";
try {
    $clientId = 'clean-session-test-true-' . time();
    $settings = new ConnectionSettings();
    $settings->setUsername($username)->setPassword($password);
    
    $mqtt = new MqttClient($host, $port, $clientId);
    $mqtt->connect($settings, true);   // clean_session = TRUE
    
    echo "✅ SUCCESS: clean_session=true works!\n";
    $mqtt->disconnect();
} catch (Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n";
}

echo "\n💡 If clean_session=false works but clean_session=true fails,\n";
echo "   it suggests the Mosquitto broker might have persistent session\n";
echo "   requirements or ACL restrictions for clean sessions.\n";