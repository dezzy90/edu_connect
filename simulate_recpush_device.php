<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

echo "🇨🇲 Real Device Message Simulator (RecPush Format)\n";
echo "=================================================\n\n";

// MQTT Configuration
$host = '172.17.31.181';
$port = 1883;
$username = 'rodadmin';
$password = 'YOUR_MQTT_PASSWORD';
$deviceId = '2581924_ipobexa';

// Test messages in ACTUAL device format (RecPush)
$testMessages = [
    // Normal identification for personId "3" (Abraham Kotto)
    [
        "operator" => "RecPush",
        "info" => [
            "customId" => "test_" . uniqid(),
            "personId" => "3",  // Abraham Kotto from our database
            "RecordID" => "1001",
            "VerifyStatus" => "1",  // String format as per real device
            "PersonType" => "0",
            "similarity1" => "92.500000",
            "similarity2" => "0.000000",
            "Sendintime" => 1,
            "direction" => "entry",
            "otype" => "1",
            "personName" => "Abraham Kotto",
            "facesluiceId" => "2581924",
            "facesluiceName" => "Test Device Main Entrance",
            "idCard" => "",
            "telnum" => "",
            "left" => "158",
            "top" => "426",
            "right" => "672",
            "bottom" => "946",
            "time" => now()->format('Y-m-d H:i:s'),
            "PushType" => "0",
            "OpendoorWay" => "0",
            "cardNum2" => "1",
            "RFIDCard" => "0",
            "szQrCodeData" => "",
            "isNoMask" => "0",
            "pic" => "" // Photo data omitted for testing
        ]
    ],
    // Normal identification for personId "1" (Isaac Biya) - Check out
    [
        "operator" => "RecPush",
        "info" => [
            "customId" => "test_" . uniqid(),
            "personId" => "1",  // Isaac Biya from our database
            "RecordID" => "1002",
            "VerifyStatus" => "1",
            "PersonType" => "0",
            "similarity1" => "89.900002",
            "similarity2" => "0.000000",
            "Sendintime" => 1,
            "direction" => "exit",
            "otype" => "1",
            "personName" => "Isaac Biya",
            "facesluiceId" => "2581924",
            "facesluiceName" => "Test Device Main Entrance",
            "idCard" => "",
            "telnum" => "",
            "left" => "180",
            "top" => "400",
            "right" => "650",
            "bottom" => "920",
            "time" => now()->addMinutes(5)->format('Y-m-d H:i:s'),
            "PushType" => "0",
            "OpendoorWay" => "0",
            "cardNum2" => "1",
            "RFIDCard" => "0",
            "szQrCodeData" => "",
            "isNoMask" => "0",
            "pic" => ""
        ]
    ],
    // Unauthorized access attempt
    [
        "operator" => "RecPush",
        "info" => [
            "customId" => "test_" . uniqid(),
            "personId" => "9999",  // Unknown person
            "RecordID" => "1003",
            "VerifyStatus" => "24", // Unauthorized
            "PersonType" => "0",
            "similarity1" => "45.200000",
            "similarity2" => "0.000000",
            "Sendintime" => 1,
            "direction" => "entry",
            "otype" => "1",
            "personName" => "Unknown Person",
            "facesluiceId" => "2581924",
            "facesluiceName" => "Test Device Main Entrance",
            "idCard" => "",
            "telnum" => "",
            "left" => "150",
            "top" => "400",
            "right" => "680",
            "bottom" => "950",
            "time" => now()->addMinutes(10)->format('Y-m-d H:i:s'),
            "PushType" => "0",
            "OpendoorWay" => "0",
            "cardNum2" => "1",
            "RFIDCard" => "0",
            "szQrCodeData" => "",
            "isNoMask" => "0",
            "pic" => ""
        ]
    ],
    // Blacklist denial
    [
        "operator" => "RecPush",
        "info" => [
            "customId" => "test_" . uniqid(),
            "personId" => "7777",
            "RecordID" => "1004",
            "VerifyStatus" => "2", // Blacklisted
            "PersonType" => "0",
            "similarity1" => "95.800000",
            "similarity2" => "0.000000",
            "Sendintime" => 1,
            "direction" => "entry",
            "otype" => "1",
            "personName" => "Blacklisted Person",
            "facesluiceId" => "2581924",
            "facesluiceName" => "Test Device Main Entrance",
            "idCard" => "",
            "telnum" => "",
            "left" => "160",
            "top" => "420",
            "right" => "660",
            "bottom" => "940",
            "time" => now()->addMinutes(15)->format('Y-m-d H:i:s'),
            "PushType" => "0",
            "OpendoorWay" => "0",
            "cardNum2" => "1",
            "RFIDCard" => "0",
            "szQrCodeData" => "",
            "isNoMask" => "0",
            "pic" => ""
        ]
    ]
];

try {
    // Create MQTT client
    $clientId = 'real-device-simulator-' . uniqid();
    $connectionSettings = (new ConnectionSettings())
        ->setUsername($username)
        ->setPassword($password)
        ->setKeepAliveInterval(60);

    $client = new MqttClient($host, $port, $clientId);
    
    echo "🔗 Connecting to MQTT broker...\n";
    $client->connect($connectionSettings, true);
    echo "✅ Connected successfully!\n\n";

    // Subscribe to downlink topic to see replies
    $downlinkTopic = "mqtt/face/{$deviceId}";
    echo "📥 Subscribing to replies on: {$downlinkTopic}\n";
    $client->subscribe($downlinkTopic, function($topic, $message) {
        echo "📨 REPLY RECEIVED: {$message}\n\n";
    }, 1);

    // Publish test messages
    $uplinkTopic = "mqtt/face/{$deviceId}/Rec";
    
    foreach ($testMessages as $index => $message) {
        $messageJson = json_encode($message, JSON_PRETTY_PRINT);
        $info = $message['info'];
        
        echo "📤 Sending RecPush message " . ($index + 1) . ":\n";
        echo "   Person: {$info['personName']} (ID: {$info['personId']})\n";
        echo "   Status: {$info['VerifyStatus']} (Similarity: {$info['similarity1']}%)\n";
        echo "   Time: {$info['time']}\n";
        echo "   Direction: {$info['direction']}\n";
        echo "   Topic: {$uplinkTopic}\n";
        
        $client->publish($uplinkTopic, $messageJson, 1);
        echo "   ✅ Message sent!\n";
        echo "   ⏳ Waiting for Laravel acknowledgment...\n\n";
        
        // Listen for replies for a few seconds
        $client->loop(true, true, 3);
        
        echo "   " . str_repeat("-", 50) . "\n\n";
        sleep(1);
    }

    $client->disconnect();
    echo "🎉 All RecPush messages sent successfully!\n\n";
    
    echo "📋 Next Steps:\n";
    echo "1. Start Laravel MQTT subscriber: php artisan mqtt:subscribe -vvv\n";
    echo "2. Check attendance logs: php check_logs.php\n";
    echo "3. Monitor Laravel logs: tail -f storage/logs/laravel.log\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n\n";
    
    if (strpos($e->getMessage(), 'unauthorized') !== false) {
        echo "🔐 MQTT Authentication Issue:\n";
        echo "The broker is still rejecting credentials.\n";
        echo "You need to fix the MQTT broker authentication first.\n";
    }
}