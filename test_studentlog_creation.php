<?php

require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Student;
use App\Models\BiometricDevice;
use App\Models\StudentLog;

try {
    echo "🧪 Testing StudentLog Creation...\n\n";

    // Find NDZI DESMOND (the student from our MQTT test)
    $student = Student::where('first_name', 'NDZI')
                     ->where('last_name', 'DESMOND')
                     ->first();

    if (!$student) {
        echo "❌ Student NDZI DESMOND not found!\n";
        exit(1);
    }

    echo "✅ Found student: {$student->first_name} {$student->last_name} (ID: {$student->id})\n";
    echo "   - Student Number: {$student->student_number}\n";
    echo "   - Biometric ID: {$student->biometric_id}\n";
    echo "   - School ID: {$student->school_id}\n\n";

    // Find the device
    $device = BiometricDevice::where('device_id', '2581924_ipobexa')->first();
    
    if (!$device) {
        echo "❌ Device 2581924_ipobexa not found!\n";
        exit(1);
    }

    echo "✅ Found device: {$device->name} (ID: {$device->id})\n";
    echo "   - Device ID: {$device->device_id}\n";
    echo "   - School ID: {$device->school_id}\n\n";

    // Check if student already has a check-in today
    $existingCheckIn = StudentLog::where('student_id', $student->id)
                                 ->where('event_type', 'check_in')
                                 ->whereDate('created_at', today())
                                 ->first();

    if ($existingCheckIn) {
        echo "⚠️  Student already has a check-in today: {$existingCheckIn->created_at}\n";
        echo "   - Log ID: {$existingCheckIn->id}\n";
        echo "   - Event Type: {$existingCheckIn->event_type}\n";
        echo "   - Device: {$existingCheckIn->device->name}\n\n";
    } else {
        echo "✅ No existing check-in found for today\n\n";
    }

    // Try to create a new check-in log
    echo "🔄 Attempting to create check-in log...\n";
    
    try {
        $log = StudentLog::createCheckIn($student->id, $device->id, [
            'confidence_score' => 95.50,
            'biometric_data' => ['photo' => 'test_photo_data'],
            'notes' => json_encode([
                'test' => true,
                'person_id' => 4,
                'person_name' => 'NDZI DESMOND',
                'device_location' => 'Test Location',
                'operator' => 'TestScript'
            ])
        ]);

        echo "✅ SUCCESS! StudentLog created:\n";
        echo "   - Log ID: {$log->id}\n";
        echo "   - Student: {$log->student->first_name} {$log->student->last_name}\n";
        echo "   - Device: {$log->device->name}\n";
        echo "   - Event Type: {$log->event_type}\n";
        echo "   - Confidence: {$log->confidence_score}%\n";
        echo "   - Created At: {$log->created_at}\n";
        echo "   - School (via student): {$log->student->school->name}\n\n";

    } catch (\Exception $e) {
        if (str_contains($e->getMessage(), 'already checked in')) {
            echo "⚠️  Student already checked in today (validation working correctly)\n";
            echo "   - Error: {$e->getMessage()}\n\n";
        } else {
            echo "❌ FAILED to create StudentLog!\n";
            echo "   - Error: {$e->getMessage()}\n";
            echo "   - File: {$e->getFile()}:{$e->getLine()}\n\n";
            throw $e;
        }
    }

    // Show recent logs for this student
    echo "📋 Recent logs for {$student->first_name} {$student->last_name}:\n";
    $recentLogs = StudentLog::where('student_id', $student->id)
                           ->orderBy('created_at', 'desc')
                           ->take(5)
                           ->get();

    if ($recentLogs->isEmpty()) {
        echo "   - No logs found\n";
    } else {
        foreach ($recentLogs as $recentLog) {
            echo "   - {$recentLog->created_at}: {$recentLog->event_type} (Device: {$recentLog->device->name})\n";
        }
    }

} catch (\Exception $e) {
    echo "💥 CRITICAL ERROR:\n";
    echo "   - Message: {$e->getMessage()}\n";
    echo "   - File: {$e->getFile()}:{$e->getLine()}\n";
    echo "   - Trace:\n";
    foreach (explode("\n", $e->getTraceAsString()) as $line) {
        echo "     {$line}\n";
    }
    exit(1);
}