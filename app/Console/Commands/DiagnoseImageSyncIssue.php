<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Student;
use App\Models\BiometricDevice;
use App\Services\PersonnelManagementService;
use Illuminate\Support\Facades\Log;

class DiagnoseImageSyncIssue extends Command
{
    protected $signature = 'device:diagnose-image {student_id} {device_id}';
    protected $description = 'Comprehensive diagnosis of image sync issues with biometric devices';

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

        $this->info("🔍 DIAGNOSING IMAGE SYNC ISSUE");
        $this->info("================================");
        $this->info("Student: {$student->full_name} (ID: {$student->id})");
        $this->info("Device: {$device->device_id} ({$device->device_name})");
        $this->info("");

        if (empty($student->photo_base64)) {
            $this->warn("❌ Student has no image - nothing to diagnose");
            return 0;
        }

        // Image analysis
        $this->info("📷 IMAGE ANALYSIS");
        $this->info("-----------------");
        
        $imageData = $student->photo_base64;
        $imageSize = strlen($imageData);
        $this->info("Raw Size: {$imageSize} characters");
        
        // Check format
        if (strpos($imageData, 'data:image/') === 0) {
            $this->info("✅ Has data URL prefix");
            $parts = explode(',', $imageData, 2);
            if (count($parts) === 2) {
                $header = $parts[0];
                $base64Data = $parts[1];
                $this->info("Header: {$header}");
                $this->info("Base64 Size: " . strlen($base64Data) . " characters");
                
                // Decode and analyze
                $decodedImage = base64_decode($base64Data, true);
                if ($decodedImage !== false) {
                    $decodedSize = strlen($decodedImage);
                    $this->info("✅ Base64 decodes successfully");
                    $this->info("Decoded Size: {$decodedSize} bytes");
                    
                    // Try to get image info
                    $tempFile = tempnam(sys_get_temp_dir(), 'img_diagnosis');
                    file_put_contents($tempFile, $decodedImage);
                    
                    $imageInfo = getimagesize($tempFile);
                    if ($imageInfo) {
                        $this->info("✅ Valid image file");
                        $this->info("Dimensions: {$imageInfo[0]} x {$imageInfo[1]}");
                        $this->info("Type: " . $this->getImageTypeName($imageInfo[2]));
                        $this->info("MIME: {$imageInfo['mime']}");
                    } else {
                        $this->error("❌ Invalid image file");
                    }
                    
                    unlink($tempFile);
                } else {
                    $this->error("❌ Base64 decode failed");
                }
            } else {
                $this->error("❌ Invalid data URL format");
            }
        } else {
            $this->warn("⚠️ No data URL prefix - raw base64");
        }

        $this->info("");
        
        // Device compatibility checks
        $this->info("🤖 DEVICE COMPATIBILITY CHECKS");
        $this->info("-------------------------------");
        
        // Device requirements: max 200KB file size, max 1080x1920 pixels
        $maxFileSizeKB = 200;
        $maxWidth = 1080;
        $maxHeight = 1920;
        
        // Check file size (approximate from base64)
        if (isset($decodedSize)) {
            $fileSizeKB = $decodedSize / 1024;
            $this->info("File Size: " . round($fileSizeKB, 2) . " KB");
            
            if ($fileSizeKB > $maxFileSizeKB) {
                $this->error("❌ File size ({$fileSizeKB} KB) exceeds device limit ({$maxFileSizeKB} KB)");
            } else {
                $this->info("✅ File size is within device limits");
            }
        }
        
        // Check dimensions if we got them
        if (isset($imageInfo) && $imageInfo) {
            $width = $imageInfo[0];
            $height = $imageInfo[1];
            
            if ($width > $maxWidth || $height > $maxHeight) {
                $this->error("❌ Image dimensions ({$width}x{$height}) exceed device limits ({$maxWidth}x{$maxHeight})");
            } else {
                $this->info("✅ Image dimensions are within device limits");
            }
        }
        
        // Overall compatibility assessment
        $hasIssues = false;
        if (isset($fileSizeKB) && $fileSizeKB > $maxFileSizeKB) $hasIssues = true;
        if (isset($imageInfo) && $imageInfo && ($imageInfo[0] > $maxWidth || $imageInfo[1] > $maxHeight)) $hasIssues = true;
        
        if ($hasIssues) {
            $this->warn("⚠️ Image does NOT meet device requirements - optimization needed");
        } else {
            $this->info("✅ Image meets all device requirements");
        }

        $this->info("");
        
        // Test sync scenarios
        $this->info("🔄 SYNC TESTS");
        $this->info("-------------");
        
        $personnelService = new PersonnelManagementService();
        
        // Test 1: Sync without image
        $this->info("Test 1: Sync WITHOUT image...");
        $resultNoImage = $personnelService->syncStudentWithoutImage($student, $device);
        if ($resultNoImage['success']) {
            $this->info("✅ Sync without image: SUCCESS");
        } else {
            $this->error("❌ Sync without image: FAILED - " . ($resultNoImage['error'] ?? 'Unknown'));
        }
        
        $this->info("");
        
        // Test 2: Sync with original image
        $this->info("Test 2: Sync WITH original image...");
        $resultWithImage = $personnelService->syncStudentToDevice($student, $device);
        if ($resultWithImage['success']) {
            $this->info("✅ Sync with image: SUCCESS (message sent)");
            $this->info("📡 Message ID: " . ($resultWithImage['message_id'] ?? 'None'));
            $this->warn("⚠️ Note: Success means message was sent, not that device accepted it");
        } else {
            $this->error("❌ Sync with image: FAILED - " . ($resultWithImage['error'] ?? 'Unknown'));
        }
        
        $this->info("");
        
        // Recommendations
        $this->info("💡 RECOMMENDATIONS");
        $this->info("------------------");
        
        $this->info("📋 Device Requirements:");
        $this->info("   • Max file size: 200KB");
        $this->info("   • Max dimensions: 1080x1920 pixels");
        $this->info("");
        
        if ($hasIssues) {
            $this->info("🔧 REQUIRED: Optimize image: php artisan device:optimize-images --student_id={$studentId}");
        }
        
        if (isset($imageInfo) && $imageInfo) {
            if ($imageInfo[0] > $maxWidth || $imageInfo[1] > $maxHeight) {
                $this->info("🔧 Image will be resized to fit {$maxWidth}x{$maxHeight} limits");
            }
        }
        
        if (isset($fileSizeKB) && $fileSizeKB > $maxFileSizeKB) {
            $this->info("🔧 Image will be compressed to meet 200KB limit");
        }
        
        $this->info("🔧 Monitor device responses: php artisan device:monitor-acks");
        $this->info("🔧 Test sync with retry: php artisan device:test-sync-retry {$studentId} {$deviceId}");
        
        $this->info("");
        $this->info("🔍 Check Laravel logs for device responses after sync attempts");
        $this->info("📋 Device error codes: 200=success, 463=base64 decode error");

        return 0;
    }

    private function getImageTypeName(int $type): string
    {
        $types = [
            IMAGETYPE_JPEG => 'JPEG',
            IMAGETYPE_PNG => 'PNG',
            IMAGETYPE_GIF => 'GIF',
            IMAGETYPE_WEBP => 'WebP',
        ];

        return $types[$type] ?? "Unknown ({$type})";
    }
}