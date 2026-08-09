<?php

namespace App\Http\Controllers\Api\V2\Integration;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class EduAdminStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'provider' => 'edu_admin',
                'api_version' => config('integrations.providers.edu_admin.api_version'),
                'mode' => config('educonnect.mode'),
                'supported_features' => config('integrations.feature_defaults.connected'),
                'sync' => config('integrations.sync'),
            ],
        ]);
    }
}
