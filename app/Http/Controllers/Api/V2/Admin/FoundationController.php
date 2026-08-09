<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class FoundationController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'service' => 'edu-connect-admin',
                'api_version' => config('educonnect.api_version'),
                'mode' => config('educonnect.mode'),
                'table_prefix' => config('educonnect.table_prefix'),
                'features' => config('integrations.feature_defaults.' . config('educonnect.mode'), []),
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }
}
