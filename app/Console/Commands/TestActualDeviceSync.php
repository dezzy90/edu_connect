<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Student;
use App\Models\BiometricDevice;
use App\Services\PersonnelManagementService;
use Illuminate\Support\Facades\Log;

class TestActualDeviceSync extends Command
{
    protected $signature = 'device:test-actual-sync {student_id} {device_id}';
    protected $description = 'Test actual device sync and monitor for real responses';

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

        $this->info("🚀 TESTING ACTUAL DEVICE SYNC");
        $this->info("============================");
        $this->info("Student: {$student->full_name} (ID: {$student->id})");
        $this->info("Device: {$device->device_id}");
        $this->info("Has Image: " . (!empty($student->photo_base64) ? 'Yes' : 'No'));

        if (!empty($student->photo_base64)) {
            $imageSize = strlen($student->photo_base64);
            $decodedSize = strlen(base64_decode(explode(',', $student->photo_base64)[1], true)) / 1024;
            $this->info("Image Size: " . round($decodedSize, 2) . " KB");
        }

        $personnelService = new PersonnelManagementService();

        $this->info("\n📡 Sending sync message to device...");
        
        $result = $personnelService->syncStudentToDevice($student, $device);

        if ($result['success']) {
            $this->info("✅ Message sent successfully!");
            $this->info("📋 Message ID: " . $result['message_id']);
            $this->info("📡 Topic: " . $result['topic']);
            
            $this->info("\n⏳ Waiting for device response (30 seconds)...");
            $this->info("💡 You should see the response in the Laravel logs");
            $this->info("💡 Run 'php artisan mqtt:monitor-acks' in another terminal to see responses");
            
            // Wait and check logs
            for ($i = 1; $i <= 30; $i++) {
                $this->info("Waiting... {$i}/30 seconds");
                sleep(1);
                
                // You could implement log monitoring here
                if ($i % 5 == 0) {
                    $this->info("💡 Check storage/logs/laravel.log for device responses");
                }
            }
            
            $this->info("\n📋 NEXT STEPS:");
            $this->info("1. Check Laravel logs for device response");
            $this->info("2. Look for error codes: 200=success, 463=base64 error");
            $this->info("3. If you see error 463, the image format is still not compatible");
            
        } else {
            $this->error("❌ Failed to send message: " . ($result['error'] ?? 'Unknown error'));
        }

        return 0;
    }
}