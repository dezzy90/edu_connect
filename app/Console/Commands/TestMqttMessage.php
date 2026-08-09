<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BiometricMessageProcessor;
use App\Models\BiometricDevice;
use App\Models\Student;

class TestMqttMessage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mqtt:test {--device= : Device ID to test with} {--student= : Student ID to test with}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test MQTT message processing with sample data';

    private BiometricMessageProcessor $processor;

    /**
     * Create a new command instance.
     */
    public function __construct(BiometricMessageProcessor $processor)
    {
        parent::__construct();
        $this->processor = $processor;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🇨🇲 Testing MQTT Message Processing for Cameroon Schools');
        $this->newLine();

        // Get sample data
        $device = $this->getTestDevice();
        $student = $this->getTestStudent();

        if (!$device) {
            $this->error('No biometric devices found. Please seed the database first.');
            return 1;
        }

        if (!$student) {
            $this->error('No students with biometric IDs found. Please seed the database first.');
            return 1;
        }

        $this->info("📱 Testing with device: {$device->name} (ID: {$device->device_id})");
        $this->info("👤 Testing with student: {$student->first_name} {$student->last_name} (Biometric: {$student->biometric_id})");
        $this->newLine();

        // Test scenarios
        $this->testCheckIn($device, $student);
        $this->newLine();
        
        $this->testCheckOut($device, $student);
        $this->newLine();
        
        $this->testInvalidBiometricId($device);
        $this->newLine();
        
        $this->testInvalidDeviceId();
        
        return 0;
    }

    private function getTestDevice(): ?BiometricDevice
    {
        $deviceId = $this->option('device');
        
        if ($deviceId) {
            return BiometricDevice::where('device_id', $deviceId)->active()->first();
        }
        
        return BiometricDevice::active()->first();
    }

    private function getTestStudent(): ?Student
    {
        $studentId = $this->option('student');
        
        if ($studentId) {
            return Student::where('id', $studentId)->whereNotNull('biometric_id')->first();
        }
        
        return Student::whereNotNull('biometric_id')->first();
    }

    private function testCheckIn(BiometricDevice $device, Student $student)
    {
        $this->info('🔑 Testing Check-In Process');
        
        // Create sample MQTT message (JSON format)
        $message = json_encode([
            'biometric_id' => $student->biometric_id,
            'confidence' => 95.8,
            'timestamp' => now()->toISOString(),
            'event' => 'recognition',
            'device_location' => $device->location
        ]);

        $this->line("📨 Simulated MQTT Message: {$message}");
        
        // Process the message
        $result = $this->processor->processMessage(
            $device->device_id, 
            $message, 
            ['type' => 'recognition']
        );

        // Display results
        if ($result['success']) {
            $this->info("✅ " . $result['message']);
            if (isset($result['data'])) {
                $this->line("📊 Event Type: " . $result['data']['event_type']);
                $this->line("📝 Log ID: " . $result['data']['log_id']);
            }
        } else {
            $this->error("❌ " . $result['message']);
        }
    }

    private function testCheckOut(BiometricDevice $device, Student $student)
    {
        $this->info('🚪 Testing Check-Out Process');
        
        // Create sample MQTT message
        $message = json_encode([
            'biometric_id' => $student->biometric_id,
            'confidence' => 92.3,
            'timestamp' => now()->addMinutes(10)->toISOString(),
            'event' => 'recognition',
            'device_location' => $device->location
        ]);

        $this->line("📨 Simulated MQTT Message: {$message}");
        
        // Process the message
        $result = $this->processor->processMessage(
            $device->device_id, 
            $message, 
            ['type' => 'recognition']
        );

        // Display results
        if ($result['success']) {
            $this->info("✅ " . $result['message']);
            if (isset($result['data'])) {
                $this->line("📊 Event Type: " . $result['data']['event_type']);
                $this->line("📝 Log ID: " . $result['data']['log_id']);
            }
        } else {
            $this->error("❌ " . $result['message']);
        }
    }

    private function testInvalidBiometricId(BiometricDevice $device)
    {
        $this->info('🚫 Testing Invalid Biometric ID');
        
        $message = json_encode([
            'biometric_id' => 'INVALID_ID_12345',
            'confidence' => 85.0,
            'timestamp' => now()->toISOString(),
            'event' => 'recognition'
        ]);

        $this->line("📨 Simulated MQTT Message: {$message}");
        
        $result = $this->processor->processMessage(
            $device->device_id, 
            $message, 
            ['type' => 'recognition']
        );

        if ($result['success']) {
            $this->info("✅ " . $result['message']);
        } else {
            $this->error("❌ " . $result['message']); // This should fail
        }
    }

    private function testInvalidDeviceId()
    {
        $this->info('📱 Testing Invalid Device ID');
        
        $message = json_encode([
            'biometric_id' => 'some_biometric_id',
            'confidence' => 90.0,
            'timestamp' => now()->toISOString(),
            'event' => 'recognition'
        ]);

        $this->line("📨 Simulated MQTT Message: {$message}");
        
        $result = $this->processor->processMessage(
            'INVALID_DEVICE_ID', 
            $message, 
            ['type' => 'recognition']
        );

        if ($result['success']) {
            $this->info("✅ " . $result['message']);
        } else {
            $this->error("❌ " . $result['message']); // This should fail
        }
    }
}
