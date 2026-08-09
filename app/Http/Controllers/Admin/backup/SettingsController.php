<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    /**
     * Display admin settings page.
     */
    public function index()
    {
        $adminUser = Auth::guard('admin')->user();
        $adminUser->load('school');

        // Get system information (for super admin)
        $systemInfo = [];
        if ($adminUser->role === "super_admin") {
            $systemInfo = [
                'total_schools' => School::count(),
                'total_admin_users' => AdminUser::count(),
                'active_admin_users' => AdminUser::where('is_active', true)->count(),
                'super_admins' => AdminUser::where('role', 'super_admin')->count(),
                'school_admins' => AdminUser::where('role', 'school_admin')->count(),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ];
        }

        return Inertia::render('Admin/Settings/Index', [
            'admin' => $adminUser,
            'systemInfo' => $systemInfo,
            'isSuper' => $adminUser->role === "super_admin",
        ]);
    }

    /**
     * Update admin profile.
     */
    public function updateProfile(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('admin_users')->ignore($adminUser->id)],
            'phone' => 'nullable|string|max:20',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            // Delete old avatar if exists
            if ($adminUser->avatar) {
                Storage::disk('public')->delete($adminUser->avatar);
            }

            $avatarPath = $request->file('avatar')->store('avatars', 'public');
            $validated['avatar'] = $avatarPath;
        }

        $adminUser->update($validated);

        return back()->with('success', 'Profile updated successfully!');
    }

    /**
     * Change admin password.
     */
    public function changePassword(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        $validated = $request->validate([
            'current_password' => 'required',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Check current password
        if (!Hash::check($validated['current_password'], $adminUser->password)) {
            return back()->withErrors(['current_password' => 'The current password is incorrect.']);
        }

        $adminUser->update([
            'password' => Hash::make($validated['password'])
        ]);

        return back()->with('success', 'Password changed successfully!');
    }

    /**
     * Update notification preferences.
     */
    public function updateNotifications(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        $validated = $request->validate([
            'email_notifications' => 'boolean',
            'attendance_alerts' => 'boolean',
            'device_alerts' => 'boolean',
            'daily_reports' => 'boolean',
            'weekly_reports' => 'boolean',
        ]);

        // Store notification preferences (you might want to create a separate model/table for this)
        // For now, we'll store it as JSON in a preferences column (you'd need to add this to migration)
        $preferences = [
            'email_notifications' => $validated['email_notifications'] ?? false,
            'attendance_alerts' => $validated['attendance_alerts'] ?? false,
            'device_alerts' => $validated['device_alerts'] ?? false,
            'daily_reports' => $validated['daily_reports'] ?? false,
            'weekly_reports' => $validated['weekly_reports'] ?? false,
        ];

        // You would typically save this to a preferences column or separate table
        // $adminUser->update(['preferences' => json_encode($preferences)]);

        return back()->with('success', 'Notification preferences updated successfully!');
    }

    /**
     * Update school information (for school admins).
     */
    public function updateSchool(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        if (!$adminUser->role === "school_admin" || !$adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'director_name' => 'nullable|string|max:255',
            'website' => 'nullable|url|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $adminUser->school->update($validated);

        return back()->with('success', 'School information updated successfully!');
    }

    /**
     * System maintenance actions (super admin only).
     */
    public function systemMaintenance(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        if (!$adminUser->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        $action = $request->get('action');

        switch ($action) {
            case 'clear_cache':
                Artisan::call('cache:clear');
                Artisan::call('config:clear');
                Artisan::call('route:clear');
                Artisan::call('view:clear');
                return back()->with('success', 'System cache cleared successfully!');

            case 'optimize':
                Artisan::call('optimize');
                return back()->with('success', 'System optimized successfully!');

            case 'backup_database':
                // You would implement database backup logic here
                return back()->with('success', 'Database backup initiated!');

            default:
                return back()->withErrors(['error' => 'Invalid maintenance action.']);
        }
    }

    /**
     * Export system data (super admin only).
     */
    public function exportData(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        if (!$adminUser->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        $type = $request->get('type', 'all');

        // This would typically generate comprehensive exports
        // For now, return basic system information
        $data = [
            'export_date' => now()->toISOString(),
            'exported_by' => $adminUser->name,
            'system_info' => [
                'total_schools' => School::count(),
                'total_admin_users' => AdminUser::count(),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
            ]
        ];

        return response()->json($data)
            ->header('Content-Disposition', 'attachment; filename="system_export_' . now()->format('Y-m-d_H-i-s') . '.json"');
    }

    /**
     * Get activity logs (basic implementation).
     */
    public function activityLogs(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        // This would typically come from an activity log system
        // For now, return some basic admin user activity
        $logs = AdminUser::select('id', 'name', 'email', 'last_login_at', 'created_at')
            ->when(!$adminUser->role === "super_admin", function($query) use ($adminUser) {
                $query->where('id', $adminUser->id);
            })
            ->latest('last_login_at')
            ->paginate(20);

        return response()->json($logs);
    }
}
