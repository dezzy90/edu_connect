<?php

use App\Http\Controllers\Api\V2\Integration\EduAdminStatusController;
use Illuminate\Support\Facades\Route;

Route::prefix('integrations/v2')
    ->name('api.integrations.v2.')
    ->group(function () {
        Route::get('/edu-admin/status', EduAdminStatusController::class)->name('edu-admin.status');
    });
