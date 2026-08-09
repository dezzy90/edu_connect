<?php

namespace App\Http\Controllers\Api\V2\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'service' => 'edu-connect-mobile',
                'api_version' => config('educonnect.api_version'),
                'mode' => config('educonnect.mode'),
                'features' => config('integrations.feature_defaults.' . config('educonnect.mode'), []),
                'push' => [
                    'enabled' => (bool) data_get(config('integrations.feature_defaults.' . config('educonnect.mode')), 'push_notifications', false),
                    'provider' => config('educonnect.notifications.push_provider'),
                    'privacy_mode' => config('educonnect.notifications.privacy_mode'),
                ],
                'realtime' => [
                    'enabled' => config('educonnect.realtime.enabled'),
                    'driver' => config('educonnect.realtime.driver'),
                ],
            ],
        ]);
    }
}
