<?php

require_once 'vendor/autoload.php';
require_once 'mqtt_config_helper.php';

use PhpMqtt\Client\MqttClient;

$config = getMqttConfig();
displayMqttConfig($config);
use PhpMqtt\Client\ConnectionSettings;

echo "🚀 Direct MQTT Device Subscriber for Rod-Connect\n";
echo "================================================\n\n";

$host = $config['host'];
$port = $config['port'];
$username = $config['username'];
$password = $config['password'];
$clientId = 'rod-device-subscriber-' . time();

echo "📡 Connecting to MQTT broker...\n";
echo "Host: {$host}:{$port}\n";
echo "Client ID: {$clientId}\n\n";

try {
    // Create MQTT client with working connection method
    $client = new MqttClient($host, $port, $clientId);
    
    $connectionSettings = (new ConnectionSettings())
        ->setUsername($username)
        ->setPassword($password)
        ->setKeepAliveInterval(60);
    
    // Connect using method we know works
    $client->connect($connectionSettings, false);
    echo "✅ Connected to MQTT broker successfully!\n\n";
    
    // Subscribe to device topics
    $topics = [
        'mqtt/face/+/Rec',           // All device recognition messages
        'mqtt/face/2581924_ipobexa/Rec', // Your specific device
        'mqtt/face/heartbeat',       // Heartbeat messages
        'mqtt/face/basic',           // Basic messages
        'test/debug'                 // Test topic
    ];
    
    echo "📥 Subscribing to topics:\n";
    foreach ($topics as $topic) {
        $client->subscribe($topic, function ($receivedTopic, $message) use ($client) {
            $timestamp = date('Y-m-d H:i:s');
            
            echo "\n" . str_repeat("=", 60) . "\n";
            echo "📨 Message Received: {$timestamp}\n";
            echo "📍 Topic: {$receivedTopic}\n";
            echo "📄 Message: {$message}\n";
            
            // Try to parse as JSON
            $data = json_decode($message, true);
            if ($data) {
                echo "🔍 Parsed JSON:\n";
                foreach ($data as $key => $value) {
                    if (is_array($value)) {
                        echo "  {$key}: " . json_encode($value) . "\n";
                    } else {
                        echo "  {$key}: {$value}\n";
                    }
                }
                
                // Check if this is a RecPush message
                if (isset($data['operator']) && $data['operator'] === 'RecPush') {
                    echo "\n🎯 BIOMETRIC DEVICE MESSAGE DETECTED!\n";
                    
                    // Extract device ID from topic
                    if (preg_match('/mqtt\/face\/([^\/]+)\/Rec/', $receivedTopic, $matches)) {
                        $deviceId = $matches[1];
                        echo "📱 Device ID: {$deviceId}\n";
                        
                        // Send acknowledgment back to device
                        $ackTopic = "mqtt/face/{$deviceId}";
                        $ackMessage = json_encode([
                            'operator' => 'RecPushAck',
                            'info' => [
                                'customId' => $data['info']['customId'] ?? '',
                                'result' => 'success',
                                'timestamp' => date('Y-m-d H:i:s')
                            ]
                        ]);
                        
                        $client->publish($ackTopic, $ackMessage, 0);
                        echo "📤 Sent ACK to: {$ackTopic}\n";
                        echo "💌 ACK Message: {$ackMessage}\n";
                        
                        // Process the student data
                        if (isset($data['info'])) {
                            $info = $data['info'];
                            echo "\n👤 Student Recognition Data:\n";
                            echo "  Personnel ID: " . ($info['personId'] ?? 'N/A') . "\n";
                            echo "  Name: " . ($info['personName'] ?? 'N/A') . "\n";
                            echo "  Verify Status: " . ($info['VerifyStatus'] ?? 'N/A') . "\n";
                            echo "  Confidence: " . ($info['similarity1'] ?? 'N/A') . "%\n";
                            echo "  Time: " . ($info['time'] ?? 'N/A') . "\n";
                            echo "  Device Name: " . ($info['facesluiceName'] ?? 'N/A') . "\n";
                        }
                    }
                }
            }
            
            echo str_repeat("=", 60) . "\n";
        }, 0);
        
        echo "  ✅ {$topic}\n";
    }
    
    echo "\n🔄 Listening for device messages...\n";
    echo "💡 Press Ctrl+C to stop\n";
    echo "📱 Now test your biometric device!\n\n";
    
    // Keep listening
    while (true) {
        $client->loop(true, true);
        usleep(100000); // 100ms delay to prevent high CPU usage
    }
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    
    if ($e->getCode() === 6) {
        echo "\n🔧 Troubleshooting:\n";
        echo "1. Check if Mosquitto broker is running on {$host}:{$port}\n";
        echo "2. Verify username '{$username}' exists in password file\n";
        echo "3. Confirm password is correct\n";
        echo "4. Check Mosquitto logs for more details\n";
    }
}