<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Student;
use App\Services\PersonnelManagementService;

class ShowDeviceMessage extends Command
{
    protected $signature = 'show:device-message {student_id}';
    protected $description = 'Show the exact message that would be sent to devices for a student';

    public function handle()
    {
        $studentId = $this->argument('student_id');

        $student = Student::find($studentId);
        if (!$student) {
            $this->error("Student with ID {$studentId} not found");
            return 1;
        }

        $this->info("Message for student: {$student->full_name}");
        $this->info("Student biometric ID: {$student->biometric_id}");
        $this->line("");

        // Get the message that would be sent
        $service = new PersonnelManagementService();
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('buildEditPersonMessage');
        $method->setAccessible(true);
        
        $message = $method->invokeArgs($service, [$student]);

        $this->info("MQTT Message Content:");
        $this->line("========================");
        $this->line(json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line("========================");
        $this->line("");
        
        $this->info("Message size: " . strlen(json_encode($message)) . " bytes");
        
        if (isset($message['info']['pic'])) {
            $this->info("Photo included: Yes (" . strlen($message['info']['pic']) . " characters)");
        } else {
            $this->info("Photo included: No");
        }

        return 0;
    }
}