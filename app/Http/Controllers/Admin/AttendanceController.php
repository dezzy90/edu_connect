<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StudentLog;
use App\Models\Student;
use App\Models\School;
use App\Models\BiometricDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    /**
     * Display a listing of attendance records.
     */
    public function index(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();
        
        $query = StudentLog::with(['student:id,first_name,last_name,student_number,school_id', 'student.school:id,name', 'device:id,name,device_id'])
            ->when($request->search, function($query, $search) {
                $query->whereHas('student', function($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('student_number', 'like', "%{$search}%");
                });
            })
            ->when($request->school_id, function($query, $schoolId) {
                $query->whereHas('student', function($q) use ($schoolId) {
                    $q->where('school_id', $schoolId);
                });
            })
            ->when($request->device_id, function($query, $deviceId) {
                $query->where('device_id', $deviceId);
            })
            ->when($request->event_type, function($query, $eventType) {
                $query->where('event_type', $eventType);
            })
            ->when($request->date_from, function($query, $dateFrom) {
                $query->whereDate('created_at', '>=', $dateFrom);
            })
            ->when($request->date_to, function($query, $dateTo) {
                $query->whereDate('created_at', '<=', $dateTo);
            });

        // Restrict to school admin's school
        if ($adminUser->role === "school_admin") {
            $query->whereHas('student', function($q) use ($adminUser) {
                $q->where('school_id', $adminUser->school_id);
            });
        }

        $attendanceLogs = $query->latest()->paginate(20);

        // Get filter options
        $schools = $adminUser->role === "super_admin" ? School::select('id', 'name')->get() : collect();
        $devices = $adminUser->role === "super_admin" 
            ? BiometricDevice::select('id', 'name', 'device_id')->get()
            : BiometricDevice::where('school_id', $adminUser->school_id)->select('id', 'name', 'device_id')->get();

        // Get stats for today
        $today = today();
        $statsQuery = StudentLog::query();
        
        // Restrict to school admin's school for stats
        if ($adminUser->role === "school_admin") {
            $statsQuery->whereHas('student', function($q) use ($adminUser) {
                $q->where('school_id', $adminUser->school_id);
            });
        }
        
        $stats = [
            'total_today' => $statsQuery->clone()->whereDate('created_at', $today)->count(),
            'verified_today' => $statsQuery->clone()->whereDate('created_at', $today)->where('confidence_score', '>=', 80)->count(),
            'unverified_today' => $statsQuery->clone()->whereDate('created_at', $today)->where('confidence_score', '<', 80)->orWhereNull('confidence_score')->count(),
            'devices_active' => $adminUser->role === "super_admin" 
                ? BiometricDevice::where('is_active', true)->count()
                : BiometricDevice::where('school_id', $adminUser->school_id)->where('is_active', true)->count(),
        ];

        return Inertia::render('Admin/Attendance/Index', [
            'attendance' => $attendanceLogs,
            'schools' => $schools,
            'devices' => $devices,
            'filters' => $request->only(['search', 'school_id', 'device_id', 'event_type', 'date_from', 'date_to']),
            'isSuper' => $adminUser->role === "super_admin",
            'stats' => $stats,
            'admin' => $adminUser,
        ]);
    }

    /**
     * Display the specified attendance record.
     */
    public function show(StudentLog $attendance)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this record
        if ($adminUser->role === "school_admin" && $attendance->student->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $attendance->load(['student.school', 'device']);

        return Inertia::render('Admin/Attendance/Show', [
            'attendance' => $attendance,
            'admin' => $adminUser,
        ]);
    }

    /**
     * Export attendance data.
     */
    public function export(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        $query = StudentLog::with(['student:id,first_name,last_name,student_number,school_id', 'device:id,name'])
            ->when($request->school_id, function($query, $schoolId) {
                $query->whereHas('student', function($q) use ($schoolId) {
                    $q->where('school_id', $schoolId);
                });
            })
            ->when($request->date_from, function($query, $dateFrom) {
                $query->whereDate('created_at', '>=', $dateFrom);
            })
            ->when($request->date_to, function($query, $dateTo) {
                $query->whereDate('created_at', '<=', $dateTo);
            });

        // Restrict to school admin's school
        if ($adminUser->role === "school_admin") {
            $query->whereHas('student', function($q) use ($adminUser) {
                $q->where('school_id', $adminUser->school_id);
            });
        }

        $attendanceLogs = $query->get();

        // This would typically generate a CSV or Excel file
        // For now, just return JSON
        return response()->json($attendanceLogs);
    }

    /**
     * Get attendance statistics.
     */
    public function stats(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();
        
        $query = StudentLog::query();
        
        // Restrict to school admin's school
        if ($adminUser->role === "school_admin") {
            $query->whereHas('student', function($q) use ($adminUser) {
                $q->where('school_id', $adminUser->school_id);
            });
        }

        $today = today();
        $thisMonth = now()->startOfMonth();

        $stats = [
            'total_logs_today' => $query->clone()->whereDate('created_at', $today)->count(),
            'check_ins_today' => $query->clone()->whereDate('created_at', $today)->where('event_type', 'check_in')->count(),
            'check_outs_today' => $query->clone()->whereDate('created_at', $today)->where('event_type', 'check_out')->count(),
            'unique_students_today' => $query->clone()->whereDate('created_at', $today)->distinct('student_id')->count(),
            'total_logs_this_month' => $query->clone()->whereDate('created_at', '>=', $thisMonth)->count(),
        ];

        return response()->json($stats);
    }
}
