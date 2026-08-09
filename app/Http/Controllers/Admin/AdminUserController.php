<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminUserController extends Controller
{
    /**
     * Display a listing of admin users.
     */
    public function index(Request $request)
    {
        // Only super admins can access
        if (!Auth::guard("admin")->user()->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        $query = AdminUser::with('school')
            ->when($request->search, function($query, $search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->role, function($query, $role) {
                $query->where('role', $role);
            })
            ->when($request->school_id, function($query, $schoolId) {
                $query->where('school_id', $schoolId);
            })
            ->when($request->status, function($query, $status) {
                if ($status === 'active') {
                    $query->where('is_active', true);
                } elseif ($status === 'inactive') {
                    $query->where('is_active', false);
                }
            });

        $adminUsers = $query->latest()->paginate(15);

        // Get filter options
        $schools = School::select('id', 'name')->get();

        return Inertia::render('Admin/AdminUsers/Index', [
            'admins' => $adminUsers,
            'schools' => $schools,
            'filters' => $request->only(['search', 'role', 'school_id', 'status']),
            'admin' => Auth::guard('admin')->user(),
        ]);
    }

    /**
     * Show the form for creating a new admin user.
     */
    public function create()
    {
        if (!Auth::guard("admin")->user()->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        $schools = School::select('id', 'name')->get();

        return Inertia::render('Admin/AdminUsers/Create', [
            'schools' => $schools,
        ]);
    }

    /**
     * Store a newly created admin user.
     */
    public function store(Request $request)
    {
        if (!Auth::guard("admin")->user()->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:admin_users',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => 'required|in:super_admin,school_admin',
            'school_id' => 'required_if:role,school_admin|nullable|exists:schools,id',
            'phone' => 'nullable|string|max:20',
        ]);

        $adminUser = AdminUser::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'school_id' => $validated['role'] === 'school_admin' ? $validated['school_id'] : null,
            'phone' => $validated['phone'],
            'is_active' => true,
        ]);

        return redirect()->route('admin.admin-users.show', $adminUser)
            ->with('success', 'Admin user created successfully!');
    }

    /**
     * Display the specified admin user.
     */
    public function show(AdminUser $adminUser)
    {
        if (!Auth::guard("admin")->user()->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        $adminUser->load(['school']);

        // Get activity statistics
        $stats = [
            'last_login' => $adminUser->last_login_at,
            'account_age' => $adminUser->created_at->diffForHumans(),
            'role_display' => ucfirst(str_replace('_', ' ', $adminUser->role)),
            'status' => $adminUser->is_active ? 'Active' : 'Inactive',
        ];

        return Inertia::render('Admin/AdminUsers/Show', [
            'adminUser' => $adminUser,
            'stats' => $stats,
        ]);
    }

    /**
     * Show the form for editing the specified admin user.
     */
    public function edit(AdminUser $adminUser)
    {
        if (!Auth::guard("admin")->user()->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        $schools = School::select('id', 'name')->get();

        return Inertia::render('Admin/AdminUsers/Edit', [
            'adminUser' => $adminUser->load('school'),
            'schools' => $schools,
        ]);
    }

    /**
     * Update the specified admin user.
     */
    public function update(Request $request, AdminUser $adminUser)
    {
        if (!Auth::guard("admin")->user()->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('admin_users')->ignore($adminUser->id)],
            'role' => 'required|in:super_admin,school_admin',
            'school_id' => 'required_if:role,school_admin|nullable|exists:schools,id',
            'phone' => 'nullable|string|max:20',
            'is_active' => 'boolean',
        ]);

        $adminUser->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'school_id' => $validated['role'] === 'school_admin' ? $validated['school_id'] : null,
            'phone' => $validated['phone'],
            'is_active' => $validated['is_active'] ?? $adminUser->is_active,
        ]);

        return redirect()->route('admin.admin-users.show', $adminUser)
            ->with('success', 'Admin user updated successfully!');
    }

    /**
     * Remove the specified admin user.
     */
    public function destroy(AdminUser $adminUser)
    {
        if (!Auth::guard("admin")->user()->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        // Prevent deleting self
        if ($adminUser->id === Auth::guard('admin')->id()) {
            return back()->withErrors(['error' => 'You cannot delete your own account.']);
        }

        // Prevent deleting the last super admin
        if ($adminUser->role === "super_admin" && AdminUser::where('role', 'super_admin')->count() <= 1) {
            return back()->withErrors(['error' => 'Cannot delete the last super admin.']);
        }

        $adminUser->delete();

        return redirect()->route('admin.admin-users.index')
            ->with('success', 'Admin user deleted successfully!');
    }

    /**
     * Toggle admin user status.
     */
    public function toggleStatus(AdminUser $adminUser)
    {
        if (!Auth::guard("admin")->user()->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        // Prevent deactivating self
        if ($adminUser->id === Auth::guard('admin')->id() && $adminUser->is_active) {
            return back()->withErrors(['error' => 'You cannot deactivate your own account.']);
        }

        $adminUser->update([
            'is_active' => !$adminUser->is_active
        ]);

        $status = $adminUser->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Admin user has been {$status} successfully!");
    }

    /**
     * Reset admin user password.
     */
    public function resetPassword(Request $request, AdminUser $adminUser)
    {
        if (!Auth::guard("admin")->user()->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $adminUser->update([
            'password' => Hash::make($validated['password'])
        ]);

        return back()->with('success', 'Password reset successfully!');
    }
}

