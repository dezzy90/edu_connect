<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\RealDeviceMessageProcessor;

echo "🧪 Testing RecPush Message Processing (Local)\n";
echo "===========================================\n\n";

// Create processor without MQTT client for local testing
$processor = new RealDeviceMessageProcessor(null, false);

// Test with actual device message format
$testMessage = json_encode([
    "operator" => "RecPush",
    "info" => [
        "customId" => "test_local_" . uniqid(),
        "personId" => "3",  // Abraham Kotto
        "RecordID" => "2001",
        "VerifyStatus" => "1",
        "PersonType" => "0",
        "similarity1" => "92.500000",
        "similarity2" => "0.000000",
        "Sendintime" => 1,
        "direction" => "entry",
        "otype" => "1",
        "personName" => "Abraham Kotto",
        "facesluiceId" => "2581924",
        "facesluiceName" => "Test Device Main Entrance",
        "time" => now()->format('Y-m-d H:i:s'),
        "pic" => ""
    ]
]);

echo "📝 Test Message (RecPush Format):\n";
echo $testMessage . "\n\n";

echo "🔄 Processing with RealDeviceMessageProcessor...\n";

$result = $processor->processRealDeviceMessage('2581924_ipobexa', $testMessage, ['type' => 'recognition']);

echo "📊 Processing Result:\n";
echo "Success: " . ($result['success'] ? 'YES' : 'NO') . "\n";
echo "Message: " . $result['message'] . "\n";

if (isset($result['data'])) {
    echo "Data:\n";
    foreach ($result['data'] as $key => $value) {
        echo "  {$key}: {$value}\n";
    }
}

echo "\n✅ Local message processing test completed!\n";

// Test invalid message format
echo "\n🧪 Testing Invalid Message Format...\n";
$invalidMessage = '{"invalid": "format"}';
$invalidResult = $processor->processRealDeviceMessage('2581924_ipobexa', $invalidMessage, []);

echo "Result: " . $invalidResult['message'] . "\n";

echo "\n🎯 Ready for real MQTT testing once broker authentication is fixed!\n";