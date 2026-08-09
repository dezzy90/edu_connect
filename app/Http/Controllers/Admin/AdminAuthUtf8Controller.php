<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;

class AdminAuthUtf8Controller extends Controller
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

        if ($adminUser && Hash::check($credentials['password'], $adminUser->password)) {
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
     * Display the registration form (only for super admins or if none exists).
     */
    public function showRegister()
    {
        // Only allow access if user is super admin or no super admin exists
        $currentAdmin = Auth::guard('admin')->user();
        $superAdminExists = AdminUser::where('role', 'super_admin')->exists();

        if (!$superAdminExists || ($currentAdmin && $currentAdmin->role === "super_admin")) {
            return Inertia::render('Admin/Auth/Register');
        }

        abort(403, 'Unauthorized to create admin accounts.');
    }

    /**
     * Handle admin registration request.
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:admin_users',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => 'required|in:super_admin,school_admin',
            'school_id' => 'required_if:role,school_admin|nullable|exists:schools,id',
        ]);

        // Check if user can create this type of admin
        $currentAdmin = Auth::guard('admin')->user();
        $superAdminExists = AdminUser::where('role', 'super_admin')->exists();

        // If no super admin exists, allow creation of first super admin
        if (!$superAdminExists && $request->role === 'super_admin') {
            // Allow first super admin creation
        } elseif ($currentAdmin && $currentAdmin->role === "super_admin") {
            // Super admin can create any type of admin
        } else {
            abort(403, 'Unauthorized to create admin accounts.');
        }

        $adminUser = AdminUser::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'school_id' => $request->role === 'school_admin' ? $request->school_id : null,
            'is_active' => true,
        ]);

        if (!$superAdminExists) {
            // Auto-login the first super admin
            Auth::guard('admin')->login($adminUser);
            return redirect('/admin/dashboard');
        }

        return redirect('/admin/login')->with('success', 'Admin account created successfully!');
    }
}
