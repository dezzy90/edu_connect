<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Schema;
use App\Models\StudentLog;

echo "📋 StudentLog Table Structure\n";
echo "============================\n\n";

$columns = Schema::getColumnListing('student_logs');

echo "Available columns:\n";
foreach ($columns as $column) {
    echo "- {$column}\n";
}

echo "\nExisting records:\n";
$logs = StudentLog::latest()->limit(3)->get();
foreach ($logs as $log) {
    echo "- Log ID {$log->id}: Student {$log->student_id}, Device {$log->device_id}\n";
}