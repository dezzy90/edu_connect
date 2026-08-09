<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Student;
use Illuminate\Support\Facades\Log;

class OptimizeStudentImagesForDevices extends Command
{
    protected $signature = 'device:optimize-images {--student_id=} {--school_id=} {--dry-run}';
    protected $description = 'Optimize student images for biometric device compatibility';

    public function handle()
    {
        $studentId = $this->option('student_id');
        $schoolId = $this->option('school_id');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn("DRY RUN MODE - No changes will be made");
        }

        $query = Student::whereNotNull('photo_base64');

        if ($studentId) {
            $query->where('id', $studentId);
        } elseif ($schoolId) {
            $query->where('school_id', $schoolId);
        }

        $students = $query->get();

        if ($students->isEmpty()) {
            $this->info("No students with images found");
            return 0;
        }

        $this->info("Found {$students->count()} students with images");

        $optimized = 0;
        $errors = 0;

        foreach ($students as $student) {
            $this->info("Processing: {$student->full_name} (ID: {$student->id})");
            
            try {
                $originalSize = strlen($student->photo_base64);
                $optimizedImage = $this->optimizeImageForDevice($student->photo_base64);
                
                if ($optimizedImage) {
                    $newSize = strlen($optimizedImage);
                    $savings = $originalSize - $newSize;
                    $savingsPercent = round(($savings / $originalSize) * 100, 1);
                    
                    $this->info("  Original: {$originalSize} bytes");
                    $this->info("  Optimized: {$newSize} bytes");
                    $this->info("  Savings: {$savings} bytes ({$savingsPercent}%)");
                    
                    if (!$dryRun && $newSize < $originalSize) {
                        $student->photo_base64 = $optimizedImage;
                        $student->save();
                        $this->info("  ✅ Image updated");
                        $optimized++;
                    } elseif (!$dryRun) {
                        $this->info("  ⚠️ No optimization needed");
                    } else {
                        $this->info("  ✅ Would optimize (dry run)");
                        $optimized++;
                    }
                } else {
                    $this->error("  ❌ Failed to optimize image");
                    $errors++;
                }
                
            } catch (\Exception $e) {
                $this->error("  ❌ Error: " . $e->getMessage());
                $errors++;
            }
        }

        $this->info("\nSummary:");
        $this->info("Processed: {$students->count()} students");
        $this->info("Optimized: {$optimized}");
        $this->info("Errors: {$errors}");

        return 0;
    }

    private function optimizeImageForDevice(string $imageData): ?string
    {
        try {
            // Extract base64 data
            if (strpos($imageData, 'data:image/') === 0) {
                $base64Data = explode(',', $imageData, 2)[1];
            } else {
                $base64Data = $imageData;
            }

            // Decode the image
            $imageContent = base64_decode($base64Data);
            if ($imageContent === false) {
                return null;
            }

            // Create image from string
            $image = imagecreatefromstring($imageContent);
            if ($image === false) {
                return null;
            }

            // Get original dimensions
            $originalWidth = imagesx($image);
            $originalHeight = imagesy($image);

            // Device requirements: max 1080x1920 pixels, ~200KB file size
            $maxWidth = 1080;
            $maxHeight = 1920;
            $targetFileSizeKB = 200; // 200KB target
            
            // Calculate new dimensions maintaining aspect ratio within device limits
            $newWidth = $originalWidth;
            $newHeight = $originalHeight;
            
            // Scale down if larger than device limits
            if ($originalWidth > $maxWidth || $originalHeight > $maxHeight) {
                $widthRatio = $maxWidth / $originalWidth;
                $heightRatio = $maxHeight / $originalHeight;
                $ratio = min($widthRatio, $heightRatio);
                
                $newWidth = (int) ($originalWidth * $ratio);
                $newHeight = (int) ($originalHeight * $ratio);
            }

            // Create new image
            $newImage = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

            // Start with high quality and reduce until we meet size requirements
            $quality = 90;
            $optimizedContent = null;
            $finalBase64 = null;
            
            do {
                ob_start();
                imagejpeg($newImage, null, $quality);
                $optimizedContent = ob_get_clean();
                
                $base64Encoded = base64_encode($optimizedContent);
                $finalBase64 = 'data:image/jpeg;base64,' . $base64Encoded;
                
                // Calculate approximate file size (base64 is ~33% larger than binary)
                $fileSizeKB = strlen($optimizedContent) / 1024;
                
                if ($fileSizeKB <= $targetFileSizeKB || $quality <= 30) {
                    break; // Either we met size requirement or quality is too low
                }
                
                $quality -= 10; // Reduce quality and try again
                
            } while ($quality > 30);

            // Clean up
            imagedestroy($image);
            imagedestroy($newImage);

            // Log optimization results
            $finalSizeKB = strlen($optimizedContent) / 1024;
            Log::info("Image optimized for device", [
                'original_size' => strlen($imageContent) . ' bytes',
                'final_size' => strlen($optimizedContent) . ' bytes',
                'final_size_kb' => round($finalSizeKB, 2) . ' KB',
                'original_dimensions' => "{$originalWidth}x{$originalHeight}",
                'final_dimensions' => "{$newWidth}x{$newHeight}",
                'final_quality' => $quality,
                'meets_requirements' => $finalSizeKB <= $targetFileSizeKB && $newWidth <= $maxWidth && $newHeight <= $maxHeight
            ]);

            return $finalBase64;

        } catch (\Exception $e) {
            Log::error("Image optimization failed", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}