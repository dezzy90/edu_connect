<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Student;
use App\Models\School;

echo "🇨🇲 Student Biometric IDs for Device Testing\n";
echo "==========================================\n\n";

$schools = School::with(['students' => function($query) {
    $query->whereNotNull('biometric_id')->limit(3);
}])->get();

foreach ($schools as $school) {
    if ($school->students->count() > 0) {
        echo "🏫 {$school->name} (School ID: {$school->id})\n";
        echo "   Device IDs: DEVICE_{$school->id}_01, DEVICE_{$school->id}_02\n";
        echo "   Students:\n";
        
        foreach ($school->students as $student) {
            echo "   👤 {$student->first_name} {$student->last_name}\n";
            echo "      Biometric ID: {$student->biometric_id}\n";
            echo "      Student ID: {$student->id}\n";
        }
        echo "\n";
    }
}