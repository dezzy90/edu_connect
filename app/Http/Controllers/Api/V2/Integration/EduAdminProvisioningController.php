<?php

namespace App\Http\Controllers\Api\V2\Integration;

use App\Http\Controllers\Controller;
use App\Services\Integration\EduAdminProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class EduAdminProvisioningController extends Controller
{
    public function __invoke(Request $request, EduAdminProvisioningService $provisioning): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['connect', 'disconnect'])],
            'request_id' => ['required', 'uuid'],
            'edu_admin_base_url' => ['required_if:action,connect', 'nullable', 'url:https'],
            'complex' => ['required', 'array'],
            'complex.id' => ['required', 'integer', 'min:1'],
            'complex.name' => ['required_if:action,connect', 'nullable', 'string', 'max:255'],
            'complex.slug' => ['nullable', 'string', 'max:255'],
            'complex.status' => ['nullable', 'string', 'max:40'],
            'school_ids' => ['required_if:action,connect', 'array', 'min:1'],
            'school_ids.*' => ['integer', 'min:1'],
            'scopes' => ['required_if:action,connect', 'array', 'min:1'],
            'scopes.*' => ['string', 'max:100'],
            'access_token' => ['required_if:action,connect', 'string', 'min:32'],
            'webhook_secret' => ['required_if:action,connect', 'string', 'min:32'],
        ]);

        try {
            if ($validated['action'] === 'disconnect') {
                $connection = $provisioning->disconnect((int) $validated['complex']['id']);

                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'status' => 'disconnected',
                        'connection_id' => $connection?->id,
                        'tenant_id' => $connection?->tenant_id,
                    ],
                ]);
            }

            $connection = $provisioning->connect($validated);
        } catch (RuntimeException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'status' => 'syncing',
                'connection_id' => $connection->id,
                'tenant_id' => $connection->tenant_id,
            ],
        ], 202);
    }
}
