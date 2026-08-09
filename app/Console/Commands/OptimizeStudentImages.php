<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Student;
use Illuminate\Support\Facades\Storage;

class OptimizeStudentImages extends Command
{
    protected $signature = 'students:optimize-images {--dry-run}';
    protected $description = 'Optimize student images for biometric device compatibility';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info("DRY RUN MODE - No changes will be made");
        }
        
        $students = Student::whereNotNull('photo_base64')->get();
        $this->info("Found {$students->count()} students with images");
        
        $optimized = 0;
        $errors = 0;
        
        foreach ($students as $student) {
            try {
                $result = $this->optimizeStudentImage($student, $dryRun);
                
                if ($result['status'] === 'optimized') {
                    $optimized++;
                    $this->info("✅ Student {$student->id}: Optimized {$result['original_size']} → {$result['new_size']} bytes");
                } elseif ($result['status'] === 'skipped') {
                    $this->line("⏭️  Student {$student->id}: {$result['reason']}");
                } else {
                    $errors++;
                    $this->error("❌ Student {$student->id}: {$result['error']}");
                }
                
            } catch (\Exception $e) {
                $errors++;
                $this->error("❌ Student {$student->id}: Exception - {$e->getMessage()}");
            }
        }
        
        $this->line("");
        $this->info("Summary:");
        $this->line("  Images optimized: {$optimized}");
        $this->line("  Errors: {$errors}");
        
        return 0;
    }
    
    private function optimizeStudentImage(Student $student, bool $dryRun): array
    {
        // Extract base64 data
        $base64Data = $student->photo_base64;
        
        if (strpos($base64Data, 'data:') === 0) {
            $commaPos = strpos($base64Data, ',');
            if ($commaPos === false) {
                return ['status' => 'error', 'error' => 'Invalid data URL'];
            }
            $base64Only = substr($base64Data, $commaPos + 1);
        } else {
            $base64Only = $base64Data;
        }
        
        // Decode image
        $imageData = base64_decode($base64Only, true);
        if ($imageData === false) {
            return ['status' => 'error', 'error' => 'Base64 decode failed'];
        }
        
        // Create image resource
        $image = @imagecreatefromstring($imageData);
        if ($image === false) {
            return ['status' => 'error', 'error' => 'Invalid image data'];
        }
        
        $originalWidth = imagesx($image);
        $originalHeight = imagesy($image);
        $originalSize = strlen($imageData);
        
        // Skip if already small and reasonable
        if ($originalWidth <= 300 && $originalHeight <= 400 && $originalSize <= 50000) {
            imagedestroy($image);
            return [
                'status' => 'skipped', 
                'reason' => 'Image already optimized'
            ];
        }
        
        // Calculate new dimensions (max 240x320 for biometric devices)
        $maxWidth = 240;
        $maxHeight = 320;
        
        $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
        $newWidth = (int)($originalWidth * $ratio);
        $newHeight = (int)($originalHeight * $ratio);
        
        // Create optimized image
        $optimizedImage = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled(
            $optimizedImage, $image,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $originalWidth, $originalHeight
        );
        
        // Convert to JPEG with quality 85
        ob_start();
        imagejpeg($optimizedImage, null, 85);
        $optimizedData = ob_get_clean();
        
        // Clean up
        imagedestroy($image);
        imagedestroy($optimizedImage);
        
        if (!$optimizedData) {
            return ['status' => 'error', 'error' => 'JPEG conversion failed'];
        }
        
        // Create new base64
        $newBase64 = base64_encode($optimizedData);
        $fullBase64 = 'data:image/jpeg;base64,' . $newBase64;
        
        if (!$dryRun) {
            $student->update(['photo_base64' => $fullBase64]);
        }
        
        return [
            'status' => 'optimized',
            'original_size' => $originalSize,
            'new_size' => strlen($optimizedData),
            'dimensions' => "{$newWidth}x{$newHeight}"
        ];
    }
}