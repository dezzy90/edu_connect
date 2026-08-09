<?php

use App\Http\Controllers\Api\V2\Admin\AuthController;
use App\Http\Controllers\Api\V2\Admin\DashboardController;
use App\Http\Controllers\Api\V2\Admin\FoundationController;
use App\Http\Controllers\Api\V2\Admin\ConversationController;
use App\Http\Controllers\Api\V2\Admin\IntegrationConnectionController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/v2')
    ->name('api.admin.v2.')
    ->group(function () {
        Route::get('/foundation', FoundationController::class)->name('foundation');
        Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login');

        Route::middleware(['auth:sanctum', 'admin.api'])->group(function () {
            Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
            Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::get('/dashboard', DashboardController::class)->name('dashboard');

            Route::get('/integration-connections', [IntegrationConnectionController::class, 'index'])
                ->name('integration-connections.index');
            Route::post('/integration-connections', [IntegrationConnectionController::class, 'store'])
                ->name('integration-connections.store');
            Route::get('/integration-connections/{connection}', [IntegrationConnectionController::class, 'show'])
                ->name('integration-connections.show');
            Route::patch('/integration-connections/{connection}', [IntegrationConnectionController::class, 'update'])
                ->name('integration-connections.update');
            Route::delete('/integration-connections/{connection}', [IntegrationConnectionController::class, 'destroy'])
                ->name('integration-connections.destroy');
            Route::post('/integration-connections/{connection}/sync-initial', [IntegrationConnectionController::class, 'syncInitial'])
                ->name('integration-connections.sync-initial');
            Route::post('/integration-connections/{connection}/sync-incremental', [IntegrationConnectionController::class, 'syncIncremental'])
                ->name('integration-connections.sync-incremental');
            Route::get('/integration-connections/{connection}/sync-runs', [IntegrationConnectionController::class, 'syncRuns'])
                ->name('integration-connections.sync-runs');

            Route::get('/conversations', [ConversationController::class, 'index'])
                ->name('conversations.index');
            Route::get('/conversations/{thread}', [ConversationController::class, 'show'])
                ->name('conversations.show');
            Route::post('/conversations/{thread}/messages', [ConversationController::class, 'postMessage'])
                ->name('conversations.messages.store');
            Route::post('/conversations/{thread}/read', [ConversationController::class, 'markRead'])
                ->name('conversations.read');
            Route::patch('/conversations/{thread}/status', [ConversationController::class, 'updateStatus'])
                ->name('conversations.status.update');
        });
    });
