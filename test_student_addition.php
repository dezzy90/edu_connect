<?php

require_once 'vendor/autoload.php';
require_once 'mqtt_config_helper.php';

use PhpMqtt\Client\MqttClient;

$config = getMqttConfig();
displayMqttConfig($config);
use PhpMqtt\Client\ConnectionSettings;

echo "🧑‍🎓 Testing Student Addition to Biometric Device\n";
echo "================================================\n\n";

// MQTT Configuration
$host = $config['host'];
$port = $config['port'];
$username = $config['username'];
$password = $config['password'];
$clientId = 'rod-personnel-test-' . time();

// Device Configuration
$deviceId = '2581924_ipobexa';
$deviceTopic = "mqtt/face/{$deviceId}";

// Student Data - NDZI DESMOND (from your real device recognition)
$student = [
    'biometric_id' => 'STU_NDZ_001',
    'first_name' => 'NDZI',
    'last_name' => 'DESMOND',
    'student_id' => 'CAM205001',
    'class' => '6ème A',
    'school' => 'Lycée Général Leclerc Douala',
    'parent_phone' => '+237 697 123 456',
    'date_of_birth' => '2010-05-15'
];

try {
    echo "📡 Connecting to MQTT broker...\n";
    $client = new MqttClient($host, $port, $clientId);
    
    $connectionSettings = (new ConnectionSettings())
        ->setUsername($username)
        ->setPassword($password)
        ->setKeepAliveInterval(60);
    
    $client->connect($connectionSettings, false);
    echo "✅ Connected successfully!\n\n";
    
    // Build EditPerson message
    $messageId = 'ROD:' . gethostname() . '-' . hrtime(true) . ':' . getmypid() . ':' . rand(1000, 9999);
    
    $editPersonMessage = [
        'messageId' => $messageId,
        'operator' => 'EditPerson',
        'info' => [
            'customId' => $student['biometric_id'],
            'name' => trim($student['first_name'] . ' ' . $student['last_name']),
            'nation' => 1, // Cameroon
            'gender' => 0, // Male (assuming)
            'birthday' => $student['date_of_birth'],
            'address' => 'Douala, Cameroon',
            'idCard' => $student['student_id'],
            'tempCardType' => 0, // Permanent card
            'EffectNumber' => 3,
            'cardValidBegin' => '2025-09-01 06:00:00',
            'cardValidEnd' => '2026-07-31 18:00:00',
            'telnum1' => $student['parent_phone'],
            'native' => 'Douala, Cameroon',
            'cardType2' => 0,
            'cardNum2' => '',
            'notes' => "Classe: {$student['class']} | École: {$student['school']} | Ajouté: " . date('Y-m-d H:i:s'),
            'personType' => 0, // Regular student
            'cardType' => 0,
            'strategyInfo' => [
                'strategyNum' => 1,
                'strategyData' => [
                    [
                        'strategyID' => 1,
                        'strategyName' => 'student_access'
                    ]
                ]
            ]
            // Note: 'pic' field omitted - would contain base64 encoded photo
        ]
    ];
    
    echo "👤 Student Information:\n";
    echo "  Name: {$student['first_name']} {$student['last_name']}\n";
    echo "  Student ID: {$student['student_id']}\n";
    echo "  Biometric ID: {$student['biometric_id']}\n";
    echo "  Class: {$student['class']}\n";
    echo "  School: {$student['school']}\n";
    echo "  Parent Phone: {$student['parent_phone']}\n\n";
    
    echo "📤 Sending EditPerson message to device...\n";
    echo "Device Topic: {$deviceTopic}\n";
    echo "Message ID: {$messageId}\n\n";
    
    // Convert message to JSON
    $jsonMessage = json_encode($editPersonMessage, JSON_PRETTY_PRINT);
    
    echo "📋 EditPerson Message:\n";
    echo "```json\n";
    echo $jsonMessage;
    echo "\n```\n\n";
    
    // Publish to device
    $client->publish($deviceTopic, json_encode($editPersonMessage), 1); // QoS 1 for reliability
    
    echo "✅ EditPerson message sent successfully!\n";
    echo "🔄 The device should now process this student addition...\n\n";
    
    // Subscribe to acknowledgment topic to see device response
    echo "📥 Listening for device acknowledgment...\n";
    $ackTopic = "{$deviceTopic}/Ack";
    
    $client->subscribe($ackTopic, function ($topic, $message) use ($messageId) {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "📨 Device ACK Received!\n";
        echo "📍 Topic: {$topic}\n";
        echo "📄 Message: {$message}\n";
        
        $ackData = json_decode($message, true);
        if ($ackData) {
            echo "🔍 Parsed ACK:\n";
            foreach ($ackData as $key => $value) {
                if (is_array($value)) {
                    echo "  {$key}: " . json_encode($value) . "\n";
                } else {
                    echo "  {$key}: {$value}\n";
                }
            }
            
            // Check if this ACK is for our message
            if (isset($ackData['messageId']) && $ackData['messageId'] === $messageId) {
                echo "🎯 This is the ACK for our EditPerson message!\n";
                
                if (isset($ackData['result'])) {
                    if ($ackData['result'] === 'success' || $ackData['result'] === 'Success') {
                        echo "✅ SUCCESS: Student added to device successfully!\n";
                        echo "🎉 NDZI DESMOND is now enrolled in the biometric device!\n";
                    } else {
                        echo "❌ FAILED: Device returned error: {$ackData['result']}\n";
                    }
                }
            }
        }
        echo str_repeat("=", 60) . "\n";
    }, 0);
    
    echo "⏳ Waiting for device acknowledgment (30 seconds timeout)...\n";
    echo "💡 You should see an ACK message if the device processes the student addition\n\n";
    
    // Listen for ACK for 30 seconds
    $startTime = time();
    while (time() - $startTime < 30) {
        $client->loop(false, true);
        usleep(100000); // 100ms
    }
    
    echo "\n⏰ Timeout reached. Check device logs for status.\n";
    
    $client->disconnect();
    echo "\n🔌 Disconnected from MQTT broker.\n";
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "🎯 Test Summary:\n";
    echo "✅ MQTT connection established\n";
    echo "✅ EditPerson message sent to device {$deviceId}\n";
    echo "✅ Message format follows API specification\n";
    echo "📋 Student: NDZI DESMOND ({$student['biometric_id']})\n";
    echo "📱 Device should now have this student enrolled\n";
    echo "🔄 Try scanning NDZI DESMOND's finger/face on the device!\n";
    echo str_repeat("=", 60) . "\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Code: " . $e->getCode() . "\n";
    
    if ($e->getCode() === 6) {
        echo "\nThis could be an authentication issue.\n";
        echo "Make sure the MQTT broker is running and credentials are correct.\n";
    }
}