<?php

require_once __DIR__ . '/vendor/autoload.php';

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

echo "🔐 MQTT Authentication Debug Test\n";
echo "================================\n\n";

// Test different connection scenarios
$host = '172.17.31.181';
$port = 1883;
$username = 'rodadmin';
$password = 'YOUR_MQTT_PASSWORD';

// Test 1: With credentials
echo "Test 1: With credentials (rodadmin/YOUR_MQTT_PASSWORD)\n";
testConnection($host, $port, $username, $password);

// Test 2: Without credentials (anonymous)
echo "\nTest 2: Anonymous connection (no credentials)\n";
testConnection($host, $port, null, null);

// Test 3: With empty credentials
echo "\nTest 3: Empty credentials\n";
testConnection($host, $port, '', '');

function testConnection($host, $port, $username, $password) {
    $clientId = 'debug-test-' . uniqid();
    
    try {
        $connectionSettings = new ConnectionSettings();
        
        if ($username !== null && $username !== '') {
            $connectionSettings->setUsername($username);
            echo "   Using username: {$username}\n";
        } else {
            echo "   Using anonymous connection\n";
        }
        
        if ($password !== null && $password !== '') {
            $connectionSettings->setPassword($password);
            echo "   Using password: " . str_repeat('*', strlen($password)) . "\n";
        } else {
            echo "   Using no password\n";
        }
        
        $connectionSettings->setKeepAliveInterval(60);
        
        $client = new MqttClient($host, $port, $clientId);
        $client->connect($connectionSettings, true);
        
        echo "   ✅ Connection successful!\n";
        
        // Test publishing
        $client->publish('rod-connect/auth-test', 'Authentication test successful', 0);
        echo "   ✅ Publishing successful!\n";
        
        $client->disconnect();
        
    } catch (Exception $e) {
        echo "   ❌ Connection failed: " . $e->getMessage() . "\n";
    }
}