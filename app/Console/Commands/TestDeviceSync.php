<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Student;
use App\Models\BiometricDevice;
use App\Services\PersonnelManagementService;
use Illuminate\Support\Facades\Log;

class TestDeviceSync extends Command
{
    protected $signature = 'test:device-sync {student_id} {--device_id=}';
    protected $description = 'Test device synchronization with detailed debugging';

    public function handle()
    {
        $studentId = $this->argument('student_id');
        $deviceId = $this->option('device_id');

        $student = Student::find($studentId);
        if (!$student) {
            $this->error("Student with ID {$studentId} not found");
            return 1;
        }

        $this->info("Testing sync for student: {$student->full_name}");
        $this->info("Student biometric ID: {$student->biometric_id}");
        $this->info("School ID: {$student->school_id}");

        // Get devices
        $query = BiometricDevice::where('school_id', $student->school_id)
            ->where('is_active', true);
        
        if ($deviceId) {
            $query->where('device_id', $deviceId);
        }
        
        $devices = $query->get();

        if ($devices->isEmpty()) {
            $this->error("No active devices found for this student's school");
            return 1;
        }

        $this->info("Found {$devices->count()} active device(s):");
        foreach ($devices as $device) {
            $this->line("  - {$device->name} (ID: {$device->device_id})");
        }

        // Test the message format
        $service = new PersonnelManagementService();
        
        foreach ($devices as $device) {
            $this->info("\n--- Testing Device: {$device->name} ---");
            
            try {
                $result = $service->syncStudentToDevice($student, $device);
                
                if ($result['success']) {
                    $this->info("✓ Sync sent successfully");
                    $this->line("  Topic: {$result['topic']}");
                    $this->line("  Message ID: {$result['message_id']}");
                } else {
                    $this->error("✗ Sync failed: {$result['error']}");
                }
                
            } catch (\Exception $e) {
                $this->error("✗ Exception: {$e->getMessage()}");
                Log::error("Test sync failed", [
                    'student_id' => $student->id,
                    'device_id' => $device->device_id,
                    'error' => $e->getMessage(),
                ]);
            }
            
            // Small delay between devices
            sleep(1);
        }

        $this->info("\nCheck the Laravel logs for detailed message content");
        return 0;
    }
}