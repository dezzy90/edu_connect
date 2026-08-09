<?php

use App\Http\Controllers\Admin\AdminAuthUtf8Controller as AdminAuthController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\ConversationController as AdminConversationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
|
| Here is where you can register admin routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// Admin Authentication Routes
Route::prefix('admin')->group(function () {
    // Guest admin routes
    Route::middleware('guest:admin')->group(function () {
        Route::get('/login', [AdminAuthController::class, 'showLogin'])->name('admin.login');
        Route::post('/login', [AdminAuthController::class, 'login']);
        Route::get('/register', [AdminAuthController::class, 'showRegister'])->name('admin.register');
        Route::post('/register', [AdminAuthController::class, 'register']);
    });

    // Authenticated admin routes
    Route::middleware('auth:admin')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

        // Dashboard
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
        Route::get('/attendance-overview', [AdminDashboardController::class, 'attendanceOverview']);

        // School Management (Super Admin only)
        Route::middleware(['admin.super'])->group(function () {
            Route::post('schools/{school}/toggle-status', [\App\Http\Controllers\Admin\SchoolControllerUtf8::class, 'toggleStatus'])->name('admin.schools.toggle-status');
            Route::resource('schools', \App\Http\Controllers\Admin\SchoolControllerUtf8::class)->names('admin.schools');
            Route::resource('admin-users', \App\Http\Controllers\Admin\AdminUserController::class)->names('admin.admin-users');
        });

        // Student Management
        Route::get('students/import', [\App\Http\Controllers\Admin\StudentControllerUtf8::class, 'import'])->name('admin.students.import');
        Route::post('students/process-import', [\App\Http\Controllers\Admin\StudentControllerUtf8::class, 'processImport'])->name('admin.students.process-import');
        Route::resource('students', \App\Http\Controllers\Admin\StudentControllerUtf8::class)->names('admin.students');
        Route::post('students/{student}/sync', [\App\Http\Controllers\Admin\StudentControllerUtf8::class, 'syncToDevices'])->name('admin.students.sync');
        Route::get('students/{student}/sync-status', [\App\Http\Controllers\Admin\StudentControllerUtf8::class, 'syncStatus'])->name('admin.students.sync-status');

        // Academic Structure Management
        Route::resource('sections', \App\Http\Controllers\Admin\SectionController::class)->names('admin.sections');
        Route::post('sections/{section}/toggle-status', [\App\Http\Controllers\Admin\SectionController::class, 'toggleStatus'])->name('admin.sections.toggle-status');

        Route::resource('options', \App\Http\Controllers\Admin\OptionController::class)->names('admin.options');
        Route::post('options/{option}/toggle-status', [\App\Http\Controllers\Admin\OptionController::class, 'toggleStatus'])->name('admin.options.toggle-status');

        Route::resource('levels', \App\Http\Controllers\Admin\LevelController::class)->names('admin.levels');
        Route::post('levels/{level}/toggle-status', [\App\Http\Controllers\Admin\LevelController::class, 'toggleStatus'])->name('admin.levels.toggle-status');

        Route::resource('classes', \App\Http\Controllers\Admin\SchoolClassController::class)->names('admin.classes');
        Route::post('classes/{class}/toggle-status', [\App\Http\Controllers\Admin\SchoolClassController::class, 'toggleStatus'])->name('admin.classes.toggle-status');

        // Device Management
        Route::resource('devices', \App\Http\Controllers\Admin\DeviceController::class)->names('admin.devices');
        Route::post('devices/{device}/sync-students', [\App\Http\Controllers\Admin\DeviceController::class, 'syncStudents'])->name('admin.devices.sync-students');
        Route::post('devices/{device}/test-connection', [\App\Http\Controllers\Admin\DeviceController::class, 'testConnection'])->name('admin.devices.test-connection');
        Route::post('devices/{device}/toggle-status', [\App\Http\Controllers\Admin\DeviceController::class, 'toggleStatus'])->name('admin.devices.toggle-status');

        // Edu-admin Integration
        Route::get('integrations', [\App\Http\Controllers\Admin\IntegrationController::class, 'index'])->name('admin.integrations.index');
        Route::middleware(['admin.super'])->group(function () {
            Route::post('integrations', [\App\Http\Controllers\Admin\IntegrationController::class, 'store'])->name('admin.integrations.store');
            Route::patch('integrations/{connection}/credentials', [\App\Http\Controllers\Admin\IntegrationController::class, 'updateCredentials'])->name('admin.integrations.credentials.update');
        });
        Route::post('integrations/{connection}/sync-initial', [\App\Http\Controllers\Admin\IntegrationController::class, 'syncInitial'])->name('admin.integrations.sync-initial');
        Route::post('integrations/{connection}/sync-incremental', [\App\Http\Controllers\Admin\IntegrationController::class, 'syncIncremental'])->name('admin.integrations.sync-incremental');
        Route::post('integrations/{connection}/push-attendance', [\App\Http\Controllers\Admin\IntegrationController::class, 'pushAttendance'])->name('admin.integrations.push-attendance');

        // Parent Communication
        Route::get('conversations', [AdminConversationController::class, 'index'])->name('admin.conversations.index');
        Route::get('conversations/list', [AdminConversationController::class, 'listThreads'])->name('admin.conversations.list');
        Route::post('conversations/realtime/auth', [AdminConversationController::class, 'authorizeRealtime'])->name('admin.conversations.realtime.auth');
        Route::get('conversations/{thread}', [AdminConversationController::class, 'show'])->name('admin.conversations.show');
        Route::post('conversations/{thread}/messages', [AdminConversationController::class, 'postMessage'])->name('admin.conversations.messages.store');
        Route::post('conversations/{thread}/read', [AdminConversationController::class, 'markRead'])->name('admin.conversations.read');
        Route::patch('conversations/{thread}/status', [AdminConversationController::class, 'updateStatus'])->name('admin.conversations.status.update');

        // Attendance Management
        Route::resource('attendance', \App\Http\Controllers\Admin\AttendanceController::class)->names('admin.attendance');

        // Settings
        Route::get('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'index'])->name('admin.settings');
        Route::put('/settings', [\App\Http\Controllers\Admin\SettingsController::class, 'update']);
    });
});

// Redirect /admin to /admin/login or /admin/dashboard
Route::get('/admin', function () {
    if (auth('admin')->check()) {
        return redirect('/admin/dashboard');
    }

    return redirect('/admin/login');
});
