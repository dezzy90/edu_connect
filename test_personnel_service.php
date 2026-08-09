<?php

require_once 'vendor/autoload.php';

// Bootstrap Laravel
$app = require_once 'bootstrap/app.php';
$app->boot();

echo "Testing PersonnelManagementService...\n\n";

try {
    // Get first student
    $student = App\Models\Student::first();
    if (!$student) {
        echo "No students found in database.\n";
        exit(1);
    }
    
    echo "Found student: {$student->first_name} {$student->last_name}\n";
    echo "Biometric ID: {$student->biometric_id}\n\n";
    
    // Get devices for the student's school
    $devices = App\Models\BiometricDevice::where('school_id', $student->school_id)->get();
    echo "Devices in school: " . $devices->count() . "\n";
    
    if ($devices->isEmpty()) {
        echo "No devices found. Creating a test device...\n";
        
        $testDevice = new App\Models\BiometricDevice();
        $testDevice->school_id = $student->school_id;
        $testDevice->name = 'Test Device';
        $testDevice->device_id = 'test_device_' . time();
        $testDevice->status = 'active';
        $testDevice->is_active = true;
        $testDevice->save();
        
        echo "Created test device: {$testDevice->device_id}\n\n";
        
        $devices = collect([$testDevice]);
    }
    
    foreach ($devices as $device) {
        echo "Device: {$device->name} ({$device->device_id}) - Status: {$device->status}\n";
    }
    echo "\n";
    
    // Test PersonnelManagementService
    echo "Testing PersonnelManagementService sync...\n";
    $service = new App\Services\PersonnelManagementService();
    
    // Sync to first device
    $device = $devices->first();
    $result = $service->syncStudentToDevice($student, $device);
    
    echo "Sync Result:\n";
    echo "  Success: " . ($result['success'] ? 'Yes' : 'No') . "\n";
    if ($result['success']) {
        echo "  Message ID: {$result['message_id']}\n";
        echo "  Topic: {$result['topic']}\n";
        echo "  Student: {$result['student']}\n";
        echo "  Device: {$result['device']}\n";
    } else {
        echo "  Error: {$result['error']}\n";
    }
    
    echo "\nPersonnelManagementService test completed!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}