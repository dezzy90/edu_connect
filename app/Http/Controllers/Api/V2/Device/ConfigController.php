<?php

namespace App\Http\Controllers\Api\V2\Device;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'service' => 'edu-connect-device',
                'api_version' => config('educonnect.api_version'),
                'mqtt' => [
                    'recognition_topic' => config('mqtt.topics.recognition'),
                    'heartbeat_topic' => config('mqtt.topics.heartbeat'),
                    'basic_topic' => config('mqtt.topics.basic'),
                    'command_topic_pattern' => config('mqtt.device_command_topic'),
                ],
                'attendance' => [
                    'store_biometric_photos' => config('educonnect.attendance.store_biometric_photos'),
                    'photo_retention_days' => config('educonnect.attendance.photo_retention_days'),
                ],
            ],
        ]);
    }
}
