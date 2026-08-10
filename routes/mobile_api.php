<?php

use App\Http\Controllers\Api\V2\Mobile\AuthController;
use App\Http\Controllers\Api\V2\Mobile\ConfigController;
use App\Http\Controllers\Api\V2\Mobile\ConversationController;
use App\Http\Controllers\Api\V2\Mobile\MessageController;
use App\Http\Controllers\Api\V2\Mobile\NotificationController;
use App\Http\Controllers\Api\V2\Mobile\PushTokenController;
use App\Http\Controllers\Api\V2\Mobile\RealtimeController;
use App\Http\Controllers\Api\V2\Mobile\StudentController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v2')
    ->name('api.mobile.v2.')
    ->group(function () {
        Route::get('/config', ConfigController::class)->name('config');

        Route::prefix('auth')->name('auth.')->group(function () {
            Route::post('/register', [AuthController::class, 'register'])->name('register');
            Route::post('/login', [AuthController::class, 'login'])->name('login');
            Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
        });

        Route::middleware(['auth:sanctum', 'mobile.parent'])->group(function () {
            Route::get('/me', [AuthController::class, 'me'])->name('me');
            Route::patch('/me', [AuthController::class, 'updateProfile'])->name('me.update');
            Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

            Route::get('/children', [StudentController::class, 'index'])->name('children.index');
            Route::post('/children/link', [StudentController::class, 'link'])->name('children.link');
            Route::get('/children/{student}', [StudentController::class, 'show'])->name('children.show');
            Route::get('/children/{student}/profile', [StudentController::class, 'profile'])->name('children.profile');
            Route::get('/children/{student}/attendance', [StudentController::class, 'attendance'])->name('children.attendance');

            Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
            Route::get('/messages/{message}', [MessageController::class, 'show'])->name('messages.show');
            Route::post('/messages/{message}/read', [MessageController::class, 'markRead'])->name('messages.read');

            Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
            Route::post('/conversations/direct', [ConversationController::class, 'startDirect'])->name('conversations.direct');
            Route::get('/conversations/{thread}', [ConversationController::class, 'show'])->name('conversations.show');
            Route::post('/conversations/{thread}/messages', [ConversationController::class, 'postMessage'])->name('conversations.messages.store');
            Route::post('/conversations/{thread}/read', [ConversationController::class, 'markRead'])->name('conversations.read');

            Route::post('/push-tokens', [PushTokenController::class, 'store'])->name('push-tokens.store');
            Route::delete('/push-tokens', [PushTokenController::class, 'destroy'])->name('push-tokens.destroy');

            Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
            Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
            Route::get('/notification-preferences', [NotificationController::class, 'preferences'])->name('notification-preferences.index');
            Route::put('/notification-preferences', [NotificationController::class, 'updatePreferences'])->name('notification-preferences.update');

            Route::get('/realtime/config', [RealtimeController::class, 'config'])->name('realtime.config');
            Route::post('/realtime/auth', [RealtimeController::class, 'authorizeChannel'])->name('realtime.auth');
            Route::post('/realtime/heartbeat', [RealtimeController::class, 'heartbeat'])->name('realtime.heartbeat');
        });
    });
