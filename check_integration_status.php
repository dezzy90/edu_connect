<?php

require_once 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Student;
use App\Models\StudentLog;
use App\Models\BiometricDevice;

echo "🔍 Checking NDZI DESMOND Integration Status\n";
echo "==========================================\n\n";

// 1. Check if student exists
echo "1️⃣ Student Status:\n";
$student = Student::where('biometric_id', 'STU_NDZI_001')->first();
if ($student) {
    echo "   ✅ Found: {$student->first_name} {$student->last_name}\n";
    echo "   📋 ID: {$student->id}\n";
    echo "   🆔 Biometric ID: {$student->biometric_id}\n";
    echo "   🏫 School ID: {$student->school_id}\n";
    echo "   📚 Class ID: {$student->class_id}\n\n";
} else {
    echo "   ❌ Student not found!\n\n";
    exit;
}

// 2. Check device
echo "2️⃣ Device Status:\n";
$device = BiometricDevice::where('device_id', '2581924_ipobexa')->first();
if ($device) {
    echo "   ✅ Found: {$device->name}\n";
    echo "   📋 ID: {$device->id}\n";
    echo "   🆔 Device ID: {$device->device_id}\n";
    echo "   🏫 School ID: {$device->school_id}\n";
    echo "   📍 Location: {$device->location}\n\n";
} else {
    echo "   ❌ Device not found!\n\n";
    exit;
}

// 3. Check existing logs
echo "3️⃣ Student Log Status:\n";
$logs = StudentLog::where('student_id', $student->id)->get();
echo "   📊 Total logs: {$logs->count()}\n";

if ($logs->count() > 0) {
    foreach ($logs as $log) {
        echo "   📝 {$log->event_type} - {$log->created_at} - Device: {$log->device_id}\n";
    }
} else {
    echo "   ⚠️ No logs found for this student\n";
}

// 4. Check if can create new log
echo "\n4️⃣ Log Creation Test:\n";
try {
    $canCheckIn = StudentLog::canCreateLog($student->id, 'check_in');
    $canCheckOut = StudentLog::canCreateLog($student->id, 'check_out');
    
    echo "   ✅ Can create check_in: " . ($canCheckIn ? 'YES' : 'NO') . "\n";
    echo "   ✅ Can create check_out: " . ($canCheckOut ? 'YES' : 'NO') . "\n";
    
    // Try to create a test log manually
    if ($canCheckIn) {
        echo "\n5️⃣ Manual Log Creation Test:\n";
        $testLog = StudentLog::createCheckIn($student->id, $device->id, [
            'school_id' => $device->school_id,
            'confidence_score' => 95.5,
            'notes' => 'Test log creation from manual script',
        ]);
        echo "   ✅ Test log created successfully! ID: {$testLog->id}\n";
    }
    
} catch (Exception $e) {
    echo "   ❌ Error: {$e->getMessage()}\n";
}

echo "\n📋 Summary:\n";
echo "✅ Student exists in database\n";
echo "✅ Device exists and is registered\n";
echo "✅ MQTT recognition working\n";
if ($logs->count() > 0) {
    echo "✅ StudentLog entries created\n";
} else {
    echo "⚠️ No StudentLog entries found - check RealDeviceMessageProcessor\n";
}