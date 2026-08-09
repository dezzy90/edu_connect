<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BiometricDevice;
use App\Models\Student;
use App\Models\StudentLog;
use App\Events\StudentCheckedIn;
use App\Events\StudentCheckedOut;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class BiometricController extends Controller
{
    /**
     * Simulate receiving an MQTT message from a biometric device
     * Topic format: mqtt/face/{device_id}/Rec
     * 
     * Expected message format based on your documentation:
     * {
     *   "device_id": "DEVICE_1_01",
     *   "biometric_id": "student-biometric-uuid",
     *   "confidence": 95.5,
     *   "timestamp": "2025-09-26T10:30:00Z",
     *   "image_data": "base64-encoded-image"
     * }
     */
    public function processMqttMessage(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'device_id' => 'required|string',
                'biometric_id' => 'required|string',
                'confidence' => 'numeric|min:0|max:100',
                'timestamp' => 'nullable|date',
                'image_data' => 'nullable|string',
            ]);

            Log::info('Processing MQTT biometric message', $validated);

            // Find the device
            $device = BiometricDevice::where('device_id', $validated['device_id'])->first();
            if (!$device) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Device not found: ' . $validated['device_id']
                ], 404);
            }

            // Find the student by biometric_id
            $student = Student::where('biometric_id', $validated['biometric_id'])
                             ->where('school_id', $device->school_id)
                             ->where('is_active', true)
                             ->first();

            if (!$student) {
                Log::warning('Student not found for biometric_id', [
                    'biometric_id' => $validated['biometric_id'],
                    'device_id' => $validated['device_id'],
                    'school_id' => $device->school_id
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Student not found or not active',
                    'device_id' => $validated['device_id'],
                    'biometric_id' => $validated['biometric_id']
                ], 404);
            }

            // Determine if this is check-in or check-out
            $eventType = $this->determineEventType($student, $device);

            // Create student log entry
            $studentLog = StudentLog::create([
                'student_id' => $student->id,
                'device_id' => $device->id,
                'event_type' => $eventType,
                'biometric_data' => json_encode([
                    'confidence' => $validated['confidence'] ?? null,
                    'image_data' => $validated['image_data'] ?? null,
                    'original_timestamp' => $validated['timestamp'] ?? null,
                ]),
                'confidence_score' => $validated['confidence'] ?? null,
                'notes' => "Processed via MQTT from device {$device->device_id}",
                'processed_at' => now(),
            ]);

            // Fire appropriate event for real-time notifications
            if ($eventType === 'check_in') {
                event(new StudentCheckedIn($student, $device, $studentLog));
            } else {
                event(new StudentCheckedOut($student, $device, $studentLog));
            }

            return response()->json([
                'status' => 'success',
                'message' => "Student {$eventType} recorded successfully",
                'data' => [
                    'student' => [
                        'id' => $student->id,
                        'name' => $student->first_name . ' ' . $student->last_name,
                        'student_number' => $student->student_number,
                        'class' => $student->schoolClass->name ?? null,
                    ],
                    'device' => [
                        'id' => $device->id,
                        'device_id' => $device->device_id,
                        'name' => $device->name,
                        'location' => $device->location,
                    ],
                    'log' => [
                        'id' => $studentLog->id,
                        'event_type' => $eventType,
                        'confidence_score' => $studentLog->confidence_score,
                        'timestamp' => $studentLog->created_at->toISOString(),
                    ]
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('MQTT message validation failed', [
                'errors' => $e->errors(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Invalid message format',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error processing MQTT message', [
                'error' => $e->getMessage(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process biometric message',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recent student logs for a specific device
     */
    public function getDeviceLogs(Request $request, string $deviceId): JsonResponse
    {
        try {
            $device = BiometricDevice::where('device_id', $deviceId)->first();
            if (!$device) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Device not found'
                ], 404);
            }

            $limit = $request->query('limit', 50);
            $logs = StudentLog::with(['student', 'device'])
                ->where('device_id', $device->id)
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'device' => [
                        'id' => $device->id,
                        'device_id' => $device->device_id,
                        'name' => $device->name,
                        'location' => $device->location,
                    ],
                    'logs' => $logs->map(function ($log) {
                        return [
                            'id' => $log->id,
                            'student' => [
                                'id' => $log->student->id,
                                'name' => $log->student->first_name . ' ' . $log->student->last_name,
                                'student_number' => $log->student->student_number,
                            ],
                            'event_type' => $log->event_type,
                            'confidence_score' => $log->confidence_score,
                            'timestamp' => $log->created_at->toISOString(),
                            'notes' => $log->notes,
                        ];
                    })
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve device logs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all active biometric devices
     */
    public function getDevices(): JsonResponse
    {
        try {
            $devices = BiometricDevice::with('school')
                ->where('is_active', true)
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $devices->map(function ($device) {
                    return [
                        'id' => $device->id,
                        'device_id' => $device->device_id,
                        'name' => $device->name,
                        'location' => $device->location,
                        'device_type' => $device->device_type,
                        'school' => [
                            'id' => $device->school->id,
                            'name' => $device->school->name,
                            'code' => $device->school->code,
                        ],
                        'last_heartbeat' => $device->last_heartbeat?->toISOString(),
                        'status' => $this->getDeviceStatus($device),
                    ];
                })
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve devices',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test endpoint to simulate MQTT device messages
     */
    public function simulateDevice(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'device_id' => 'required|string',
                'student_biometric_id' => 'nullable|string',
            ]);

            // Get device
            $device = BiometricDevice::where('device_id', $validated['device_id'])->first();
            if (!$device) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Device not found'
                ], 404);
            }

            // Get a random student from the same school if not specified
            $biometricId = $validated['student_biometric_id'];
            if (!$biometricId) {
                $student = Student::where('school_id', $device->school_id)
                    ->where('is_active', true)
                    ->whereNotNull('biometric_id')
                    ->inRandomOrder()
                    ->first();
                
                if (!$student) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'No students with biometric IDs found in this school'
                    ], 404);
                }
                
                $biometricId = $student->biometric_id;
            }

            // Simulate MQTT message
            $mqttMessage = [
                'device_id' => $validated['device_id'],
                'biometric_id' => $biometricId,
                'confidence' => fake()->randomFloat(2, 85, 99),
                'timestamp' => now()->toISOString(),
                'image_data' => 'data:image/jpeg;base64,' . base64_encode('simulated_image_data')
            ];

            // Process the simulated message
            $simulatedRequest = new Request($mqttMessage);
            return $this->processMqttMessage($simulatedRequest);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Simulation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Determine if this should be a check-in or check-out event
     */
    private function determineEventType(Student $student, BiometricDevice $device): string
    {
        // Get the last log entry for this student today
        $lastLog = StudentLog::where('student_id', $student->id)
            ->whereDate('created_at', today())
            ->orderBy('created_at', 'desc')
            ->first();

        // If no log today or last event was check-out, this is check-in
        if (!$lastLog || $lastLog->event_type === 'check_out') {
            return 'check_in';
        }

        // If last event was check-in, this is check-out
        return 'check_out';
    }

    /**
     * Get device status based on last heartbeat
     */
    private function getDeviceStatus(BiometricDevice $device): string
    {
        if (!$device->last_heartbeat) {
            return 'never_connected';
        }

        $minutesSinceHeartbeat = $device->last_heartbeat->diffInMinutes(now());

        if ($minutesSinceHeartbeat <= 5) {
            return 'online';
        } elseif ($minutesSinceHeartbeat <= 60) {
            return 'idle';
        } else {
            return 'offline';
        }
    }
}
