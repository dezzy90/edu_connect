<?php

use App\Http\Controllers\Api\GateSourceWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::post('/api/integrations/edu-admin/gate-workspace', GateSourceWorkspaceController::class)
    ->middleware(['api', 'throttle:120,1']);
