<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\BiometricController;
use App\Http\Controllers\Api\StudentLogController;
use App\Http\Controllers\Api\CascadingDataController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
|--------------------------------------------------------------------------
| MQTT Biometric Device Routes
|--------------------------------------------------------------------------
*/

// MQTT message processing - this is what the actual MQTT system would call
Route::post('/biometric/mqtt-message', [BiometricController::class, 'processMqttMessage'])
    ->name('biometric.mqtt.process');

// Device management
Route::get('/biometric/devices', [BiometricController::class, 'getDevices'])
    ->name('biometric.devices.index');

Route::get('/biometric/devices/{deviceId}/logs', [BiometricController::class, 'getDeviceLogs'])
    ->name('biometric.devices.logs');

// Test simulation endpoint
Route::post('/biometric/simulate', [BiometricController::class, 'simulateDevice'])
    ->name('biometric.simulate');

/*
|--------------------------------------------------------------------------
| Cascading Data Routes (for dynamic dropdowns)
|--------------------------------------------------------------------------
*/

Route::prefix('cascading')->group(function () {
    Route::get('/sections', [CascadingDataController::class, 'getSections']);
    Route::get('/options', [CascadingDataController::class, 'getOptions']);
    Route::get('/levels', [CascadingDataController::class, 'getLevels']);
    Route::get('/classes', [CascadingDataController::class, 'getClasses']);
    Route::get('/school-data', [CascadingDataController::class, 'getSchoolData']);
});

/*
|--------------------------------------------------------------------------
| Student Log & Attendance Routes
|--------------------------------------------------------------------------
*/

// Attendance logs
Route::get('/attendance/logs', [StudentLogController::class, 'index'])
    ->name('attendance.logs.index');

// Today's attendance summary for a school
Route::get('/attendance/today-summary', [StudentLogController::class, 'getTodaysSummary'])
    ->name('attendance.today.summary');

// Current attendance status (who's currently in school)
Route::get('/attendance/current-status', [StudentLogController::class, 'getCurrentStatus'])
    ->name('attendance.current.status');

// Individual student attendance history
Route::get('/attendance/student/{studentId}', [StudentLogController::class, 'getStudentAttendance'])
    ->name('attendance.student.history');

/*
|--------------------------------------------------------------------------
| Test Routes (for development and testing)
|--------------------------------------------------------------------------
*/

Route::prefix('test')->group(function () {
    // Get sample data for testing
    Route::get('/sample-students', function () {
        return response()->json([
            'status' => 'success',
            'data' => \App\Models\Student::with(['schoolClass', 'school'])
                ->where('is_active', true)
                ->whereNotNull('biometric_id')
                ->limit(10)
                ->get()
                ->map(function ($student) {
                    return [
                        'id' => $student->id,
                        'name' => $student->first_name . ' ' . $student->last_name,
                        'student_number' => $student->student_number,
                        'biometric_id' => $student->biometric_id,
                        'school' => $student->school->name,
                        'class' => $student->schoolClass->name ?? 'N/A',
                    ];
                })
        ]);
    });

    Route::get('/sample-devices', function () {
        return response()->json([
            'status' => 'success',
            'data' => \App\Models\BiometricDevice::with('school')
                ->where('is_active', true)
                ->get()
                ->map(function ($device) {
                    return [
                        'id' => $device->id,
                        'device_id' => $device->device_id,
                        'name' => $device->name,
                        'location' => $device->location,
                        'school' => $device->school->name,
                        'mqtt_topic' => "mqtt/face/{$device->device_id}/Rec",
                    ];
                })
        ]);
    });

    // Quick test endpoint to simulate a student check-in
    Route::post('/quick-checkin', function (Request $request) {
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
        ]);

        $student = \App\Models\Student::find($validated['student_id']);
        $device = \App\Models\BiometricDevice::where('school_id', $student->school_id)->first();

        if (!$device) {
            return response()->json(['status' => 'error', 'message' => 'No device found for this school'], 404);
        }

        // Simulate MQTT message
        $mqttData = [
            'device_id' => $device->device_id,
            'biometric_id' => $student->biometric_id,
            'confidence' => 95.5,
            'timestamp' => now()->toISOString(),
        ];

        $biometricController = new BiometricController();
        return $biometricController->processMqttMessage(new Request($mqttData));
    });
});

require __DIR__ . '/admin_api.php';
require __DIR__ . '/mobile_api.php';
require __DIR__ . '/device_api.php';
require __DIR__ . '/integration_api.php';
