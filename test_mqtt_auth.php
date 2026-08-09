<?php

require_once 'vendor/autoload.php';
require_once 'mqtt_config_helper.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

echo "Testing MQTT Authentication\n";
echo str_repeat("=", 50) . "\n\n";

$config = getMqttConfig();
displayMqttConfig($config);

$host = $config['host'];
$port = $config['port'];
$username = $config['username'];
$password = $config['password'];

try {
    echo "Attempting connection...\n";
    
    $client = new MqttClient($host, $port, 'test-auth-' . time());
    
    $settings = (new ConnectionSettings())
        ->setUsername($username)
        ->setPassword($password)
        ->setKeepAliveInterval(60);
    
    $client->connect($settings, false);
    
    echo "✅ SUCCESS: Connected with authentication!\n";
    
    $client->disconnect();
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "\nTrying without authentication...\n";
    
    try {
        $client = new MqttClient($host, $port, 'test-noauth-' . time());
        $settings = (new ConnectionSettings())->setKeepAliveInterval(60);
        $client->connect($settings, false);
        
        echo "✅ SUCCESS: Connected WITHOUT authentication!\n";
        echo "⚠️  Your broker does not require authentication\n";
        echo "💡 Update .env: Remove MQTT_USERNAME and MQTT_PASSWORD\n";
        
        $client->disconnect();
        
    } catch (Exception $e2) {
        echo "❌ ERROR: " . $e2->getMessage() . "\n";
        echo "\n🔍 Possible issues:\n";
        echo "  1. Broker not running\n";
        echo "  2. Wrong host/port\n";
        echo "  3. Firewall blocking connection\n";
        echo "  4. Wrong credentials\n";
    }
}
