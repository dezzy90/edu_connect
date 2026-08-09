<?php

namespace App\Services;

use App\Models\Student;
use App\Models\BiometricDevice;
use App\Models\StudentLog;
use App\Services\DeviceRegistrationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;
use PhpMqtt\Client\MqttClient;

class RealDeviceMessageProcessor
{
    private ?MqttClient $mqttClient;
    private bool $disconnectedMode;
    private DeviceRegistrationService $deviceRegistration;

    public function __construct(?MqttClient $mqttClient = null, bool $disconnectedMode = true)
    {
        $this->mqttClient = $mqttClient;
        $this->disconnectedMode = $disconnectedMode;
        $this->deviceRegistration = new DeviceRegistrationService();
    }

    /**
     * Process message from real biometric device (2581924_ipobexa format)
     */
    public function processRealDeviceMessage(string $deviceId, string $messageData, array $context = []): array
    {
        try {
            Log::info("Processing real device message", [
                'device_id' => $deviceId,
                'message' => substr($messageData, 0, 500) . '...', // Truncate for logging
                'context' => $context
            ]);

            // Update device heartbeat immediately (before processing message)
            $device = BiometricDevice::where('device_id', $deviceId)->first();
            if ($device) {
                $device->updateHeartbeat();
                Log::debug("Device heartbeat updated at message start", [
                    'device_id' => $deviceId,
                    'last_heartbeat' => now()->toDateTimeString()
                ]);
            }

            // Parse the JSON message
            $message = json_decode($messageData, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->errorResponse('Invalid JSON format: ' . json_last_error_msg());
            }

            // Check for the real device format with operator and info
            if (isset($message['operator']) && $message['operator'] === 'RecPush' && isset($message['info'])) {
                return $this->processRecPushMessage($deviceId, $message, $context);
            }

            // Fallback to old format for compatibility
            if (isset($message['PersonnelId']) && isset($message['VerifyStatus'])) {
                return $this->processLegacyMessage($deviceId, $message, $context);
            }

            return $this->errorResponse('Unsupported message format: missing operator/PersonnelId');

        } catch (Exception $e) {
            Log::error("Error processing real device message", [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse('Processing error: ' . $e->getMessage());
        }
    }

    /**
     * Process RecPush message format from real device
     */
    private function processRecPushMessage(string $deviceId, array $message, array $context): array
    {
        $info = $message['info'];
        
        // Extract key fields from the real format
        $personId = $info['personId'] ?? null;
        $verifyStatus = (int) ($info['VerifyStatus'] ?? 0);
        $timestamp = $info['time'] ?? now()->toDateTimeString();
        $similarity = (float) ($info['similarity1'] ?? 0.0);
        $personName = $info['personName'] ?? '';
        $customId = $info['customId'] ?? uniqid();
        $deviceName = $info['facesluiceName'] ?? 'Unknown Device';
        
        // Log the structured data
        Log::info("RecPush message parsed", [
            'device_id' => $deviceId,
            'person_id' => $personId,
            'verify_status' => $verifyStatus,
            'similarity' => $similarity,
            'person_name' => $personName,
            'timestamp' => $timestamp
        ]);

        if (!$personId) {
            return $this->errorResponse('Missing personId in RecPush message');
        }

        // Get or auto-register device
        $device = $this->deviceRegistration->autoRegisterDevice($deviceId, $info);

        // Process based on VerifyStatus
        $result = $this->processRecPushVerifyStatus($device, $personId, $verifyStatus, $timestamp, $info);

        // Send acknowledgment if in disconnected mode
        if ($this->disconnectedMode && $this->mqttClient) {
            $this->sendRecPushAcknowledgment($deviceId, $customId, $result['success']);
        }

        return $result;
    }

    /**
     * Process legacy message format (fallback compatibility)
     */
    private function processLegacyMessage(string $deviceId, array $message, array $context): array
    {
        $personnelId = $message['PersonnelId'];
        $verifyStatus = (int) $message['VerifyStatus'];
        $timestamp = $message['Timestamp'] ?? now()->toDateTimeString();
        $messageId = $message['MessageId'] ?? uniqid();

        $device = BiometricDevice::where('device_id', $deviceId)->where('is_active', true)->first();
        if (!$device) {
            return $this->errorResponse("Device not found or inactive: {$deviceId}");
        }

        $result = $this->processVerifyStatus($device, $personnelId, $verifyStatus, $timestamp, $message);

        if ($this->disconnectedMode && $this->mqttClient) {
            $this->sendAcknowledgment($deviceId, $messageId, $result['success']);
        }

        return $result;
    }

    /**
     * Process different VerifyStatus values for RecPush format
     */
    private function processRecPushVerifyStatus(BiometricDevice $device, string $personId, int $verifyStatus, string $timestamp, array $info): array
    {
        switch ($verifyStatus) {
            case 1: // Normal successful identification
                return $this->processRecPushIdentification($device, $personId, $timestamp, $info);
                
            case 2: // Blacklist denial
                return $this->processRecPushBlacklistDenial($device, $personId, $timestamp, $info);
                
            case 24: // Unauthorized access
                return $this->processRecPushUnauthorizedAccess($device, $personId, $timestamp, $info);
                
            default:
                return $this->processRecPushOtherStatus($device, $personId, $verifyStatus, $timestamp, $info);
        }
    }

    /**
     * Process normal identification from RecPush format
     */
    private function processRecPushIdentification(BiometricDevice $device, string $personId, string $timestamp, array $info): array
    {
        // Try to find student by customId first (this should be biometric_id)
        $customId = $info['customId'] ?? '';
        $student = null;
        
        if ($customId) {
            $student = $this->findStudentByCustomId($customId, $device->school_id);
            Log::info("Student lookup by customId", [
                'custom_id' => $customId,
                'found' => $student ? true : false,
                'student_id' => $student ? $student->id : null
            ]);
        }
        
        // If not found by customId, try by personId
        if (!$student) {
            $student = $this->findStudentByPersonId($personId, $device->school_id);
            Log::info("Student lookup by personId", [
                'person_id' => $personId,
                'found' => $student ? true : false,
                'student_id' => $student ? $student->id : null
            ]);
        }
        
        if (!$student) {
            return $this->errorResponse("Student not found for customId: '{$customId}' or personId: '{$personId}'");
        }

        // Determine if this is check-in or check-out
        $eventType = $this->determineEventType($student, $device);

        try {
            // Try to create the log entry using the model's safe methods
            if ($eventType === 'check_in') {
                // Check if already checked in today
                if (!StudentLog::canCreateLog($student->id, 'check_in')) {
                    return [
                        'success' => true,
                        'message' => "Student {$student->first_name} {$student->last_name} already checked in today (similarity: {$info['similarity1']}%)",
                        'data' => [
                            'student_id' => $student->id,
                            'event_type' => 'check_in_duplicate',
                            'verify_status' => 1,
                            'similarity' => (float) ($info['similarity1'] ?? 0.0)
                        ]
                    ];
                }

                $log = StudentLog::createCheckIn($student->id, $device->id, [
                    'confidence_score' => (float) ($info['similarity1'] ?? 0.0),
                    'biometric_data' => isset($info['pic']) ? ['photo' => $info['pic']] : null,
                    'notes' => json_encode([
                        'verify_status' => 1,
                        'person_id' => $personId,
                        'person_name' => $info['personName'] ?? '',
                        'similarity' => (float) ($info['similarity1'] ?? 0.0),
                        'device_name' => $info['facesluiceName'] ?? '',
                        'custom_id' => $info['customId'] ?? '',
                        'has_photo' => !empty($info['pic']),
                        'device_location' => $device->location,
                        'operator' => 'RecPush'
                    ])
                ]);
            } else {
                // Check if already checked out today
                if (!StudentLog::canCreateLog($student->id, 'check_out')) {
                    return [
                        'success' => true,
                        'message' => "Student {$student->first_name} {$student->last_name} already checked out today (similarity: {$info['similarity1']}%)",
                        'data' => [
                            'student_id' => $student->id,
                            'event_type' => 'check_out_duplicate',
                            'verify_status' => 1,
                            'similarity' => (float) ($info['similarity1'] ?? 0.0)
                        ]
                    ];
                }

                $log = StudentLog::createCheckOut($student->id, $device->id, [
                    'confidence_score' => (float) ($info['similarity1'] ?? 0.0),
                    'biometric_data' => isset($info['pic']) ? ['photo' => $info['pic']] : null,
                    'notes' => json_encode([
                        'verify_status' => 1,
                        'person_id' => $personId,
                        'person_name' => $info['personName'] ?? '',
                        'similarity' => (float) ($info['similarity1'] ?? 0.0),
                        'device_name' => $info['facesluiceName'] ?? '',
                        'custom_id' => $info['customId'] ?? '',
                        'has_photo' => !empty($info['pic']),
                        'device_location' => $device->location,
                        'operator' => 'RecPush'
                    ])
                ]);
            }

        } catch (Exception $e) {
            // Handle validation errors gracefully
            Log::warning("Failed to create student log", [
                'student_id' => $student->id,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'device_id' => $device->device_id
            ]);

            return [
                'success' => true,
                'message' => "Recognition successful for {$student->first_name} {$student->last_name} but log creation failed: {$e->getMessage()} (similarity: {$info['similarity1']}%)",
                'data' => [
                    'student_id' => $student->id,
                    'event_type' => $eventType . '_error',
                    'verify_status' => 1,
                    'similarity' => (float) ($info['similarity1'] ?? 0.0),
                    'error' => $e->getMessage()
                ]
            ];
        }

        return [
            'success' => true,
            'message' => "Successfully processed {$eventType} for {$student->first_name} {$student->last_name} (similarity: {$info['similarity1']}%)",
            'data' => [
                'student_id' => $student->id,
                'event_type' => $eventType,
                'log_id' => $log->id,
                'verify_status' => 1,
                'similarity' => (float) ($info['similarity1'] ?? 0.0)
            ]
        ];
    }

    /**
     * Process blacklist denial from RecPush format
     */
    private function processRecPushBlacklistDenial(BiometricDevice $device, string $personId, string $timestamp, array $info): array
    {
        Log::warning("RecPush blacklist denial", [
            'device_id' => $device->device_id,
            'person_id' => $personId,
            'person_name' => $info['personName'] ?? '',
            'timestamp' => $timestamp
        ]);

        return [
            'success' => true,
            'message' => "Blacklist denial recorded for personId: {$personId} ({$info['personName']})",
            'data' => [
                'verify_status' => 2,
                'action' => 'denied_access',
                'person_name' => $info['personName'] ?? ''
            ]
        ];
    }

    /**
     * Process unauthorized access from RecPush format
     */
    private function processRecPushUnauthorizedAccess(BiometricDevice $device, string $personId, string $timestamp, array $info): array
    {
        Log::warning("RecPush unauthorized access attempt", [
            'device_id' => $device->device_id,
            'person_id' => $personId,
            'person_name' => $info['personName'] ?? '',
            'timestamp' => $timestamp
        ]);

        return [
            'success' => true,
            'message' => "Unauthorized access recorded for personId: {$personId} ({$info['personName']})",
            'data' => [
                'verify_status' => 24,
                'action' => 'unauthorized_access',
                'person_name' => $info['personName'] ?? ''
            ]
        ];
    }

    /**
     * Process other status codes from RecPush format
     */
    private function processRecPushOtherStatus(BiometricDevice $device, string $personId, int $verifyStatus, string $timestamp, array $info): array
    {
        Log::info("RecPush unknown verify status", [
            'device_id' => $device->device_id,
            'person_id' => $personId,
            'person_name' => $info['personName'] ?? '',
            'verify_status' => $verifyStatus,
            'timestamp' => $timestamp
        ]);

        return [
            'success' => true,
            'message' => "Processed unknown status {$verifyStatus} for personId: {$personId} ({$info['personName']})",
            'data' => [
                'verify_status' => $verifyStatus,
                'action' => 'unknown_status',
                'person_name' => $info['personName'] ?? ''
            ]
        ];
    }

    /**
     * Send acknowledgment for RecPush message
     */
    private function sendRecPushAcknowledgment(string $deviceId, string $customId, bool $success): void
    {
        try {
            $replyTopic = "mqtt/face/{$deviceId}";
            $acknowledgment = json_encode([
                'operator' => 'RecPushReply',
                'info' => [
                    'customId' => $customId,
                    'Status' => $success ? 'OK' : 'ERROR',
                    'Timestamp' => now()->format('Y-m-d H:i:s')
                ]
            ]);

            $this->mqttClient->publish($replyTopic, $acknowledgment, 1);
            
            Log::info("RecPush acknowledgment sent", [
                'device_id' => $deviceId,
                'custom_id' => $customId,
                'status' => $success ? 'OK' : 'ERROR',
                'topic' => $replyTopic
            ]);

        } catch (Exception $e) {
            Log::error("Failed to send RecPush acknowledgment", [
                'device_id' => $deviceId,
                'custom_id' => $customId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Find student by personId (RecPush format)
     * In RecPush, customId is the key field we should use for matching
     */
    private function findStudentByPersonId(string $personId, int $schoolId): ?Student
    {
        // For RecPush format, we should primarily use customId, but personId might also be useful
        // Let's try both approaches
        
        // First try to find by biometric_id using personId
        $student = Student::where('biometric_id', $personId)
            ->where('school_id', $schoolId)
            ->first();

        if ($student) {
            return $student;
        }

        // Try to find by student ID if personId is numeric
        if (is_numeric($personId)) {
            $student = Student::where('id', $personId)
                ->where('school_id', $schoolId)
                ->first();
            
            if ($student) {
                return $student;
            }
        }

        // Try to find by student_number as fallback
        $student = Student::where('student_number', $personId)
            ->where('school_id', $schoolId)
            ->first();

        return $student;
    }

    /**
     * Find student by customId from RecPush message
     */
    private function findStudentByCustomId(string $customId, int $schoolId): ?Student
    {
        // Primary lookup by biometric_id
        $student = Student::where('biometric_id', $customId)
            ->where('school_id', $schoolId)
            ->first();

        if ($student) {
            return $student;
        }

        // Fallback: try student_number
        $student = Student::where('student_number', $customId)
            ->where('school_id', $schoolId)
            ->first();

        return $student;
    }

    /**
     * Process different VerifyStatus values
     */
    private function processVerifyStatus(BiometricDevice $device, string $personnelId, int $verifyStatus, string $timestamp, array $message): array
    {
        switch ($verifyStatus) {
            case 1: // Normal successful identification
                return $this->processNormalIdentification($device, $personnelId, $timestamp, $message);
                
            case 2: // Blacklist denial
                return $this->processBlacklistDenial($device, $personnelId, $timestamp, $message);
                
            case 24: // Unauthorized access
                return $this->processUnauthorizedAccess($device, $personnelId, $timestamp, $message);
                
            default:
                // Handle other status codes
                return $this->processOtherStatus($device, $personnelId, $verifyStatus, $timestamp, $message);
        }
    }

    /**
     * Process normal identification (check-in/check-out)
     */
    private function processNormalIdentification(BiometricDevice $device, string $personnelId, string $timestamp, array $message): array
    {
        // Find student by PersonnelId (assuming it maps to biometric_id or student ID)
        $student = $this->findStudentByPersonnelId($personnelId, $device->school_id);
        
        if (!$student) {
            return $this->errorResponse("Student not found for PersonnelId: {$personnelId}");
        }

        // Determine if this is check-in or check-out
        $eventType = $this->determineEventType($student, $device);

        // Create log entry
        $log = StudentLog::create([
            'student_id' => $student->id,
            'device_id' => $device->id,
            'event_type' => $eventType,
            'event_time' => $timestamp,
            'metadata' => json_encode([
                'verify_status' => 1,
                'personnel_id' => $personnelId,
                'raw_message' => $message,
                'device_location' => $device->location
            ])
        ]);

        return [
            'success' => true,
            'message' => "Successfully processed {$eventType} for {$student->first_name} {$student->last_name}",
            'data' => [
                'student_id' => $student->id,
                'event_type' => $eventType,
                'log_id' => $log->id,
                'verify_status' => 1
            ]
        ];
    }

    /**
     * Process blacklist denial
     */
    private function processBlacklistDenial(BiometricDevice $device, string $personnelId, string $timestamp, array $message): array
    {
        // Log the blacklist denial attempt
        Log::warning("Blacklist denial", [
            'device_id' => $device->device_id,
            'personnel_id' => $personnelId,
            'timestamp' => $timestamp
        ]);

        return [
            'success' => true,
            'message' => "Blacklist denial recorded for PersonnelId: {$personnelId}",
            'data' => [
                'verify_status' => 2,
                'action' => 'denied_access'
            ]
        ];
    }

    /**
     * Process unauthorized access
     */
    private function processUnauthorizedAccess(BiometricDevice $device, string $personnelId, string $timestamp, array $message): array
    {
        // Log the unauthorized access attempt
        Log::warning("Unauthorized access attempt", [
            'device_id' => $device->device_id,
            'personnel_id' => $personnelId,
            'timestamp' => $timestamp
        ]);

        return [
            'success' => true,
            'message' => "Unauthorized access recorded for PersonnelId: {$personnelId}",
            'data' => [
                'verify_status' => 24,
                'action' => 'unauthorized_access'
            ]
        ];
    }

    /**
     * Process other status codes
     */
    private function processOtherStatus(BiometricDevice $device, string $personnelId, int $verifyStatus, string $timestamp, array $message): array
    {
        Log::info("Unknown verify status", [
            'device_id' => $device->device_id,
            'personnel_id' => $personnelId,
            'verify_status' => $verifyStatus,
            'timestamp' => $timestamp
        ]);

        return [
            'success' => true,
            'message' => "Processed unknown status {$verifyStatus} for PersonnelId: {$personnelId}",
            'data' => [
                'verify_status' => $verifyStatus,
                'action' => 'unknown_status'
            ]
        ];
    }

    /**
     * Find student by PersonnelId
     */
    private function findStudentByPersonnelId(string $personnelId, int $schoolId): ?Student
    {
        // Try to find by biometric_id first
        $student = Student::where('biometric_id', $personnelId)
            ->where('school_id', $schoolId)
            ->first();

        if (!$student) {
            // Try to find by student ID if PersonnelId is numeric
            if (is_numeric($personnelId)) {
                $student = Student::where('id', $personnelId)
                    ->where('school_id', $schoolId)
                    ->first();
            }
        }

        return $student;
    }

    /**
     * Determine if this is check-in or check-out
     */
    private function determineEventType(Student $student, BiometricDevice $device): string
    {
        // Get the latest log for this student today
        $lastLog = StudentLog::getLatestForStudent($student->id, today());

        // If no log exists or last log was check-out, this should be check-in
        if (!$lastLog || $lastLog->event_type === 'check_out') {
            // Check if check-in is allowed (not already checked in today)
            if (StudentLog::canCreateLog($student->id, 'check_in')) {
                return 'check_in';
            }
            // If check-in already exists today, default to check-out
            return 'check_out';
        }

        // If last log was check-in, this should be check-out
        if ($lastLog->event_type === 'check_in') {
            // Check if check-out is allowed (not already checked out today)
            if (StudentLog::canCreateLog($student->id, 'check_out')) {
                return 'check_out';
            }
            // If already checked out, this might be a duplicate scan - still return check_out
            return 'check_out';
        }

        // Default fallback
        return 'check_in';
    }

    /**
     * Send acknowledgment to device (for disconnected mode)
     */
    private function sendAcknowledgment(string $deviceId, string $messageId, bool $success): void
    {
        try {
            $replyTopic = "mqtt/face/{$deviceId}";
            $acknowledgment = json_encode([
                'MessageId' => $messageId,
                'Status' => $success ? 'OK' : 'ERROR',
                'Timestamp' => now()->toISOString()
            ]);

            $this->mqttClient->publish($replyTopic, $acknowledgment, 1);
            
            Log::info("Acknowledgment sent", [
                'device_id' => $deviceId,
                'message_id' => $messageId,
                'status' => $success ? 'OK' : 'ERROR',
                'topic' => $replyTopic
            ]);

        } catch (Exception $e) {
            Log::error("Failed to send acknowledgment", [
                'device_id' => $deviceId,
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create error response
     */
    private function errorResponse(string $message): array
    {
        return [
            'success' => false,
            'message' => $message,
            'data' => null
        ];
    }
}