<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentLog;
use App\Models\BiometricDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Carbon\Carbon;

class AdminDashboardController extends Controller
{
    /**
     * Display the admin dashboard.
     */
    public function index()
    {
        $adminUser = Auth::guard('admin')->user();
        $isSuper = $adminUser->role === 'super_admin';

        // Get stats based on admin role
        if ($isSuper) {
            $stats = $this->getSuperAdminStats();
            $recentActivities = $this->getRecentActivities();
            $schools = School::with('students')->get();
        } else {
            $stats = $this->getSchoolAdminStats($adminUser->school_id);
            $recentActivities = $this->getRecentActivities($adminUser->school_id);
            $schools = null;
        }

        return Inertia::render('Admin/Dashboard', [
            'admin' => $adminUser->load('school'),
            'stats' => $stats,
            'recentActivities' => $recentActivities,
            'schools' => $schools,
            'isSuper' => $isSuper,
        ]);
    }

    /**
     * Get statistics for super admin.
     */
    private function getSuperAdminStats()
    {
        $today = Carbon::today();
        $thisMonth = Carbon::now()->startOfMonth();

        return [
            'total_schools' => School::count(),
            'total_students' => Student::count(),
            'active_devices' => BiometricDevice::where('is_active', true)->count(),
            'total_attendance_today' => StudentLog::whereDate('created_at', $today)->count(),
            'total_attendance_month' => StudentLog::whereDate('created_at', '>=', $thisMonth)->count(),
            'total_admin_users' => AdminUser::where('is_active', true)->count(),
            'online_devices' => BiometricDevice::where('last_heartbeat', '>=', now()->subMinutes(5))->count(),
        ];
    }

    /**
     * Get statistics for school admin.
     */
    private function getSchoolAdminStats($schoolId)
    {
        $today = Carbon::today();
        $thisMonth = Carbon::now()->startOfMonth();

        return [
            'total_students' => Student::where('school_id', $schoolId)->count(),
            'active_devices' => BiometricDevice::where('school_id', $schoolId)->where('is_active', true)->count(),
            'total_attendance_today' => StudentLog::whereHas('student', function($query) use ($schoolId) {
                $query->where('school_id', $schoolId);
            })->whereDate('created_at', $today)->count(),
            'total_attendance_month' => StudentLog::whereHas('student', function($query) use ($schoolId) {
                $query->where('school_id', $schoolId);
            })->whereDate('created_at', '>=', $thisMonth)->count(),
                        'online_devices' => BiometricDevice::where('school_id', $schoolId)
                ->where('last_heartbeat', '>=', now()->subMinutes(5))->count(),
            'present_students_today' => StudentLog::whereHas('student', function($query) use ($schoolId) {
                $query->where('school_id', $schoolId);
            })
            ->whereDate('created_at', $today)
            ->where('event_type', 'check_in')
            ->distinct('student_id')
            ->count(),
        ];
    }

    /**
     * Get recent activities.
     */
    private function getRecentActivities($schoolId = null)
    {
        $query = StudentLog::with(['student.school'])
            ->latest()
            ->limit(10);

        if ($schoolId) {
            $query->whereHas('student', function($q) use ($schoolId) {
                $q->where('school_id', $schoolId);
            });
        }

        return $query->get()->map(function ($log) {
            return [
                'id' => $log->id,
                'student_name' => $log->student->first_name . ' ' . $log->student->last_name,
                'school_name' => $log->student->school->name,
                'event_type' => $log->event_type,
                'device_id' => $log->device_id,
                'similarity' => $log->similarity,
                'created_at' => $log->created_at,
                'formatted_time' => $log->created_at->diffForHumans(),
            ];
        });
    }

    /**
     * Get attendance overview data.
     */
    public function attendanceOverview(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();
        $schoolId = $adminUser->role !== 'super_admin' ? $adminUser->school_id : $request->get('school_id');

        $days = 7; // Last 7 days
        $attendanceData = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            
            $query = StudentLog::whereDate('created_at', $date->toDateString())
                ->where('event_type', 'check_in');

            if ($schoolId) {
                $query->whereHas('student', function($q) use ($schoolId) {
                    $q->where('school_id', $schoolId);
                });
            }

            $attendanceData[] = [
                'date' => $date->format('M d'),
                'count' => $query->distinct('student_id')->count(),
            ];
        }

        return response()->json($attendanceData);
    }
}

