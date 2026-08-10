<?php

namespace App\Http\Controllers\Api\V2\Mobile;

use App\Http\Controllers\Controller;
use App\Models\V2\ParentAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'preferred_language' => ['nullable', 'string', 'max:10'],
            'region' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:1000'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $phone = $this->normalizePhone($validated['phone']);
        $email = isset($validated['email']) ? strtolower($validated['email']) : null;

        $existing = ParentAccount::query()
            ->where('phone', $phone)
            ->when($email, fn ($query) => $query->orWhere('email', $email))
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'phone' => 'A parent account already exists for this phone or email.',
            ]);
        }

        $parent = ParentAccount::query()->create([
            'phone' => $phone,
            'email' => $email,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'preferred_language' => $validated['preferred_language'] ?? config('educonnect.mobile.default_locale', 'en'),
            'region' => $validated['region'] ?? null,
            'address' => $validated['address'] ?? null,
            'status' => 'active',
            'password_hash' => Hash::make($validated['password']),
            'settings' => [],
            'last_login_at' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Parent account created.',
            'data' => $this->authPayload($parent, $validated['device_name'] ?? 'mobile-app'),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => ['required_without:phone', 'nullable', 'string', 'max:255'],
            'phone' => ['required_without:identifier', 'nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $identifier = $validated['phone'] ?? $validated['identifier'];
        $parent = $this->findParentByIdentifier((string) $identifier);

        if (!$parent || !$parent->password_hash || !Hash::check($validated['password'], $parent->password_hash)) {
            throw ValidationException::withMessages([
                'identifier' => 'The supplied credentials are invalid.',
            ]);
        }

        if ($parent->status !== 'active') {
            return response()->json([
                'status' => 'error',
                'message' => 'This parent account is not active.',
            ], 403);
        }

        $parent->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'status' => 'success',
            'data' => $this->authPayload($parent, $validated['device_name'] ?? 'mobile-app'),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        $parent = $this->findParentByIdentifier((string) $validated['identifier']);

        if ($parent) {
            $parent->forceFill([
                'settings' => array_merge($parent->settings ?? [], [
                    'password_help_requested_at' => now()->toIso8601String(),
                ]),
            ])->save();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'If this parent account exists, the school can verify you and help reset the password.',
            'data' => null,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var ParentAccount $parent */
        $parent = $request->user();

        return response()->json([
            'status' => 'success',
            'data' => [
                'parent' => $this->parentPayload($parent->loadCount([
                    'studentLinks as active_children_count' => fn ($query) => $query->where('status', 'active'),
                ])),
            ],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        /** @var ParentAccount $parent */
        $parent = $request->user();

        $validated = $request->validate([
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('ec_parent_accounts', 'email')->ignore($parent->id)],
            'first_name' => ['sometimes', 'string', 'max:120'],
            'last_name' => ['sometimes', 'string', 'max:120'],
            'preferred_language' => ['sometimes', 'string', 'max:10'],
            'region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'settings' => ['sometimes', 'array'],
        ]);

        if (array_key_exists('email', $validated) && $validated['email']) {
            $validated['email'] = strtolower($validated['email']);
        }

        $parent->fill($validated)->save();

        return response()->json([
            'status' => 'success',
            'data' => [
                'parent' => $this->parentPayload($parent->refresh()),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var ParentAccount $parent */
        $parent = $request->user();

        $parent->currentAccessToken()?->delete();

        return response()->json([
            'status' => 'success',
            'data' => null,
        ]);
    }

    private function authPayload(ParentAccount $parent, string $deviceName): array
    {
        $expiresAt = now()->addMinutes((int) config('educonnect.mobile.token_expiration_minutes', 43200));
        $token = $parent->createToken($deviceName, ['mobile:parent'], $expiresAt);

        return [
            'token_type' => 'Bearer',
            'access_token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'parent' => $this->parentPayload($parent),
        ];
    }

    private function parentPayload(ParentAccount $parent): array
    {
        return [
            'id' => $parent->id,
            'phone' => $parent->phone,
            'email' => $parent->email,
            'first_name' => $parent->first_name,
            'last_name' => $parent->last_name,
            'full_name' => $parent->full_name,
            'preferred_language' => $parent->preferred_language,
            'region' => $parent->region,
            'address' => $parent->address,
            'status' => $parent->status,
            'phone_verified_at' => $parent->phone_verified_at?->toIso8601String(),
            'email_verified_at' => $parent->email_verified_at?->toIso8601String(),
            'last_login_at' => $parent->last_login_at?->toIso8601String(),
            'active_children_count' => $parent->active_children_count ?? null,
            'settings' => $parent->settings ?? [],
        ];
    }

    private function findParentByIdentifier(string $identifier): ?ParentAccount
    {
        $identifier = trim($identifier);

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return ParentAccount::query()->where('email', strtolower($identifier))->first();
        }

        return ParentAccount::query()->where('phone', $this->normalizePhone($identifier))->first();
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^\d+]/', '', trim($phone)) ?: trim($phone);

        if (str_starts_with($phone, '00')) {
            return '+' . substr($phone, 2);
        }

        return $phone;
    }
}
