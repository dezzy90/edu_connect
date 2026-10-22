<?php

use App\Http\Controllers\Api\HikvisionPushController;
use Illuminate\Support\Facades\Route;

// The terminal expects this exact root path, not Laravel's /api prefix.
Route::post('/iot/{serial}/global/0-global/model/service/operate/PUSH/{operation}', HikvisionPushController::class)
    ->where('serial', '[A-Za-z0-9_-]{1,128}')
    ->where('operation', 'AuthInfo|Login|Event|CommandRequest|CommandResult|Logout')
    ->middleware('throttle:3000,1')
    ->name('hikvision.push');
