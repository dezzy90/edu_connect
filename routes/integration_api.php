<?php

use App\Http\Controllers\Api\V2\Integration\EduAdminStatusController;
use App\Http\Controllers\Api\V2\Integration\EduAdminWebhookController;
use App\Http\Middleware\EnsureEduAdminWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::prefix('integrations/v2')
    ->name('api.integrations.v2.')
    ->group(function () {
        Route::get('/edu-admin/status', EduAdminStatusController::class)->name('edu-admin.status');
        Route::post('/edu-admin/mobile-message-published', [EduAdminWebhookController::class, 'mobileMessagePublished'])
            ->middleware(EnsureEduAdminWebhookSignature::class)
            ->name('edu-admin.mobile-message-published');
    });
