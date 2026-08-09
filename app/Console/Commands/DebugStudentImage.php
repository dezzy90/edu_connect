<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Student;
use App\Models\BiometricDevice;
use App\Services\PersonnelManagementService;
use Illuminate\Support\Facades\Log;

class DebugStudentImage extends Command
{
    protected $signature = 'debug:student-image {student_id}';
    protected $description = 'Debug a specific student image to see what is being sent';

    public function handle()
    {
        $studentId = $this->argument('student_id');
        $student = Student::find($studentId);
        
        if (!$student) {
            $this->error("Student not found");
            return 1;
        }
        
        $this->info("Debugging image for: {$student->full_name}");
        $this->info("Student ID: {$student->id}");
        $this->info("Biometric ID: {$student->biometric_id}");
        
        if (!$student->photo_base64) {
            $this->info("❌ No image data found");
            return 0;
        }
        
        $this->info("✅ Image data exists");
        $this->line("Original length: " . strlen($student->photo_base64));
        
        // Show first 100 characters
        $preview = substr($student->photo_base64, 0, 100);
        $this->line("Preview: " . $preview . "...");
        
        // Check if it starts with data:
        if (strpos($student->photo_base64, 'data:') === 0) {
            $commaPos = strpos($student->photo_base64, ',');
            if ($commaPos !== false) {
                $header = substr($student->photo_base64, 0, $commaPos);
                $base64Part = substr($student->photo_base64, $commaPos + 1);
                $this->line("Header: {$header}");
                $this->line("Base64 part length: " . strlen($base64Part));
                
                // Check first 50 chars of base64
                $base64Preview = substr($base64Part, 0, 50);
                $this->line("Base64 preview: {$base64Preview}...");
                
                // Try to decode
                $decoded = base64_decode($base64Part, true);
                if ($decoded === false) {
                    $this->error("❌ Base64 decode failed");
                } else {
                    $this->info("✅ Base64 decode successful");
                    $this->line("Decoded size: " . strlen($decoded));
                    
                    // Check if it's an image
                    $imageInfo = @getimagesizefromstring($decoded);
                    if ($imageInfo === false) {
                        $this->error("❌ Not a valid image");
                    } else {
                        $this->info("✅ Valid image detected");
                        $this->line("Type: " . $imageInfo['mime']);
                        $this->line("Dimensions: " . $imageInfo[0] . 'x' . $imageInfo[1]);
                    }
                }
            } else {
                $this->error("❌ No comma found in data URL");
            }
        } else {
            $this->warn("⚠️  No data: prefix found");
            // Try to decode directly
            $decoded = base64_decode($student->photo_base64, true);
            if ($decoded === false) {
                $this->error("❌ Direct base64 decode failed");
            } else {
                $this->info("✅ Direct base64 decode successful");
            }
        }
        
        return 0;
    }
}