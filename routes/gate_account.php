<?php

use App\Http\Controllers\Admin\GateAccountController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/gate/account/activate')->name('admin.gate.account.')->group(function (): void {
    Route::get('/{invitation}/{token}', [GateAccountController::class, 'show'])->whereUuid('invitation')
        ->where('token', '[a-f0-9]{64}')->middleware('throttle:30,1')->name('show');
    Route::post('/{invitation}/{token}', [GateAccountController::class, 'store'])->whereUuid('invitation')
        ->where('token', '[a-f0-9]{64}')->middleware('throttle:10,1')->name('complete');
});
