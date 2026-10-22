<?php

use App\Http\Controllers\Api\V2\Mobile\GateHistoryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum', 'mobile.parent'])
    ->get('/api/mobile/v2/children/{student}/gate-history', GateHistoryController::class)
    ->whereNumber('student')->name('api.mobile.v2.children.gate-history');
