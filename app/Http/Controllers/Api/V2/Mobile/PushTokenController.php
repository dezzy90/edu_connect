<?php

namespace App\Http\Controllers\Api\V2\Mobile;

use App\Http\Controllers\Controller;
use App\Models\V2\MobilePushToken;
use App\Models\V2\ParentAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PushTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /** @var ParentAccount $parent */
        $parent = $request->user();

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'provider' => ['required', 'string', Rule::in(['fcm', 'apns', 'expo'])],
            'platform' => ['required', 'string', Rule::in(['android', 'ios', 'web'])],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:60'],
            'locale' => ['nullable', 'string', 'max:20'],
            'timezone' => ['nullable', 'string', 'max:80'],
        ]);

        $token = MobilePushToken::query()->updateOrCreate(
            [
                'provider' => $validated['provider'],
                'token' => $validated['token'],
            ],
            [
                'parent_account_id' => $parent->id,
                'platform' => $validated['platform'],
                'device_name' => $validated['device_name'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'locale' => $validated['locale'] ?? null,
                'timezone' => $validated['timezone'] ?? null,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ],
        );

        return response()->json([
            'status' => 'success',
            'data' => $this->payload($token),
        ], $token->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var ParentAccount $parent */
        $parent = $request->user();

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'provider' => ['required', 'string', Rule::in(['fcm', 'apns', 'expo'])],
        ]);

        MobilePushToken::query()
            ->where('parent_account_id', $parent->id)
            ->where('provider', $validated['provider'])
            ->where('token', $validated['token'])
            ->update(['revoked_at' => now()]);

        return response()->json([
            'status' => 'success',
            'data' => null,
        ]);
    }

    private function payload(MobilePushToken $token): array
    {
        return [
            'id' => $token->id,
            'provider' => $token->provider,
            'platform' => $token->platform,
            'device_name' => $token->device_name,
            'app_version' => $token->app_version,
            'locale' => $token->locale,
            'timezone' => $token->timezone,
            'last_seen_at' => $token->last_seen_at?->toIso8601String(),
            'revoked_at' => $token->revoked_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
            'updated_at' => $token->updated_at?->toIso8601String(),
        ];
    }
}
