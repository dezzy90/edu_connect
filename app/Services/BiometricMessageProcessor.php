<?php

namespace App\Services;

use App\Models\BiometricDevice;
use App\Models\Student;
use App\Models\StudentLog;
use App\Events\StudentCheckedIn;
use App\Events\StudentCheckedOut;
use Exception;
use Illuminate\Support\Facades\Log;

class BiometricMessageProcessor
{
    /**
     * Process biometric message from MQTT.
     */
    public function processMessage(string $deviceId, string $message, array $messageInfo = []): array
    {
        try {
            // Determine the processing method based on message type
            $messageType = $messageInfo['type'] ?? 'recognition';
            
            switch ($messageType) {
                case 'recognition':
                    return $this->processRecognitionMessage($deviceId, $message, $messageInfo);
                case 'capture':
                    return $this->processCaptureMessage($deviceId, $message, $messageInfo);
                case 'qrcode':
                    return $this->processQRCodeMessage($deviceId, $message, $messageInfo);
                case 'idcard':
                    return $this->processIDCardMessage($deviceId, $message, $messageInfo);
                case 'card':
                    return $this->processCardMessage($deviceId, $message, $messageInfo);
                case 'alarm':
                    return $this->processAlarmMessage($deviceId, $message, $messageInfo);
                default:
                    return $this->processRecognitionMessage($deviceId, $message, $messageInfo);
            }

        } catch (Exception $e) {
            Log::error('Biometric message processing error', [
                'device_id' => $deviceId,
                'message' => $message,
                'message_info' => $messageInfo,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Processing error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Parse the biometric message.
     */
    private function parseMessage(string $message): ?array
    {
        // Attempt to decode JSON message
        $decoded = json_decode($message, true);
        
        if ($decoded !== null) {
            return $this->parseJsonMessage($decoded);
        }

        // Attempt to parse other formats (XML, plain text, etc.)
        return $this->parseAlternativeFormats($message);
    }

    /**
     * Parse JSON format message.
     */
    private function parseJsonMessage(array $data): ?array
    {
        // Expected JSON structure (adjust based on your device's format):
        // {
        //   "biometric_id": "12345",
        //   "confidence": 98.5,
        //   "timestamp": "2024-01-01T10:30:00Z",
        //   "event": "recognition",
        //   "data": {...}
        // }

        return [
            'biometric_id' => $data['biometric_id'] ?? $data['user_id'] ?? $data['id'] ?? null,
            'confidence_score' => $data['confidence'] ?? $data['confidence_score'] ?? null,
            'timestamp' => $data['timestamp'] ?? null,
            'event' => $data['event'] ?? $data['type'] ?? 'recognition',
            'raw_data' => $data
        ];
    }

    /**
     * Parse alternative message formats.
     */
    private function parseAlternativeFormats(string $message): ?array
    {
        // Try parsing simple formats like "ID:12345,CONF:98.5"
        if (preg_match('/ID:(\w+)(?:,CONF:([\d.]+))?/', $message, $matches)) {
            return [
                'biometric_id' => $matches[1],
                'confidence_score' => isset($matches[2]) ? (float) $matches[2] : null,
                'timestamp' => now()->toISOString(),
                'event' => 'recognition',
                'raw_data' => ['original_message' => $message]
            ];
        }

        // Try parsing XML (add XML parsing logic if needed)
        if (strpos($message, '<?xml') === 0) {
            return $this->parseXmlMessage($message);
        }

        // If all parsing fails, return null
        return null;
    }

    /**
     * Parse XML message format.
     */
    private function parseXmlMessage(string $xmlMessage): ?array
    {
        try {
            $xml = simplexml_load_string($xmlMessage);
            
            if ($xml === false) {
                return null;
            }

            return [
                'biometric_id' => (string) ($xml->biometric_id ?? $xml->user_id ?? $xml->id ?? ''),
                'confidence_score' => isset($xml->confidence) ? (float) $xml->confidence : null,
                'timestamp' => (string) ($xml->timestamp ?? now()->toISOString()),
                'event' => (string) ($xml->event ?? 'recognition'),
                'raw_data' => json_decode(json_encode($xml), true)
            ];
        } catch (Exception $e) {
            Log::warning('XML parsing failed', ['message' => $xmlMessage, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Find student by biometric data.
     */
    private function findStudent(array $data, int $schoolId): ?Student
    {
        if (empty($data['biometric_id'])) {
            return null;
        }

        return Student::where('school_id', $schoolId)
            ->where('biometric_id', $data['biometric_id'])
            ->active()
            ->first();
    }

    /**
     * Determine if this should be a check-in or check-out event.
     */
    private function determineEventType(Student $student, array $data): string
    {
        // Check student's current status
        if ($student->isCurrentlyCheckedIn()) {
            return StudentLog::EVENT_CHECK_OUT;
        }

        return StudentLog::EVENT_CHECK_IN;
    }

    /**
     * Create student log entry.
     */
    private function createStudentLog(Student $student, BiometricDevice $device, string $eventType, array $data): StudentLog
    {
        if ($eventType === StudentLog::EVENT_CHECK_IN) {
            return StudentLog::createCheckIn($student->id, $device->id, [
                'biometric_data' => $data['raw_data'] ?? null,
                'confidence_score' => $data['confidence_score'] ?? null,
            ]);
        } else {
            return StudentLog::createCheckOut($student->id, $device->id, [
                'biometric_data' => $data['raw_data'] ?? null,
                'confidence_score' => $data['confidence_score'] ?? null,
            ]);
        }
    }

    /**
     * Fire appropriate event for real-time notifications.
     */
    private function fireEvent(StudentLog $log, Student $student): void
    {
        if ($log->isCheckIn()) {
            event(new StudentCheckedIn($student, $log));
        } else {
            event(new StudentCheckedOut($student, $log));
        }
    }

    /**
     * Process recognition message (main check-in/check-out logic).
     */
    private function processRecognitionMessage(string $deviceId, string $message, array $messageInfo): array
    {
        // Find the biometric device
        $device = BiometricDevice::where('device_id', $deviceId)->active()->first();
        
        if (!$device) {
            return [
                'success' => false,
                'message' => "Device not found or inactive: {$deviceId}"
            ];
        }

        // Update device heartbeat
        $device->updateHeartbeat();

        // Parse the message
        $data = $this->parseMessage($message);
        
        if (!$data) {
            return [
                'success' => false,
                'message' => 'Invalid message format'
            ];
        }

        // Find the student
        $student = $this->findStudent($data, $device->school_id);
        
        if (!$student) {
            return [
                'success' => false,
                'message' => "Student not found for biometric ID: " . ($data['biometric_id'] ?? 'unknown')
            ];
        }

        // Determine event type (check-in or check-out)
        $eventType = $this->determineEventType($student, $data);

        // Process the log entry
        $log = $this->createStudentLog($student, $device, $eventType, $data);

        // Fire appropriate event for real-time notifications
        $this->fireEvent($log, $student);

        return [
            'success' => true,
            'message' => "Successfully processed {$eventType} for {$student->full_name}",
            'data' => [
                'student' => $student->toArray(),
                'event_type' => $eventType,
                'log_id' => $log->id
            ]
        ];
    }

    /**
     * Process stranger capture message.
     */
    private function processCaptureMessage(string $deviceId, string $message, array $messageInfo): array
    {
        // Find the biometric device
        $device = BiometricDevice::where('device_id', $deviceId)->active()->first();
        
        if (!$device) {
            return [
                'success' => false,
                'message' => "Device not found or inactive: {$deviceId}"
            ];
        }

        // Update device heartbeat
        $device->updateHeartbeat();

        // Parse the message
        $data = $this->parseMessage($message);

        // Log stranger capture event
        Log::info('Stranger capture detected', [
            'device_id' => $deviceId,
            'school_id' => $device->school_id,
            'data' => $data,
            'timestamp' => now()
        ]);

        // You might want to create a separate StrangerCapture model/table
        // For now, we'll just log it and potentially send alerts

        return [
            'success' => true,
            'message' => "Stranger capture recorded for device {$deviceId}",
            'data' => [
                'device_id' => $deviceId,
                'capture_data' => $data
            ]
        ];
    }

    /**
     * Process QR code message.
     */
    private function processQRCodeMessage(string $deviceId, string $message, array $messageInfo): array
    {
        $device = BiometricDevice::where('device_id', $deviceId)->active()->first();
        
        if (!$device) {
            return [
                'success' => false,
                'message' => "Device not found or inactive: {$deviceId}"
            ];
        }

        $device->updateHeartbeat();

        // Parse QR code data
        $data = $this->parseMessage($message);

        // Log QR code scan
        Log::info('QR Code scanned', [
            'device_id' => $deviceId,
            'school_id' => $device->school_id,
            'qr_data' => $data,
            'timestamp' => now()
        ]);

        return [
            'success' => true,
            'message' => "QR code processed for device {$deviceId}",
            'data' => $data
        ];
    }

    /**
     * Process ID card message.
     */
    private function processIDCardMessage(string $deviceId, string $message, array $messageInfo): array
    {
        $device = BiometricDevice::where('device_id', $deviceId)->active()->first();
        
        if (!$device) {
            return [
                'success' => false,
                'message' => "Device not found or inactive: {$deviceId}"
            ];
        }

        $device->updateHeartbeat();

        $data = $this->parseMessage($message);

        Log::info('ID Card scanned', [
            'device_id' => $deviceId,
            'school_id' => $device->school_id,
            'id_card_data' => $data,
            'timestamp' => now()
        ]);

        return [
            'success' => true,
            'message' => "ID card processed for device {$deviceId}",
            'data' => $data
        ];
    }

    /**
     * Process IC/RF card message.
     */
    private function processCardMessage(string $deviceId, string $message, array $messageInfo): array
    {
        $device = BiometricDevice::where('device_id', $deviceId)->active()->first();
        
        if (!$device) {
            return [
                'success' => false,
                'message' => "Device not found or inactive: {$deviceId}"
            ];
        }

        $device->updateHeartbeat();

        $data = $this->parseMessage($message);

        Log::info('IC/RF Card scanned', [
            'device_id' => $deviceId,
            'school_id' => $device->school_id,
            'card_data' => $data,
            'timestamp' => now()
        ]);

        return [
            'success' => true,
            'message' => "Card processed for device {$deviceId}",
            'data' => $data
        ];
    }

    /**
     * Process alarm message.
     */
    private function processAlarmMessage(string $deviceId, string $message, array $messageInfo): array
    {
        $device = BiometricDevice::where('device_id', $deviceId)->active()->first();
        
        if (!$device) {
            return [
                'success' => false,
                'message' => "Device not found or inactive: {$deviceId}"
            ];
        }

        $device->updateHeartbeat();

        $data = $this->parseMessage($message);

        Log::warning('Device alarm triggered', [
            'device_id' => $deviceId,
            'school_id' => $device->school_id,
            'alarm_data' => $data,
            'timestamp' => now()
        ]);

        // You might want to send immediate notifications for alarms
        // event(new DeviceAlarmTriggered($device, $data));

        return [
            'success' => true,
            'message' => "Alarm processed for device {$deviceId}",
            'data' => $data
        ];
    }

    /**
     * Process device heartbeat message.
     */
    public function processHeartbeat(string $message, ?string $deviceId = null): array
    {
        // If device ID is provided directly (from topic), use it
        if ($deviceId) {
            $device = BiometricDevice::where('device_id', $deviceId)->first();
            if ($device) {
                $device->updateHeartbeat();
                
                Log::info('Device heartbeat updated from topic', [
                    'device_id' => $deviceId,
                    'last_heartbeat' => now()->toDateTimeString()
                ]);
                
                return [
                    'success' => true,
                    'message' => "Heartbeat updated for device {$deviceId}",
                    'device_id' => $deviceId
                ];
            }
        }
        
        // Parse heartbeat message to extract device information
        $data = $this->parseMessage($message);
        
        if (isset($data['device_id']) || isset($data['deviceId'])) {
            $extractedDeviceId = $data['device_id'] ?? $data['deviceId'];
            
            $device = BiometricDevice::where('device_id', $extractedDeviceId)->first();
            if ($device) {
                $device->updateHeartbeat();
                
                Log::info('Device heartbeat updated from message', [
                    'device_id' => $extractedDeviceId,
                    'last_heartbeat' => now()->toDateTimeString()
                ]);
                
                return [
                    'success' => true,
                    'message' => "Heartbeat updated for device {$extractedDeviceId}",
                    'device_id' => $extractedDeviceId
                ];
            }
        }

        // If no specific device ID, log general heartbeat
        Log::info('General device heartbeat received', [
            'message' => $message,
            'timestamp' => now()
        ]);

        return [
            'success' => true,
            'message' => 'General heartbeat processed'
        ];
    }

    /**
     * Process basic up/down notification.
     */
    public function processBasicNotification(string $message): array
    {
        $data = $this->parseMessage($message);

        Log::info('Basic device notification', [
            'data' => $data,
            'timestamp' => now()
        ]);

        return [
            'success' => true,
            'message' => 'Basic notification processed',
            'data' => $data
        ];
    }

    /**
     * Process downlink execution acknowledgment.
     */
    public function processAcknowledgment(string $deviceId, string $message): array
    {
        $device = BiometricDevice::where('device_id', $deviceId)->active()->first();
        
        if (!$device) {
            return [
                'success' => false,
                'message' => "Device not found or inactive: {$deviceId}"
            ];
        }

        $device->updateHeartbeat();

        $data = $this->parseMessage($message);

        Log::info('Device acknowledgment received', [
            'device_id' => $deviceId,
            'school_id' => $device->school_id,
            'ack_data' => $data,
            'timestamp' => now()
        ]);

        return [
            'success' => true,
            'message' => "Acknowledgment processed for device {$deviceId}",
            'data' => $data
        ];
    }
}