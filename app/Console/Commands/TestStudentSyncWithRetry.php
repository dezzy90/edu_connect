<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Student;
use App\Models\BiometricDevice;
use App\Services\PersonnelManagementService;
use Illuminate\Support\Facades\Log;

class TestStudentSyncWithRetry extends Command
{
    protected $signature = 'device:test-sync-retry {student_id} {device_id}';
    protected $description = 'Test student sync with automatic retry on image failures';

    public function handle()
    {
        $studentId = $this->argument('student_id');
        $deviceId = $this->argument('device_id');

        // Find the student
        $student = Student::find($studentId);
        if (!$student) {
            $this->error("Student with ID {$studentId} not found");
            return 1;
        }

        // Find the device
        $device = BiometricDevice::where('device_id', $deviceId)->first();
        if (!$device) {
            $this->error("Device with ID {$deviceId} not found");
            return 1;
        }

        $this->info("Testing sync with retry for:");
        $this->info("Student: {$student->full_name} (ID: {$student->id})");
        $this->info("Device: {$device->device_id} ({$device->device_name})");
        $this->info("Has Image: " . (!empty($student->photo_base64) ? 'Yes' : 'No'));

        if (!empty($student->photo_base64)) {
            $imageSize = strlen($student->photo_base64);
            $this->info("Image Size: {$imageSize} characters");
            
            // Show first 100 characters of image data
            $preview = substr($student->photo_base64, 0, 100) . '...';
            $this->info("Image Preview: {$preview}");
        }

        $personnelService = new PersonnelManagementService();

        $this->info("Attempting sync with retry mechanism...");
        
        $result = $personnelService->syncStudentWithRetry($student, $device);

        $this->info("Sync Result:");
        $this->info(json_encode($result, JSON_PRETTY_PRINT));

        if ($result['success']) {
            $this->info("✅ Sync successful!");
            if (isset($result['synced_without_image']) && $result['synced_without_image']) {
                $this->warn("⚠️ Note: Student was synced WITHOUT image due to device rejection");
            }
        } else {
            $this->error("❌ Sync failed: " . ($result['error'] ?? 'Unknown error'));
        }

        // Also test sync without image for comparison
        if (!empty($student->photo_base64)) {
            $this->info("\n--- Testing sync WITHOUT image for comparison ---");
            
            $resultNoImage = $personnelService->syncStudentWithoutImage($student, $device);
            
            $this->info("Sync without image result:");
            $this->info(json_encode($resultNoImage, JSON_PRETTY_PRINT));
            
            if ($resultNoImage['success']) {
                $this->info("✅ Sync without image successful!");
            } else {
                $this->error("❌ Sync without image failed: " . ($resultNoImage['error'] ?? 'Unknown error'));
            }
        }

        return 0;
    }
}