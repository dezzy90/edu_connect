<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Biometrics\Gate\AccountInvitations;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

final class GateAccountController extends Controller
{
    private function headers(): array
    {
        return ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
            'X-Content-Type-Options' => 'nosniff'];
    }

    public function show(Request $request, string $invitation, string $token, AccountInvitations $service)
    {
        $valid = $service->inspect($invitation, $token);

        return response()->view('gate-account', ['invitation' => $valid, 'completed' => false], $valid ? 200 : 404, $this->headers());
    }

    public function store(Request $request, string $invitation, string $token, AccountInvitations $service)
    {
        abort_if($request->user('admin') !== null, 409, 'Sign out before activating a different account.');
        if ($service->inspect($invitation, $token) === null) {
            return response()->view('gate-account', ['invitation' => null, 'completed' => false], 404, $this->headers());
        }
        $input = $request->validate(['password' => ['required', 'string', 'max:128', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()]]);
        $service->complete($invitation, $token, $input['password']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->view('gate-account', ['invitation' => null, 'completed' => true], 200, $this->headers());
    }
}
