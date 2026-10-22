<?php

// Isolated first-install route module. The full application already wires these in routes/admin.php.
use App\Http\Controllers\Api\V2\Admin\DeviceRegistryController;
use App\Http\Controllers\Api\V2\Admin\GateSetupController;
use App\Http\Controllers\Api\V2\Admin\GateWorkspaceController;
use App\Http\Controllers\Api\V2\Admin\HikvisionEnrollmentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:admin')->prefix('admin')->name('admin.hikvision-enrollments.')->group(function (): void {
    Route::get('/hikvision-enrollments', [HikvisionEnrollmentController::class, 'index'])->name('index');
    Route::post('/hikvision-enrollment-photos/{photo}/retry', [HikvisionEnrollmentController::class, 'retryPhoto'])->whereNumber('photo')->middleware('throttle:20,1')->name('retry-photo');
    Route::post('/hikvision-enrollments/{enrollment}/retry', [HikvisionEnrollmentController::class, 'retryEnrollment'])->whereNumber('enrollment')->middleware('throttle:20,1')->name('retry');
});

Route::middleware('auth:admin')->prefix('admin/gate')->name('admin.gate.')->group(function (): void {
    Route::get('/devices', [DeviceRegistryController::class, 'index'])->name('devices.index');
    Route::post('/devices', [DeviceRegistryController::class, 'store'])->middleware('throttle:20,1')->name('devices.register');
    Route::get('/devices/{device}', [DeviceRegistryController::class, 'index'])->whereNumber('device')->name('devices.show');
    Route::post('/devices/{device}/actions', [DeviceRegistryController::class, 'store'])->whereNumber('device')->middleware('throttle:20,1')->name('devices.actions');
    Route::get('/setup', [GateSetupController::class, 'index'])->name('setup.index');
    Route::post('/setup/schools/{school}', [GateSetupController::class, 'store'])->whereNumber('school')->middleware('throttle:20,1')->name('setup.save');
    Route::post('/setup/schools/{school}/activate', [GateSetupController::class, 'activate'])->whereNumber('school')->middleware('throttle:10,1')->name('setup.activate');
    Route::post('/setup/schools/{school}/publications/{publication}/pause', [GateSetupController::class, 'pause'])->whereNumber(['school', 'publication'])->middleware('throttle:10,1')->name('setup.pause');
    Route::get('/', [GateWorkspaceController::class, 'index'])->name('index');
    Route::get('/days/{day}', [GateWorkspaceController::class, 'show'])->whereNumber('day')->name('show');
    Route::post('/days/{day}/actions', [GateWorkspaceController::class, 'store'])->whereNumber('day')->middleware('throttle:20,1')->name('actions');
});
