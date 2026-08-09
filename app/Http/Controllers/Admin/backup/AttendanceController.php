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
     * Display attendance records.
     */
    public function index(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();
        
        $query = StudentLog::with(['student.school', 'student.schoolClass', 'biometricDevice'])
            ->when($request->search, function($query, $search) {
                $query->whereHas('student', function($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('student_id', 'like', "%{$search}%");
                });
            })
            ->when($request->school_id, function($query, $schoolId) {
                $query->whereHas('student', function($q) use ($schoolId) {
                    $q->where('school_id', $schoolId);
                });
            })
            ->when($request->event_type, function($query, $eventType) {
                $query->where('event_type', $eventType);
            })
            ->when($request->device_id, function($query, $deviceId) {
                $query->where('device_id', $deviceId);
            })
            ->when($request->date_from, function($query, $dateFrom) {
                $query->whereDate('created_at', '>=', $dateFrom);
            })
            ->when($request->date_to, function($query, $dateTo) {
                $query->whereDate('created_at', '<=', $dateTo);
            });

        // Default to today if no date filter is set
        if (!$request->date_from && !$request->date_to) {
            $query->whereDate('created_at', today());
        }

        // Restrict to school admin's school
        if ($adminUser->role === "school_admin") {
            $query->whereHas('student', function($q) use ($adminUser) {
                $q->where('school_id', $adminUser->school_id);
            });
        }

        $attendanceRecords = $query->latest()->paginate(20);

        // Get filter options
        $schools = $adminUser->role === "super_admin" ? School::select('id', 'name')->get() : collect();
        $devices = $adminUser->role === "super_admin" 
            ? BiometricDevice::select('id', 'device_id', 'name')->get()
            : BiometricDevice::where('school_id', $adminUser->school_id)->select('id', 'device_id', 'name')->get();

        return Inertia::render('Admin/Attendance/Index', [
            'attendanceRecords' => $attendanceRecords,
            'schools' => $schools,
            'devices' => $devices,
            'filters' => $request->only(['search', 'school_id', 'event_type', 'device_id', 'date_from', 'date_to']),
            'isSuper' => $adminUser->role === "super_admin",
        ]);
    }

    /**
     * Display attendance statistics and reports.
     */
    public function reports(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();
        
        $dateFrom = $request->date_from ? Carbon::parse($request->date_from) : Carbon::now()->startOfMonth();
        $dateTo = $request->date_to ? Carbon::parse($request->date_to) : Carbon::now()->endOfMonth();

        $baseQuery = StudentLog::whereBetween('created_at', [$dateFrom, $dateTo]);

        // Restrict to school admin's school
        if ($adminUser->role === "school_admin") {
            $baseQuery->whereHas('student', function($q) use ($adminUser) {
                $q->where('school_id', $adminUser->school_id);
            });
        }

        // Apply school filter if provided
        if ($request->school_id) {
            $baseQuery->whereHas('student', function($q) use ($request) {
                $q->where('school_id', $request->school_id);
            });
        }

        // Overall statistics
        $stats = [
            'total_check_ins' => (clone $baseQuery)->where('event_type', 'check_in')->count(),
            'total_check_outs' => (clone $baseQuery)->where('event_type', 'check_out')->count(),
            'unique_students' => (clone $baseQuery)->where('event_type', 'check_in')->distinct('student_id')->count(),
            'active_days' => (clone $baseQuery)->selectRaw('DATE(created_at) as date')->distinct()->count(),
        ];

        // Daily attendance chart data
        $dailyAttendance = [];
        $currentDate = $dateFrom->copy();
        while ($currentDate <= $dateTo) {
            $count = (clone $baseQuery)
                ->whereDate('created_at', $currentDate)
                ->where('event_type', 'check_in')
                ->distinct('student_id')
                ->count();

            $dailyAttendance[] = [
                'date' => $currentDate->format('Y-m-d'),
                'formatted_date' => $currentDate->format('M d'),
                'count' => $count,
            ];

            $currentDate->addDay();
        }

        // Top students by attendance
        $topStudents = Student::select('students.*')
            ->join('student_logs', 'students.id', '=', 'student_logs.student_id')
            ->whereBetween('student_logs.created_at', [$dateFrom, $dateTo])
            ->where('student_logs.event_type', 'check_in');

        if ($adminUser->role === "school_admin") {
            $topStudents->where('students.school_id', $adminUser->school_id);
        }

        if ($request->school_id) {
            $topStudents->where('students.school_id', $request->school_id);
        }

        $topStudents = $topStudents->with('school')
            ->selectRaw('students.*, COUNT(student_logs.id) as attendance_count')
            ->groupBy('students.id')
            ->orderByDesc('attendance_count')
            ->limit(10)
            ->get();

        // Device usage statistics
        $deviceStats = BiometricDevice::select('biometric_devices.*')
            ->join('student_logs', 'biometric_devices.device_id', '=', 'student_logs.device_id')
            ->whereBetween('student_logs.created_at', [$dateFrom, $dateTo]);

        if ($adminUser->role === "school_admin") {
            $deviceStats->where('biometric_devices.school_id', $adminUser->school_id);
        }

        if ($request->school_id) {
            $deviceStats->where('biometric_devices.school_id', $request->school_id);
        }

        $deviceStats = $deviceStats->with('school')
            ->selectRaw('biometric_devices.*, COUNT(student_logs.id) as usage_count')
            ->groupBy('biometric_devices.id')
            ->orderByDesc('usage_count')
            ->get();

        // Get filter options
        $schools = $adminUser->role === "super_admin" ? School::select('id', 'name')->get() : collect();

        return Inertia::render('Admin/Attendance/Reports', [
            'stats' => $stats,
            'dailyAttendance' => $dailyAttendance,
            'topStudents' => $topStudents,
            'deviceStats' => $deviceStats,
            'schools' => $schools,
            'filters' => $request->only(['school_id', 'date_from', 'date_to']),
            'isSuper' => $adminUser->role === "super_admin",
            'dateRange' => [
                'from' => $dateFrom->format('Y-m-d'),
                'to' => $dateTo->format('Y-m-d'),
            ],
        ]);
    }

    /**
     * Display detailed view of a student's attendance.
     */
    public function studentDetail(Student $student, Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this student
        if ($adminUser->role === "school_admin" && $student->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $dateFrom = $request->date_from ? Carbon::parse($request->date_from) : Carbon::now()->startOfMonth();
        $dateTo = $request->date_to ? Carbon::parse($request->date_to) : Carbon::now()->endOfMonth();

        $student->load(['school', 'schoolClass']);

        // Get attendance records for the period
        $attendanceRecords = StudentLog::where('student_id', $student->id)
            ->whereBetween('created_at', [$dateFrom, $dateTo])
            ->with('biometricDevice:id,device_id,name')
            ->latest()
            ->paginate(20);

        // Calculate statistics
        $stats = [
            'total_check_ins' => StudentLog::where('student_id', $student->id)
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('event_type', 'check_in')
                ->count(),
            'total_check_outs' => StudentLog::where('student_id', $student->id)
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('event_type', 'check_out')
                ->count(),
            'attendance_days' => StudentLog::where('student_id', $student->id)
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('event_type', 'check_in')
                ->selectRaw('DATE(created_at) as date')
                ->distinct()
                ->count(),
            'average_check_in_time' => StudentLog::where('student_id', $student->id)
                ->whereBetween('created_at', [$dateFrom, $dateTo])
                ->where('event_type', 'check_in')
                ->selectRaw('AVG(TIME(created_at)) as avg_time')
                ->value('avg_time'),
        ];

        return Inertia::render('Admin/Attendance/StudentDetail', [
            'student' => $student,
            'attendanceRecords' => $attendanceRecords,
            'stats' => $stats,
            'filters' => $request->only(['date_from', 'date_to']),
            'dateRange' => [
                'from' => $dateFrom->format('Y-m-d'),
                'to' => $dateTo->format('Y-m-d'),
            ],
        ]);
    }

    /**
     * Export attendance data.
     */
    public function export(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        $dateFrom = $request->date_from ? Carbon::parse($request->date_from) : Carbon::now()->startOfMonth();
        $dateTo = $request->date_to ? Carbon::parse($request->date_to) : Carbon::now()->endOfMonth();

        $query = StudentLog::with(['student.school', 'student.schoolClass', 'biometricDevice'])
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        // Restrict to school admin's school
        if ($adminUser->role === "school_admin") {
            $query->whereHas('student', function($q) use ($adminUser) {
                $q->where('school_id', $adminUser->school_id);
            });
        }

        // Apply filters
        if ($request->school_id) {
            $query->whereHas('student', function($q) use ($request) {
                $q->where('school_id', $request->school_id);
            });
        }

        if ($request->event_type) {
            $query->where('event_type', $request->event_type);
        }

        $attendanceData = $query->latest()->get();

        // Transform data for export
        $exportData = $attendanceData->map(function ($log) {
            return [
                'Date' => $log->created_at->format('Y-m-d H:i:s'),
                'Student ID' => $log->student->student_id,
                'Student Name' => $log->student->first_name . ' ' . $log->student->last_name,
                'School' => $log->student->school->name,
                'Class' => $log->student->schoolClass?->name,
                'Event Type' => ucfirst(str_replace('_', ' ', $log->event_type)),
                'Device' => $log->biometricDevice?->name,
                'Similarity Score' => $log->similarity,
                'Verify Status' => $log->verify_status,
            ];
        });

        // For now, return as JSON. In production, you'd generate CSV/Excel
        return response()->json([
            'data' => $exportData,
            'filename' => "attendance_export_{$dateFrom->format('Y-m-d')}_to_{$dateTo->format('Y-m-d')}.json"
        ]);
    }

    /**
     * Manual attendance entry (for corrections/adjustments).
     */
    public function manualEntry(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'event_type' => 'required|in:check_in,check_out',
            'device_id' => 'required|exists:biometric_devices,device_id',
            'event_time' => 'required|date',
            'notes' => 'nullable|string|max:500',
        ]);

        // Check if school admin can access this student
        $student = Student::find($validated['student_id']);
        if ($adminUser->role === "school_admin" && $student->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        // Create manual attendance entry
        $log = StudentLog::create([
            'student_id' => $validated['student_id'],
            'device_id' => $validated['device_id'],
            'event_type' => $validated['event_type'],
            'verify_status' => 1, // Manual entries are always verified
            'similarity' => 100, // Manual entries have perfect similarity
            'created_at' => Carbon::parse($validated['event_time']),
            'notes' => $validated['notes'] ?? 'Manual entry by ' . $adminUser->name,
        ]);

        return back()->with('success', 'Manual attendance entry created successfully!');
    }
}
