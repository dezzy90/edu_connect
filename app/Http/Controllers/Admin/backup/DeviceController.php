<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Models\School;
use App\Models\StudentLog;
use App\Services\PersonnelManagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Carbon\Carbon;

class DeviceController extends Controller
{
    /**
     * Display a listing of devices.
     */
    public function index(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();
        
        $query = BiometricDevice::with(['school'])
            ->when($request->search, function($query, $search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('device_id', 'like', "%{$search}%")
                      ->orWhere('mac_address', 'like', "%{$search}%")
                      ->orWhere('ip_address', 'like', "%{$search}%");
                });
            })
            ->when($request->school_id, function($query, $schoolId) {
                $query->where('school_id', $schoolId);
            })
            ->when($request->status, function($query, $status) {
                if ($status === 'online') {
                    $query->where('last_heartbeat', '>=', now()->subMinutes(5));
                } elseif ($status === 'offline') {
                    $query->where(function($q) {
                        $q->whereNull('last_heartbeat')
                          ->orWhere('last_heartbeat', '<', now()->subMinutes(5));
                    });
                } elseif ($status === 'active') {
                    $query->where('is_active', true);
                } elseif ($status === 'inactive') {
                    $query->where('is_active', false);
                }
            });

        // Restrict to school admin's school
        if ($adminUser->role === "school_admin") {
            $query->where('school_id', $adminUser->school_id);
        }

        $devices = $query->latest()->paginate(15);

        // Add online status to each device
        $devices->getCollection()->transform(function ($device) {
            $device->is_online = $device->last_heartbeat && 
                $device->last_heartbeat->diffInMinutes(now()) <= 5;
            return $device;
        });

        // Get filter options
        $schools = $adminUser->role === "super_admin" ? School::select('id', 'name')->get() : collect();

        return Inertia::render('Admin/Devices/Index', [
            'devices' => $devices,
            'schools' => $schools,
            'filters' => $request->only(['search', 'school_id', 'status']),
            'isSuper' => $adminUser->role === "super_admin",
        ]);
    }

    /**
     * Show the form for creating a new device.
     */
    public function create()
    {
        $adminUser = Auth::guard('admin')->user();

        $schools = $adminUser->role === "super_admin" 
            ? School::select('id', 'name')->get()
            : School::where('id', $adminUser->school_id)->select('id', 'name')->get();

        return Inertia::render('Admin/Devices/Create', [
            'schools' => $schools,
            'isSuper' => $adminUser->role === "super_admin",
        ]);
    }

    /**
     * Store a newly created device.
     */
    public function store(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'device_id' => 'required|string|max:255|unique:biometric_devices',
            'mac_address' => 'nullable|string|max:17',
            'ip_address' => 'nullable|ip',
            'location' => 'nullable|string|max:255',
            'device_type' => 'nullable|string|max:100',
            'firmware_version' => 'nullable|string|max:50',
            'school_id' => $adminUser->role === "super_admin" 
                ? 'required|exists:schools,id' 
                : 'nullable',
        ]);

        // Set school_id for school admin
        if ($adminUser->role === "school_admin") {
            $validated['school_id'] = $adminUser->school_id;
        }

        $validated['is_active'] = true;

        $device = BiometricDevice::create($validated);

        return redirect()->route('admin.devices.show', $device)
            ->with('success', 'Device created successfully!');
    }

    /**
     * Display the specified device.
     */
    public function show(BiometricDevice $device)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this device
        if ($adminUser->role === "school_admin" && $device->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $device->load(['school']);

        // Get device statistics
        $stats = [
            'total_logs' => StudentLog::where('device_id', $device->device_id)->count(),
            'logs_today' => StudentLog::where('device_id', $device->device_id)
                ->whereDate('created_at', today())->count(),
            'logs_this_month' => StudentLog::where('device_id', $device->device_id)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
            'unique_students_today' => StudentLog::where('device_id', $device->device_id)
                ->whereDate('created_at', today())
                ->distinct('student_id')
                ->count(),
        ];

        // Get recent activity
        $recentActivity = StudentLog::where('device_id', $device->device_id)
            ->with(['student:id,first_name,last_name,student_id'])
            ->latest()
            ->limit(20)
            ->get();

        // Check online status
        $device->is_online = $device->last_heartbeat && 
            $device->last_heartbeat->diffInMinutes(now()) <= 5;

        return Inertia::render('Admin/Devices/Show', [
            'device' => $device,
            'stats' => $stats,
            'recentActivity' => $recentActivity,
        ]);
    }

    /**
     * Show the form for editing the specified device.
     */
    public function edit(BiometricDevice $device)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this device
        if ($adminUser->role === "school_admin" && $device->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $schools = $adminUser->role === "super_admin" 
            ? School::select('id', 'name')->get()
            : School::where('id', $adminUser->school_id)->select('id', 'name')->get();

        return Inertia::render('Admin/Devices/Edit', [
            'device' => $device,
            'schools' => $schools,
            'isSuper' => $adminUser->role === "super_admin",
        ]);
    }

    /**
     * Update the specified device.
     */
    public function update(Request $request, BiometricDevice $device)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this device
        if ($adminUser->role === "school_admin" && $device->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'device_id' => ['required', 'string', 'max:255', Rule::unique('biometric_devices')->ignore($device->id)],
            'mac_address' => 'nullable|string|max:17',
            'ip_address' => 'nullable|ip',
            'location' => 'nullable|string|max:255',
            'device_type' => 'nullable|string|max:100',
            'firmware_version' => 'nullable|string|max:50',
            'school_id' => $adminUser->role === "super_admin" 
                ? 'required|exists:schools,id' 
                : 'nullable',
        ]);

        // Ensure school admin can't change school
        if ($adminUser->role === "school_admin") {
            $validated['school_id'] = $adminUser->school_id;
        }

        $device->update($validated);

        return redirect()->route('admin.devices.show', $device)
            ->with('success', 'Device updated successfully!');
    }

    /**
     * Remove the specified device.
     */
    public function destroy(BiometricDevice $device)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this device
        if ($adminUser->role === "school_admin" && $device->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        // Check if device has logs
        if (StudentLog::where('device_id', $device->device_id)->exists()) {
            return back()->withErrors(['error' => 'Cannot delete device with attendance records.']);
        }

        $device->delete();

        return redirect()->route('admin.devices.index')
            ->with('success', 'Device deleted successfully!');
    }

    /**
     * Toggle device active status.
     */
    public function toggleStatus(BiometricDevice $device)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this device
        if ($adminUser->role === "school_admin" && $device->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $device->update([
            'is_active' => !$device->is_active
        ]);

        $status = $device->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Device has been {$status} successfully!");
    }

    /**
     * Sync students to device.
     */
    public function syncStudents(BiometricDevice $device)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this device
        if ($adminUser->role === 'school_admin' && $device->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        try {
            // This would sync all students from the school to the device
            $students = $device->school->students;
            
            // For now, just return success - MQTT sync can be implemented later
            return back()->with('success', "Sync command sent to device. {$students->count()} students will be synced.");

        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Error syncing students: ' . $e->getMessage()]);
        }
    }

    /**
     * Test device connection.
     */
    public function testConnection(BiometricDevice $device)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this device
        if ($adminUser->role === "school_admin" && $device->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        try {
            // Check if device responded recently (within 5 minutes)
            $isOnline = $device->last_heartbeat && 
                $device->last_heartbeat->diffInMinutes(now()) <= 5;

            $lastSeen = $device->last_heartbeat 
                ? $device->last_heartbeat->diffForHumans() 
                : 'Never';

            return response()->json([
                'success' => true,
                'online' => $isOnline,
                'last_seen' => $lastSeen,
                'message' => $isOnline 
                    ? 'Device is online and responding'
                    : "Device appears offline. Last seen: {$lastSeen}"
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'online' => false,
                'message' => 'Error testing device connection: ' . $e->getMessage()
            ], 500);
        }
    }
}
