<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Student;
use App\Models\BiometricDevice;
use App\Services\PersonnelManagementService;
use Illuminate\Support\Facades\Log;

class TestSyncWithoutImage extends Command
{
    protected $signature = 'test:sync-no-image {student_id}';
    protected $description = 'Test syncing a student without image to confirm basic sync works';

    public function handle()
    {
        $studentId = $this->argument('student_id');
        $student = Student::find($studentId);
        
        if (!$student) {
            $this->error("Student not found");
            return 1;
        }
        
        $this->info("Testing sync WITHOUT image for: {$student->full_name}");
        
        // Temporarily remove the image
        $originalImage = $student->photo_base64;
        $student->photo_base64 = null;
        
        // Get device
        $device = BiometricDevice::where('school_id', $student->school_id)
            ->where('is_active', true)
            ->first();
            
        if (!$device) {
            $this->error("No active device found");
            return 1;
        }
        
        $this->info("Syncing to device: {$device->name}");
        
        try {
            $service = new PersonnelManagementService();
            $result = $service->syncStudentToDevice($student, $device);
            
            if ($result['success']) {
                $this->info("✅ Sync sent successfully (no image)");
                $this->line("Message ID: {$result['message_id']}");
                $this->line("Topic: {$result['topic']}");
            } else {
                $this->error("❌ Sync failed: {$result['error']}");
            }
            
        } catch (\Exception $e) {
            $this->error("Exception: {$e->getMessage()}");
        }
        
        // Restore original image
        $student->photo_base64 = $originalImage;
        
        return 0;
    }
}