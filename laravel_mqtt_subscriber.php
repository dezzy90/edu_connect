<?php

require_once 'vendor/autoload.php';
require_once 'mqtt_config_helper.php';

// Bootstrap Laravel
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use App\Services\RealDeviceMessageProcessor;

echo "🚀 Laravel-Integrated MQTT Device Subscriber\n";
echo "=============================================\n\n";

$config = getMqttConfig();
displayMqttConfig($config);

$host = $config['host'];
$port = $config['port'];
$username = $config['username'];
$password = $config['password'];
$clientId = $config['client_id_prefix'] . '-laravel-subscriber-' . time();

echo "📡 Connecting to MQTT broker...\n";
echo "Client ID: {$clientId}\n\n";

try {
    // Create MQTT client
    $client = new MqttClient($host, $port, $clientId);
    
    $connectionSettings = (new ConnectionSettings())
        ->setUsername($username)
        ->setPassword($password)
        ->setKeepAliveInterval(60);
    
    // Connect to broker
    $client->connect($connectionSettings, false);
    echo "✅ Connected to MQTT broker successfully!\n\n";
    
    // Create the Laravel message processor
    $processor = new RealDeviceMessageProcessor($client, true);
    
    // Subscribe to device recognition messages
    $topics = [
        'mqtt/face/+/Rec',           // All device recognition messages
        'mqtt/face/2581924_ipobexa/Rec', // Your specific device
        'mqtt/face/heartbeat',       // Heartbeat messages
        'mqtt/face/basic',           // Basic messages
    ];
    
    echo "📥 Subscribing to topics:\n";
    foreach ($topics as $topic) {
        $client->subscribe($topic, function ($receivedTopic, $message) use ($client, $processor) {
            $timestamp = date('Y-m-d H:i:s');
            
            echo "\n" . str_repeat("=", 60) . "\n";
            echo "📨 Message Received: {$timestamp}\n";
            echo "📍 Topic: {$receivedTopic}\n";
            echo "📄 Message: " . substr($message, 0, 200) . "...\n";
            
            // Try to parse as JSON
            $data = json_decode($message, true);
            if ($data) {
                // Check if this is a RecPush message (biometric recognition)
                if (isset($data['operator']) && $data['operator'] === 'RecPush') {
                    echo "\n🎯 BIOMETRIC RECOGNITION DETECTED!\n";
                    
                    // Extract device ID from topic
                    if (preg_match('/mqtt\/face\/([^\/]+)\/Rec/', $receivedTopic, $matches)) {
                        $deviceId = $matches[1];
                        echo "📱 Device ID: {$deviceId}\n";
                        
                        // Process through Laravel system
                        echo "🔄 Processing through Laravel system...\n";
                        
                        try {
                            $result = $processor->processRealDeviceMessage($deviceId, $message, [
                                'topic' => $receivedTopic,
                                'timestamp' => $timestamp
                            ]);
                            
                            if ($result['success']) {
                                echo "✅ SUCCESS: {$result['message']}\n";
                                
                                if (isset($result['data'])) {
                                    echo "📊 Result Data:\n";
                                    foreach ($result['data'] as $key => $value) {
                                        echo "   - {$key}: " . (is_array($value) ? json_encode($value) : $value) . "\n";
                                    }
                                }
                            } else {
                                echo "❌ FAILED: {$result['message']}\n";
                            }
                            
                        } catch (\Exception $e) {
                            echo "💥 PROCESSING ERROR: {$e->getMessage()}\n";
                            echo "   File: {$e->getFile()}:{$e->getLine()}\n";
                        }
                        
                        // Display student info
                        if (isset($data['info'])) {
                            $info = $data['info'];
                            echo "\n👤 Student Data:\n";
                            echo "   - Personnel ID: " . ($info['personId'] ?? 'N/A') . "\n";
                            echo "   - Name: " . ($info['personName'] ?? 'N/A') . "\n";
                            echo "   - Custom ID: " . ($info['customId'] ?? 'N/A') . "\n";
                            echo "   - Verify Status: " . ($info['VerifyStatus'] ?? 'N/A') . "\n";
                            echo "   - Confidence: " . ($info['similarity1'] ?? 'N/A') . "%\n";
                            echo "   - Time: " . ($info['time'] ?? 'N/A') . "\n";
                        }
                    }
                } elseif (isset($data['operator']) && $data['operator'] === 'HeartBeat') {
                    echo "💗 HeartBeat from {$data['info']['facesluiceId']}\n";
                } elseif (isset($data['operator']) && $data['operator'] === 'Online') {
                    echo "🟢 Device Online: {$data['info']['facesname']} (IP: {$data['info']['wifiIp']})\n";
                }
            } else {
                echo "⚠️  Failed to parse JSON message\n";
            }
            
            echo str_repeat("=", 60) . "\n";
        }, 0);
        
        echo "  ✅ {$topic}\n";
    }
    
    echo "\n🔄 Laravel-integrated listening active...\n";
    echo "📊 StudentLogs will be created in database\n";
    echo "💡 Press Ctrl+C to stop\n";
    echo "📱 Now test your biometric device!\n\n";
    
    // Keep listening
    while (true) {
        $client->loop(true, true);
        usleep(100000); // 100ms delay
    }
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    
    if ($e->getCode() === 6) {
        echo "\n🔧 Troubleshooting:\n";
        echo "1. Check if MQTT broker is running on {$host}:{$port}\n";
        echo "2. Verify credentials in .env file\n";
        echo "3. Check network connectivity\n";
    }
}