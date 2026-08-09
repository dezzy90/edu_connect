<?php

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

// Bootstrap Laravel
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\StudentLog;

echo "🇨🇲 Recent Student Logs from MQTT Test:\n";
echo "=====================================\n\n";

$logs = StudentLog::with(['student.school', 'device'])
    ->latest()
    ->limit(10)
    ->get();

if ($logs->isEmpty()) {
    echo "No logs found.\n";
} else {
    foreach ($logs as $log) {
        echo "👤 {$log->student->first_name} {$log->student->last_name}\n";
        echo "   📍 Event: {$log->event_type}\n";
        echo "   ⏰ Time: {$log->event_time}\n";
        echo "   📱 Device: {$log->device->name}\n";
        echo "   🏫 School: {$log->student->school->name}\n";
        
        $confidence = isset($log->metadata['confidence']) ? $log->metadata['confidence'] : 'N/A';
        echo "   📊 Confidence: {$confidence}%\n";
        echo "\n";
    }
}