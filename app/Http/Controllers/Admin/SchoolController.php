<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Student;
use App\Models\BiometricDevice;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SchoolController extends Controller
{
    /**
     * Display a listing of schools.
     */
    public function index()
    {
        // Only super admins can access
        if (Auth::guard("admin")->user()->role !== "super_admin") {
            abort(403, 'Access denied.');
        }

        $schools = School::withCount(['students', 'biometricDevices'])
            ->with(['adminUsers' => function($query) {
                $query->where('is_active', true)->select('id', 'name', 'email', 'school_id', 'role');
            }])
            ->paginate(10);

        return Inertia::render('Admin/Schools/Index', [
            'schools' => $schools,
            'admin' => Auth::guard('admin')->user(),
        ]);
    }

    /**
     * Show the form for creating a new school.
     */
    public function create()
    {
        if (!Auth::guard("admin")->user()->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        return Inertia::render('Admin/Schools/Create');
    }

    /**
     * Store a newly created school.
     */
    public function store(Request $request)
    {
        if (!Auth::guard("admin")->user()->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:schools',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'director_name' => 'nullable|string|max:255',
            'website' => 'nullable|url|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $school = School::create($validated);

        return redirect()->route('admin.schools.index')
            ->with('success', 'School created successfully!');
    }

    /**
     * Display the specified school.
     */
    public function show(School $school)
    {
        if (!Auth::guard("admin")->user()->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        $school->load([
            'students:id,school_id,first_name,last_name,student_id,created_at',
            'biometricDevices:id,school_id,name,device_id,is_active,last_heartbeat',
            'adminUsers:id,name,email,school_id,role,is_active,last_login_at'
        ]);

        // Get recent activity for this school
        $recentActivity = Student::where('school_id', $school->id)
            ->with(['studentLogs' => function($query) {
                $query->latest()->limit(10)->with('biometricDevice:id,name');
            }])
            ->get()
            ->pluck('studentLogs')
            ->flatten()
            ->sortByDesc('created_at')
            ->take(10);

        // Get stats
        $stats = [
            'total_students' => $school->students->count(),
            'active_devices' => $school->biometricDevices->where('is_active', true)->count(),
            'online_devices' => $school->biometricDevices->where('last_heartbeat', '>=', now()->subMinutes(5))->count(),
            'admin_users' => $school->adminUsers->where('is_active', true)->count(),
        ];

        return Inertia::render('Admin/Schools/Show', [
            'school' => $school,
            'stats' => $stats,
            'recentActivity' => $recentActivity,
        ]);
    }

    /**
     * Show the form for editing the specified school.
     */
    public function edit(School $school)
    {
        if (!Auth::guard("admin")->user()->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        return Inertia::render('Admin/Schools/Edit', [
            'school' => $school,
        ]);
    }

    /**
     * Update the specified school.
     */
    public function update(Request $request, School $school)
    {
        if (!Auth::guard("admin")->user()->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('schools')->ignore($school->id)],
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'director_name' => 'nullable|string|max:255',
            'website' => 'nullable|url|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $school->update($validated);

        return redirect()->route('admin.schools.show', $school)
            ->with('success', 'School updated successfully!');
    }

    /**
     * Remove the specified school.
     */
    public function destroy(School $school)
    {
        if (!Auth::guard("admin")->user()->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        // Check if school has students or devices
        if ($school->students()->count() > 0) {
            return back()->withErrors(['error' => 'Cannot delete school with existing students.']);
        }

        if ($school->biometricDevices()->count() > 0) {
            return back()->withErrors(['error' => 'Cannot delete school with existing devices.']);
        }

        // Delete associated admin users
        $school->adminUsers()->delete();
        
        $school->delete();

        return redirect()->route('admin.schools.index')
            ->with('success', 'School deleted successfully!');
    }

    /**
     * Toggle school status (active/inactive).
     */
    public function toggleStatus(School $school)
    {
        if (!Auth::guard("admin")->user()->role === "super_admin") {
            abort(403, 'Access denied.');
        }

        $school->update([
            'is_active' => !$school->is_active
        ]);

        $status = $school->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "School has been {$status} successfully!");
    }
}

