<?php

require_once 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Student;

echo "Checking existing student gender formats:\n";
$students = Student::take(5)->get(['first_name', 'last_name', 'gender', 'biometric_id']);

foreach($students as $s) {
    echo "{$s->first_name} {$s->last_name} - Gender: [{$s->gender}] - Bio ID: [{$s->biometric_id}]\n";
}