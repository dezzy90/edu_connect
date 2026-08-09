<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Student;
use App\Models\BiometricDevice;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Illuminate\Support\Str;

class SchoolControllerUtf8 extends Controller
{
    /**
     * Display a listing of schools.
     */
    public function index(Request $request)
    {
        // Only super admins can access
        if (Auth::guard("admin")->user()->role !== "super_admin") {
            abort(403, 'Access denied.');
        }

        $query = School::withCount(['students', 'biometricDevices'])
            ->with(['adminUsers' => function($query) {
                $query->where('is_active', true)->select('id', 'name', 'email', 'school_id', 'role');
            }]);

        // Apply search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $schools = $query->latest()->paginate(10)->withQueryString();

        return Inertia::render('Admin/Schools/Index', [
            'schools' => $schools,
            'admin' => Auth::guard('admin')->user(),
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Show the form for creating a new school.
     */
    public function create()
    {
        if (Auth::guard("admin")->user()->role !== "super_admin") {
            abort(403, 'Access denied.');
        }

        // Generate a unique school code
        $code = $this->generateUniqueCode();

        return Inertia::render('Admin/Schools/Create', [
            'generatedCode' => $code,
            'timezones' => $this->getTimezones(),
            'admin' => Auth::guard('admin')->user(),
        ]);
    }

    /**
     * Store a newly created school.
     */
    public function store(Request $request)
    {
        if (Auth::guard("admin")->user()->role !== "super_admin") {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:schools',
            'code' => 'required|string|max:255|unique:schools',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255|unique:schools',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'timezone' => 'required|string|max:255',
            'is_active' => 'boolean',
            'subscription_expires_at' => 'nullable|date',
        ]);

        // Handle logo upload
        if ($request->hasFile('logo')) {
            $validated['logo'] = $request->file('logo')->store('schools/logos', 'public');
        }

        $school = School::create($validated);

        return redirect()->route('admin.schools.index')
            ->with('success', 'School created successfully!');
    }

    /**
     * Display the specified school.
     */
    public function show(School $school)
    {
        if (Auth::guard("admin")->user()->role !== "super_admin") {
            abort(403, 'Access denied.');
        }

        $school->load([
            'students' => function($query) {
                $query->select('id', 'school_id', 'first_name', 'last_name', 'student_number', 'is_active', 'created_at')
                      ->latest()
                      ->limit(10);
            },
            'biometricDevices' => function($query) {
                $query->select('id', 'school_id', 'name', 'device_id', 'is_active', 'last_heartbeat')
                      ->latest()
                      ->limit(10);
            },
            'adminUsers' => function($query) {
                $query->select('id', 'name', 'email', 'school_id', 'role', 'is_active', 'last_login_at')
                      ->where('is_active', true);
            }
        ]);

        // Get recent activity for this school
        $recentActivity = $school->studentLogs()
            ->with(['student:id,first_name,last_name,student_number', 'biometricDevice:id,name'])
            ->latest()
            ->limit(20)
            ->get();

        // Get stats
        $stats = [
            'total_students' => $school->students()->count(),
            'active_students' => $school->students()->where('is_active', true)->count(),
            'total_devices' => $school->biometricDevices()->count(),
            'active_devices' => $school->biometricDevices()->where('is_active', true)->count(),
            'online_devices' => $school->biometricDevices()->where('last_heartbeat', '>=', now()->subMinutes(5))->count(),
            'admin_users' => $school->adminUsers()->where('is_active', true)->count(),
            'total_logs_today' => $school->studentLogs()->whereDate('student_logs.created_at', today())->count(),
        ];

        return Inertia::render('Admin/Schools/Show', [
            'school' => $school,
            'stats' => $stats,
            'recentActivity' => $recentActivity,
            'admin' => Auth::guard('admin')->user(),
        ]);
    }

    /**
     * Show the form for editing the specified school.
     */
    public function edit(School $school)
    {
        if (Auth::guard("admin")->user()->role !== "super_admin") {
            abort(403, 'Access denied.');
        }

        return Inertia::render('Admin/Schools/Edit', [
            'school' => $school,
            'timezones' => $this->getTimezones(),
            'admin' => Auth::guard('admin')->user(),
        ]);
    }

    /**
     * Update the specified school.
     */
    public function update(Request $request, School $school)
    {
        if (Auth::guard("admin")->user()->role !== "super_admin") {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('schools')->ignore($school->id)],
            'code' => ['required', 'string', 'max:255', Rule::unique('schools')->ignore($school->id)],
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('schools')->ignore($school->id)],
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'timezone' => 'required|string|max:255',
            'is_active' => 'boolean',
            'subscription_expires_at' => 'nullable|date',
        ]);

        // Handle logo upload
        if ($request->hasFile('logo')) {
            // Delete old logo if exists
            if ($school->logo) {
                Storage::disk('public')->delete($school->logo);
            }
            $validated['logo'] = $request->file('logo')->store('schools/logos', 'public');
        }

        $school->update($validated);

        return redirect()->route('admin.schools.show', $school)
            ->with('success', 'School updated successfully!');
    }

    /**
     * Remove the specified school.
     */
    public function destroy(School $school)
    {
        if (Auth::guard("admin")->user()->role !== "super_admin") {
            abort(403, 'Access denied.');
        }

        // Check if school has students or devices
        if ($school->students()->count() > 0) {
            return back()->withErrors(['error' => 'Cannot delete school with existing students. Please remove or transfer students first.']);
        }

        if ($school->biometricDevices()->count() > 0) {
            return back()->withErrors(['error' => 'Cannot delete school with existing devices. Please remove devices first.']);
        }

        // Delete logo if exists
        if ($school->logo) {
            Storage::disk('public')->delete($school->logo);
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
        if (Auth::guard("admin")->user()->role !== "super_admin") {
            abort(403, 'Access denied.');
        }

        $school->update([
            'is_active' => !$school->is_active
        ]);

        $status = $school->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "School has been {$status} successfully!");
    }

    /**
     * Generate a unique school code.
     */
    private function generateUniqueCode(): string
    {
        do {
            $code = 'SCH-' . strtoupper(Str::random(6));
        } while (School::where('code', $code)->exists());

        return $code;
    }

    /**
     * Get list of common timezones.
     */
    private function getTimezones(): array
    {
        return [
            'UTC' => 'UTC',
            'Africa/Douala' => 'Africa/Douala (Cameroon)',
            'Africa/Lagos' => 'Africa/Lagos (Nigeria)',
            'Africa/Nairobi' => 'Africa/Nairobi (Kenya)',
            'Africa/Johannesburg' => 'Africa/Johannesburg (South Africa)',
            'Africa/Cairo' => 'Africa/Cairo (Egypt)',
            'Europe/London' => 'Europe/London (UK)',
            'Europe/Paris' => 'Europe/Paris (France)',
            'America/New_York' => 'America/New_York (US Eastern)',
            'America/Los_Angeles' => 'America/Los_Angeles (US Pacific)',
            'Asia/Dubai' => 'Asia/Dubai (UAE)',
            'Asia/Tokyo' => 'Asia/Tokyo (Japan)',
        ];
    }
}
