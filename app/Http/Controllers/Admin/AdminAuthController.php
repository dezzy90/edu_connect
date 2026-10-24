<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class AdminAuthController extends Controller
{
    /**
     * Display the login form.
     */
    public function showLogin()
    {
        return Inertia::render('Admin/Auth/Login');
    }

    /**
     * Handle admin login request.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $adminUser = AdminUser::where('email', $credentials['email'])
            ->where('is_active', true)
            ->first();

        if ($adminUser && ! $adminUser->gate_source_only && Hash::check($credentials['password'], $adminUser->password)) {
            Auth::guard('admin')->login($adminUser, $request->boolean('remember'));

            // Update last login timestamp
            $adminUser->update(['last_login_at' => now()]);

            $request->session()->regenerate();

            return redirect()->intended('/admin/dashboard');
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records or account is inactive.',
        ])->onlyInput('email');
    }

    /**
     * Handle admin logout request.
     */
    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/admin/login');
    }

    /**
     * Public administrator registration is permanently closed.
     */
    public function showRegister()
    {
        abort(403, 'Public administrator registration is disabled.');
    }

    /**
     * Keep cached legacy routes closed before validation or account creation.
     */
    public function register(Request $request)
    {
        abort(403, 'Public administrator registration is disabled.');
    }
}
