<?php

namespace App\Http\Controllers\Api\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $admin = AdminUser::query()
            ->where('email', strtolower($validated['email']))
            ->first();

        if (!$admin || !Hash::check($validated['password'], $admin->password)) {
            throw ValidationException::withMessages([
                'email' => 'The supplied credentials are invalid.',
            ]);
        }

        if (!$admin->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'This admin account is inactive.',
            ], 403);
        }

        $admin->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'status' => 'success',
            'data' => $this->authPayload(
                $admin->refresh(),
                $validated['device_name'] ?? 'edu-connect-web',
                (bool) ($validated['remember'] ?? false)
            ),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();

        return response()->json([
            'status' => 'success',
            'data' => [
                'admin' => $this->adminPayload($admin),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var AdminUser $admin */
        $admin = $request->user();

        $admin->currentAccessToken()?->delete();

        return response()->json([
            'status' => 'success',
            'data' => null,
        ]);
    }

    private function authPayload(AdminUser $admin, string $deviceName, bool $remember): array
    {
        $expiresAt = $remember ? now()->addDays(30) : now()->addHours(12);
        $token = $admin->createToken($deviceName, ['admin:*'], $expiresAt);

        return [
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'admin' => $this->adminPayload($admin),
        ];
    }

    private function adminPayload(AdminUser $admin): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role,
            'school_id' => $admin->school_id,
            'phone' => $admin->phone,
            'avatar' => $admin->avatar,
            'is_active' => $admin->is_active,
            'last_login_at' => $admin->last_login_at?->toIso8601String(),
            'school' => $admin->relationLoaded('school') && $admin->school ? [
                'id' => $admin->school->id,
                'name' => $admin->school->name,
                'code' => $admin->school->code ?? null,
                'type' => $admin->school->type ?? null,
            ] : null,
        ];
    }
}
