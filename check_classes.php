<?php

require_once 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\SchoolClass;
use App\Models\School;

echo "Checking available classes:\n";

$school = School::where('name', 'like', '%Lycée Général Leclerc Douala%')->first();
if ($school) {
    echo "School: {$school->name} (ID: {$school->id})\n\n";
    
    $classes = SchoolClass::where('school_id', $school->id)->get(['id', 'name', 'code', 'level_id']);
    
    if ($classes->count() > 0) {
        echo "Available classes:\n";
        foreach($classes as $class) {
            echo "  ID: {$class->id} - Name: {$class->name} - Code: {$class->code} - Level ID: {$class->level_id}\n";
        }
    } else {
        echo "No classes found for this school. Let me check all classes:\n";
        $allClasses = SchoolClass::take(10)->get(['id', 'name', 'code', 'school_id', 'level_id']);
        foreach($allClasses as $class) {
            echo "  ID: {$class->id} - Name: {$class->name} - Code: {$class->code} - School ID: {$class->school_id} - Level ID: {$class->level_id}\n";
        }
    }
} else {
    echo "School not found!\n";
}