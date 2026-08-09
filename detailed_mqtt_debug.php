<?php

require_once 'vendor/autoload.php';
require_once 'mqtt_config_helper.php';

use PhpMqtt\Client\MqttClient;

$config = getMqttConfig();
displayMqttConfig($config);
use PhpMqtt\Client\ConnectionSettings;

echo "🔐 Detailed MQTT Connection Debug\n";
echo "==================================\n\n";

$host = $config['host'];
$port = $config['port'];
$username = $config['username'];
$password = $config['password'];
$clientId = 'rod-debug-' . time();

echo "📡 Testing Connection:\n";
echo "  Host: {$host}\n";
echo "  Port: {$port}\n";
echo "  Username: '{$username}'\n";
echo "  Password: '{$password}' (length: " . strlen($password) . ")\n";
echo "  Client ID: {$clientId}\n\n";

try {
    // First test: Basic connection without authentication
    echo "🔸 Test 1: Connection without authentication...\n";
    $client1 = new MqttClient($host, $port, $clientId . '-noauth');
    $connectionSettings1 = new ConnectionSettings();
    
    try {
        $client1->connect($connectionSettings1, false);
        echo "✅ Connection without auth: SUCCESS\n";
        $client1->disconnect();
    } catch (Exception $e) {
        echo "❌ Connection without auth: FAILED - " . $e->getMessage() . "\n";
    }
    
    echo "\n🔸 Test 2: Connection with authentication...\n";
    
    // Second test: Connection with authentication
    $client2 = new MqttClient($host, $port, $clientId . '-auth');
    $connectionSettings2 = (new ConnectionSettings())
        ->setUsername($username)
        ->setPassword($password)
        ->setKeepAliveInterval(60);
    
    echo "  Username bytes: " . bin2hex($username) . "\n";
    echo "  Password bytes: " . bin2hex($password) . "\n";
    
    $client2->connect($connectionSettings2, false);
    echo "✅ Connection with auth: SUCCESS\n";
    
    // Test subscribing to a simple topic
    echo "\n🔸 Test 3: Subscribe to test topic...\n";
    $client2->subscribe('test/debug', function($topic, $message) {
        echo "📨 Received: {$message} on {$topic}\n";
    }, 0);
    echo "✅ Subscription: SUCCESS\n";
    
    // Test publishing
    echo "\n🔸 Test 4: Publish test message...\n";
    $testMsg = json_encode(['test' => true, 'time' => date('Y-m-d H:i:s')]);
    $client2->publish('test/debug', $testMsg, 0);
    echo "✅ Publishing: SUCCESS\n";
    
    // Listen briefly
    echo "\n🔸 Test 5: Listen for messages (3 seconds)...\n";
    $startTime = time();
    while (time() - $startTime < 3) {
        $client2->loop(false, true);
        usleep(100000); // 100ms
    }
    
    $client2->disconnect();
    echo "✅ All tests passed! MQTT credentials are working.\n";
    
} catch (Exception $e) {
    echo "\n❌ DETAILED ERROR:\n";
    echo "Exception: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    
    if ($e->getCode() === 6) {
        echo "\n🔍 Error Code 6 Analysis:\n";
        echo "This typically means 'Connection Refused - Bad username or password'\n";
        echo "Possible issues:\n";
        echo "  1. Username 'rodadmin' does not exist in Mosquitto password file\n";
        echo "  2. Password 'YOUR_MQTT_PASSWORD' is incorrect for user 'rodadmin'\n";
        echo "  3. Mosquitto password file is not being loaded\n";
        echo "  4. Mosquitto config has authentication disabled but our client sends auth\n";
        echo "\n💡 Next steps:\n";
        echo "  - Check Mosquitto logs\n";
        echo "  - Verify password file with: mosquitto_passwd -U /path/to/pwfile\n";
        echo "  - Test with mosquitto_pub: mosquitto_pub -h {$host} -u {$username} -P {$password} -t test -m 'hello'\n";
    }
}

echo "\n" . str_repeat("=", 60) . "\n";