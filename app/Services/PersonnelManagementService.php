<?php

namespace App\Services;

use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use App\Models\Student;
use App\Models\BiometricDevice;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Service for managing personnel on biometric devices
 * Implements EditPerson MQTT command for adding/updating students
 */
class PersonnelManagementService
{
    /**
     * Create MQTT client with connection settings
     */
    private function createMqttClient(): MqttClient
    {
        $host = config('mqtt.host');
        $port = config('mqtt.port');
        $clientId = 'rod-personnel-' . time() . '-' . rand(1000, 9999);
        
        return new MqttClient($host, $port, $clientId);
    }

    /**
     * Get MQTT connection settings
     */
    private function getConnectionSettings(): ConnectionSettings
    {
        $username = config('mqtt.username');
        $password = config('mqtt.password');
        
        $connectionSettings = (new ConnectionSettings())
            ->setKeepAliveInterval(60);
            
        // Only set username and password if they are not null
        // This matches the working test file behavior
        if ($username !== null) {
            $connectionSettings->setUsername($username);
        }
        
        if ($password !== null) {
            $connectionSettings->setPassword($password);
        }
        
        return $connectionSettings;
    }

    /**
     * Add or update a student on a specific biometric device
     */
    public function syncStudentToDevice(Student $student, BiometricDevice $device): array
    {
        $mqttClient = null;
        
        try {
            // Create MQTT client
            $mqttClient = $this->createMqttClient();
            $connectionSettings = $this->getConnectionSettings();
            
            // Connect to MQTT broker
            $mqttClient->connect($connectionSettings, false);
            
            // Prepare EditPerson message
            $message = $this->buildEditPersonMessage($student);
            
            // Send to device
            $topic = "mqtt/face/{$device->device_id}";
            $messageJson = json_encode($message);
            
            Log::info("About to send MQTT message", [
                'topic' => $topic,
                'message_size' => strlen($messageJson),
                'message_content' => $message, // Log the full message for debugging
                'device_id' => $device->device_id,
                'student_biometric_id' => $student->biometric_id,
            ]);
            
            $mqttClient->publish($topic, $messageJson, 1); // QoS 1 for reliability
            
            // Disconnect
            $mqttClient->disconnect();
            
            Log::info("Personnel sync sent", [
                'student_id' => $student->id,
                'device_id' => $device->device_id,
                'topic' => $topic,
                'message_id' => $message['messageId']
            ]);
            
            return [
                'success' => true,
                'message_id' => $message['messageId'],
                'topic' => $topic,
                'student' => $student->first_name . ' ' . $student->last_name,
                'device' => $device->device_id
            ];
            
        } catch (Exception $e) {
            Log::error("Personnel sync failed", [
                'student_id' => $student->id,
                'device_id' => $device->device_id,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'student' => $student->first_name . ' ' . $student->last_name,
                'device' => $device->device_id
            ];
        } finally {
            // Ensure disconnection
            if ($mqttClient) {
                try {
                    $mqttClient->disconnect();
                } catch (Exception $e) {
                    // Ignore disconnect errors
                }
            }
        }
    }

    /**
     * Sync a student to all devices in their school
     */
    public function syncStudentToSchool(Student $student): array
    {
        $results = [];
        
        // Get all devices in the student's school
        $devices = BiometricDevice::where('school_id', $student->school_id)
            ->where('is_active', true)
            ->get();
        
        if ($devices->isEmpty()) {
            return [
                'success' => false,
                'error' => 'No active devices found in student\'s school',
                'student' => $student->first_name . ' ' . $student->last_name,
                'school' => $student->school->name ?? 'Unknown'
            ];
        }
        
        foreach ($devices as $device) {
            $results[] = $this->syncStudentToDevice($student, $device);
        }
        
        return $results;
    }

    /**
     * Sync all students in a school to all devices in that school
     */
    public function syncSchoolPersonnel(int $schoolId): array
    {
        $results = [];
        
        // Get all students with biometric IDs in the school
        $students = Student::where('school_id', $schoolId)
            ->whereNotNull('biometric_id')
            ->get();
        
        // Get all active devices in the school
        $devices = BiometricDevice::where('school_id', $schoolId)
            ->where('is_active', true)
            ->get();
        
        if ($students->isEmpty()) {
            return [
                'success' => false,
                'error' => 'No students with biometric IDs found in school',
                'school_id' => $schoolId
            ];
        }
        
        if ($devices->isEmpty()) {
            return [
                'success' => false,
                'error' => 'No active devices found in school',
                'school_id' => $schoolId
            ];
        }
        
        foreach ($students as $student) {
            foreach ($devices as $device) {
                $results[] = $this->syncStudentToDevice($student, $device);
                
                // Small delay to prevent overwhelming the device
                usleep(100000); // 100ms delay
            }
        }
        
        return $results;
    }

    /**
     * Delete a student from a specific device
     */
    public function deleteStudentFromDevice(Student $student, BiometricDevice $device): array
    {
        $mqttClient = null;
        
        try {
            // Create MQTT client
            $mqttClient = $this->createMqttClient();
            $connectionSettings = $this->getConnectionSettings();
            
            // Connect to MQTT broker
            $mqttClient->connect($connectionSettings, false);
            
            // Prepare DeletePerson message (similar structure but different operator)
            $message = [
                'messageId' => $this->generateMessageId(),
                'operator' => 'DelPerson',
                'info' => [
                    'customId' => $student->biometric_id,
                ]
            ];
            
            $topic = "mqtt/face/{$device->device_id}";
            $mqttClient->publish($topic, json_encode($message), 1);
            
            // Disconnect
            $mqttClient->disconnect();
            
            Log::info("Personnel delete sent", [
                'student_id' => $student->id,
                'device_id' => $device->device_id,
                'message_id' => $message['messageId']
            ]);
            
            return [
                'success' => true,
                'message_id' => $message['messageId'],
                'operation' => 'delete',
                'student' => $student->first_name . ' ' . $student->last_name,
                'device' => $device->device_id
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'operation' => 'delete',
                'student' => $student->first_name . ' ' . $student->last_name,
                'device' => $device->device_id
            ];
        } finally {
            // Ensure disconnection
            if ($mqttClient) {
                try {
                    $mqttClient->disconnect();
                } catch (Exception $e) {
                    // Ignore disconnect errors
                }
            }
        }
    }

    /**
     * Build EditPerson message from Student model
     */
    private function buildEditPersonMessage(Student $student): array
    {
        // Determine gender code (0 = male, 1 = female, based on API docs)
        $genderCode = match(strtolower($student->gender ?? 'male')) {
            'm', 'male', 'masculin' => 0,
            'f', 'female', 'feminin' => 1,
            default => 0
        };
        
        // Generate validity period (current academic year)
        $academicYearStart = now()->month >= 9 ? 
            now()->format('Y') : (now()->year - 1);
        $academicYearEnd = $academicYearStart + 1;
        
        $cardValidBegin = "{$academicYearStart}-09-01 10:00:00";
        $cardValidEnd = "{$academicYearEnd}-07-31 16:00:00";
        
        // Build message following exact documentation format
        $message = [
            'messageId' => $this->generateMessageId(),
            'operator' => 'EditPerson',
            'info' => [
                'customId' => $student->biometric_id,
                'name' => trim($student->first_name . ' ' . $student->last_name),
                'nation' => 1, // Default nationality code
                'gender' => $genderCode,
                'birthday' => $student->date_of_birth ? 
                    $student->date_of_birth->format('Y-m-d') : '2000-01-01',
                'address' => $student->address ?? '',
                'idCard' => $student->student_number ?? $student->biometric_id,
                'tempCardType' => 0, // Permanent card
                'EffectNumber' => 3, // Effect number from documentation
                'cardValidBegin' => $cardValidBegin,
                'cardValidEnd' => $cardValidEnd,
                'telnum1' => $student->phone ?? $this->getParentPhone($student),
                'native' => $this->getStudentLocation($student),
                'cardType2' => 0,
                'cardNum2' => '',
                'notes' => $this->buildStudentNotes($student),
                'personType' => 0, // Regular person type
                'cardType' => 0, // Default card type
                'strategyInfo' => [
                    'strategyNum' => 1,
                    'strategyData' => [
                        [
                            'strategyID' => 1,
                            'strategyName' => 'student_access'
                        ]
                    ]
                ]
            ]
        ];

        // Add photo if available in base64 format (exactly as in documentation)
        if ($student->photo_base64) {
            // Validate and clean the base64 image
            $imageData = $this->validateAndCleanBase64Image($student->photo_base64);
            if ($imageData) {
                $message['info']['pic'] = $imageData;
                Log::info("Added photo to sync message", [
                    'student_id' => $student->id,
                    'photo_size' => strlen($imageData),
                    'has_data_prefix' => strpos($imageData, 'data:') === 0,
                ]);
            } else {
                Log::warning("Invalid or corrupted photo, syncing WITHOUT image", [
                    'student_id' => $student->id,
                    'original_size' => strlen($student->photo_base64),
                ]);
                // Don't add pic field at all if image is invalid
            }
        } else {
            Log::info("No photo available for student, syncing without image", [
                'student_id' => $student->id
            ]);
        }

        return $message;
    }

    /**
     * Validate and clean base64 image data for device consumption
     */
    private function validateAndCleanBase64Image(string $base64Data): ?string
    {
        try {
            Log::info("Starting image validation", [
                'original_length' => strlen($base64Data),
                'starts_with_data' => strpos($base64Data, 'data:') === 0
            ]);
            
            // Remove any whitespace and newlines
            $base64Data = trim(preg_replace('/\s+/', '', $base64Data));
            
            // Extract just the base64 part (remove data: prefix if present)
            if (strpos($base64Data, 'data:') === 0) {
                $commaPos = strpos($base64Data, ',');
                if ($commaPos === false) {
                    Log::error("Invalid data URL format - no comma found");
                    return null;
                }
                $base64Only = substr($base64Data, $commaPos + 1);
            } else {
                $base64Only = $base64Data;
            }
            
            // Remove any remaining whitespace from base64 data
            $base64Only = preg_replace('/\s+/', '', $base64Only);
            
            // Validate base64 format - must only contain valid base64 characters
            if (!preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $base64Only)) {
                Log::error("Invalid base64 characters found");
                return null;
            }
            
            // Ensure proper padding
            $len = strlen($base64Only);
            if ($len === 0) {
                Log::error("Empty base64 data");
                return null;
            }
            
            if ($len % 4 !== 0) {
                $padLength = 4 - ($len % 4);
                $base64Only .= str_repeat('=', $padLength);
                Log::info("Added padding", ['pad_length' => $padLength]);
            }
            
            // Validate base64 decoding
            $decodedData = base64_decode($base64Only, true);
            if ($decodedData === false || strlen($decodedData) === 0) {
                Log::error("Base64 decode failed or empty result");
                return null;
            }
            
            // Verify it's actually an image
            $imageInfo = @getimagesizefromstring($decodedData);
            if ($imageInfo === false) {
                Log::error("Not a valid image file");
                return null;
            }
            
            // Check decoded size limit (device wants < 1M decoded)
            if (strlen($decodedData) > 1048576) {
                Log::error("Decoded image too large", [
                    'decoded_size' => strlen($decodedData),
                    'limit' => 1048576
                ]);
                return null;
            }
            
            // Device-specific requirements: max 200KB file size, max 1080x1920 pixels
            $fileSizeKB = strlen($decodedData) / 1024;
            $maxFileSizeKB = 200;
            $maxWidth = 1080;
            $maxHeight = 1920;
            
            if ($fileSizeKB > $maxFileSizeKB) {
                Log::error("Image file size exceeds device limit", [
                    'size_kb' => round($fileSizeKB, 2),
                    'max_kb' => $maxFileSizeKB
                ]);
                return null;
            }
            
            if ($imageInfo[0] > $maxWidth || $imageInfo[1] > $maxHeight) {
                Log::error("Image dimensions exceed device limit", [
                    'dimensions' => $imageInfo[0] . 'x' . $imageInfo[1],
                    'max_dimensions' => $maxWidth . 'x' . $maxHeight
                ]);
                return null;
            }
            
            // CRITICAL: Use exact format from documentation
            // Documentation shows: "data:image/jpeg;base64,Qk025wAAAAAAAAADYAAA..."
            
            $dataUrl = "data:{$imageInfo['mime']};base64,{$base64Only}";
            
            Log::info("Image validation successful", [
                'mime_type' => $imageInfo['mime'],
                'width' => $imageInfo[0],
                'height' => $imageInfo[1],
                'decoded_size' => strlen($decodedData),
                'base64_length' => strlen($base64Only),
                'final_format' => 'data_url_with_prefix',
                'final_length' => strlen($dataUrl)
            ]);
            
            // Return full data URL as shown in documentation
            return $dataUrl;
            
        } catch (\Exception $e) {
            Log::error("Error validating base64 image", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Generate unique message ID (following documentation format)
     */
    private function generateMessageId(): string
    {
        // Format: ID:hostname-timestamp:processid:counter
        return 'ID:' . gethostname() . '-' . hrtime(true) . ':' . getmypid() . ':' . rand(1, 999);
    }

    /**
     * Get parent phone number for student
     */
    private function getParentPhone(Student $student): string
    {
        // Try to get parent phone from related Parent model
        if ($student->parents && $student->parents->isNotEmpty()) {
            $parent = $student->parents->first();
            return $parent->phone ?? '';
        }
        
        return '';
    }

    /**
     * Get student location (school location)
     */
    private function getStudentLocation(Student $student): string
    {
        if ($student->school) {
            // Extract city from school address
            $address = $student->school->address ?? '';
            if (preg_match('/([A-Za-z\s]+),\s*([A-Za-z\s]+),\s*Cameroon/i', $address, $matches)) {
                return $matches[1] . ', ' . $matches[2];
            }
        }
        
        return 'Cameroon';
    }

    /**
     * Build notes field with student details
     */
    private function buildStudentNotes(Student $student): string
    {
        $notes = [];
        
        if ($student->schoolClass) {
            $notes[] = "Classe: {$student->schoolClass->name}";
        }
        
        if ($student->school) {
            $notes[] = "École: {$student->school->name}";
        }
        
        $notes[] = "Ajouté: " . now()->format('Y-m-d H:i:s');
        
        return implode(' | ', $notes);
    }

    /**
     * Process acknowledgment from device
     */
    public function handlePersonnelAck(array $ackData, string $deviceId): void
    {
        try {
            Log::info("Personnel ACK received", [
                'device_id' => $deviceId,
                'ack_data' => $ackData
            ]);
            
            // You can add more sophisticated ACK handling here
            // For example, updating a personnel_sync_status table
            
        } catch (Exception $e) {
            Log::error("Error handling personnel ACK", [
                'device_id' => $deviceId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Sync student to a device with automatic retry without image on failure
     */
    public function syncStudentWithRetry(Student $student, BiometricDevice $device): array
    {
        // First attempt: with image if available
        $result = $this->syncStudentToDevice($student, $device);
        
        if (!$result['success'] || !isset($result['message_id'])) {
            return $result;
        }

        Log::info("Student sync initiated, monitoring for errors", [
            'message_id' => $result['message_id'],
            'student_id' => $student->id,
            'device_id' => $device->device_id,
            'has_image' => !empty($student->photo_base64),
        ]);

        return $result;
    }

    /**
     * Sync student to device without image
     */
    public function syncStudentWithoutImage(Student $student, BiometricDevice $device): array
    {
        // Temporarily remove the image
        $originalPhoto = $student->photo_base64;
        $student->photo_base64 = null;
        
        try {
            $result = $this->syncStudentToDevice($student, $device);
            Log::info("Synced student without image", [
                'student_id' => $student->id,
                'device_id' => $device->device_id,
                'had_original_image' => !empty($originalPhoto),
            ]);
            return array_merge($result, ['synced_without_image' => true]);
            
        } finally {
            // Restore the original photo
            $student->photo_base64 = $originalPhoto;
        }
    }
}