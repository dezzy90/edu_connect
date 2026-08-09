<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Student;
use Illuminate\Support\Facades\Log;

class FixStudentImages extends Command
{
    protected $signature = 'students:fix-images {--dry-run : Show what would be fixed without making changes}';
    protected $description = 'Fix corrupted base64 images in student records';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info("DRY RUN MODE - No changes will be made");
        }
        
        $this->info("Checking student images for corruption...");
        
        $students = Student::whereNotNull('photo_base64')->get();
        $this->info("Found {$students->count()} students with images");
        
        $corrupted = 0;
        $fixed = 0;
        $removed = 0;
        
        foreach ($students as $student) {
            $result = $this->validateStudentImage($student);
            
            if ($result['status'] === 'corrupted') {
                $corrupted++;
                $this->error("❌ Student {$student->id} ({$student->full_name}): {$result['error']}");
                
                if (!$dryRun) {
                    // Remove corrupted image
                    $student->update(['photo_base64' => null]);
                    $removed++;
                    $this->line("   → Removed corrupted image");
                }
            } elseif ($result['status'] === 'fixable') {
                $corrupted++;
                $this->warn("⚠️  Student {$student->id} ({$student->full_name}): {$result['error']}");
                
                if (!$dryRun && isset($result['fixed_data'])) {
                    $student->update(['photo_base64' => $result['fixed_data']]);
                    $fixed++;
                    $this->line("   → Fixed image encoding");
                }
            } else {
                $this->info("✅ Student {$student->id} ({$student->full_name}): Image OK");
            }
        }
        
        $this->line("");
        $this->info("Summary:");
        $this->line("  Total students checked: {$students->count()}");
        $this->line("  Corrupted images found: {$corrupted}");
        
        if (!$dryRun) {
            $this->line("  Images fixed: {$fixed}");
            $this->line("  Images removed: {$removed}");
        } else {
            $this->line("  Would be fixed: (run without --dry-run to apply)");
        }
        
        return 0;
    }
    
    private function validateStudentImage(Student $student): array
    {
        try {
            $base64Data = $student->photo_base64;
            
            // Remove any whitespace and newlines
            $cleaned = trim(preg_replace('/\s+/', '', $base64Data));
            
            // Check if it starts with data: prefix
            if (strpos($cleaned, 'data:') === 0) {
                $commaPos = strpos($cleaned, ',');
                if ($commaPos === false) {
                    return ['status' => 'corrupted', 'error' => 'Invalid data URL - no comma'];
                }
                $mimeType = substr($cleaned, 0, $commaPos);
                $base64Only = substr($cleaned, $commaPos + 1);
            } else {
                $base64Only = $cleaned;
                $mimeType = 'data:image/jpeg;base64';
            }
            
            // Remove whitespace from base64
            $base64Only = preg_replace('/\s+/', '', $base64Only);
            
            // Check for valid base64 characters
            if (!preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $base64Only)) {
                return ['status' => 'corrupted', 'error' => 'Invalid base64 characters'];
            }
            
            // Check length and padding
            $len = strlen($base64Only);
            if ($len === 0) {
                return ['status' => 'corrupted', 'error' => 'Empty base64 data'];
            }
            
            $fixedBase64 = $base64Only;
            if ($len % 4 !== 0) {
                $padLength = 4 - ($len % 4);
                $fixedBase64 .= str_repeat('=', $padLength);
            }
            
            // Try to decode
            $decoded = base64_decode($fixedBase64, true);
            if ($decoded === false || strlen($decoded) === 0) {
                return ['status' => 'corrupted', 'error' => 'Base64 decode failed'];
            }
            
            // Check if it's an image
            $imageInfo = @getimagesizefromstring($decoded);
            if ($imageInfo === false) {
                return ['status' => 'corrupted', 'error' => 'Not a valid image'];
            }
            
            // Check size
            if (strlen($decoded) > 1048576) {
                return ['status' => 'corrupted', 'error' => 'Image too large (>1M)'];
            }
            
            // If we had to fix padding or format, return fixable
            $finalData = $mimeType . ',' . $fixedBase64;
            if ($finalData !== $base64Data) {
                return [
                    'status' => 'fixable',
                    'error' => 'Fixed padding/format issues',
                    'fixed_data' => $finalData
                ];
            }
            
            return ['status' => 'ok'];
            
        } catch (\Exception $e) {
            return ['status' => 'corrupted', 'error' => 'Exception: ' . $e->getMessage()];
        }
    }
}