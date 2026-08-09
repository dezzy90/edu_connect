<?php

use App\Http\Controllers\Api\V2\Device\ConfigController;
use Illuminate\Support\Facades\Route;

Route::prefix('device/v2')
    ->name('api.device.v2.')
    ->group(function () {
        Route::get('/config', ConfigController::class)->name('config');
    });
