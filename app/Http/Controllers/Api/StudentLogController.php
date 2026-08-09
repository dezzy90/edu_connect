<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\StudentLog;
use App\Models\Student;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StudentLogController extends Controller
{
    /**
     * Get attendance logs with filtering options
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'school_id' => 'nullable|exists:schools,id',
                'student_id' => 'nullable|exists:students,id',
                'device_id' => 'nullable|exists:biometric_devices,id',
                'event_type' => 'nullable|in:check_in,check_out',
                'date' => 'nullable|date',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'limit' => 'nullable|integer|min:1|max:1000',
            ]);

            $query = StudentLog::with(['student.schoolClass.level.option.section', 'device']);

            // Apply filters
            if (isset($validated['student_id'])) {
                $query->where('student_id', $validated['student_id']);
            }

            if (isset($validated['device_id'])) {
                $query->where('device_id', $validated['device_id']);
            }

            if (isset($validated['event_type'])) {
                $query->where('event_type', $validated['event_type']);
            }

            if (isset($validated['date'])) {
                $query->whereDate('created_at', $validated['date']);
            } elseif (isset($validated['date_from']) || isset($validated['date_to'])) {
                if (isset($validated['date_from'])) {
                    $query->whereDate('created_at', '>=', $validated['date_from']);
                }
                if (isset($validated['date_to'])) {
                    $query->whereDate('created_at', '<=', $validated['date_to']);
                }
            }

            // Filter by school if specified
            if (isset($validated['school_id'])) {
                $query->whereHas('student', function ($q) use ($validated) {
                    $q->where('school_id', $validated['school_id']);
                });
            }

            $limit = $validated['limit'] ?? 100;
            $logs = $query->orderBy('created_at', 'desc')->limit($limit)->get();

            return response()->json([
                'status' => 'success',
                'data' => $logs->map(function ($log) {
                    return $this->formatLogEntry($log);
                }),
                'meta' => [
                    'total' => $logs->count(),
                    'filters_applied' => array_intersect_key($validated, array_flip([
                        'school_id', 'student_id', 'device_id', 'event_type', 'date', 'date_from', 'date_to'
                    ]))
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve attendance logs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get today's attendance summary for a school
     */
    public function getTodaysSummary(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'school_id' => 'required|exists:schools,id',
            ]);

            $schoolId = $validated['school_id'];
            $today = Carbon::today();

            // Get today's attendance stats
            $stats = StudentLog::whereHas('student', function ($q) use ($schoolId) {
                    $q->where('school_id', $schoolId);
                })
                ->whereDate('created_at', $today)
                ->select('event_type', DB::raw('count(*) as total'))
                ->groupBy('event_type')
                ->get()
                ->pluck('total', 'event_type');

            // Get students who checked in today
            $checkedInStudents = StudentLog::with(['student.schoolClass'])
                ->whereHas('student', function ($q) use ($schoolId) {
                    $q->where('school_id', $schoolId);
                })
                ->where('event_type', 'check_in')
                ->whereDate('created_at', $today)
                ->get()
                ->groupBy('student_id')
                ->map(function ($logs) use ($today) {
                    // Get the first check-in of the day
                    $firstCheckIn = $logs->sortBy('created_at')->first();
                    return [
                        'student_id' => $firstCheckIn->student->id,
                        'name' => $firstCheckIn->student->first_name . ' ' . $firstCheckIn->student->last_name,
                        'student_number' => $firstCheckIn->student->student_number,
                        'class' => $firstCheckIn->student->schoolClass->name ?? 'N/A',
                        'first_check_in' => $firstCheckIn->created_at->format('H:i'),
                        'status' => $this->getStudentDailyStatus($firstCheckIn->student->id, $today)
                    ];
                })
                ->values();

            // Get total active students in school
            $totalActiveStudents = Student::where('school_id', $schoolId)
                ->where('is_active', true)
                ->count();

            $presentStudents = $checkedInStudents->count();
            $absentStudents = $totalActiveStudents - $presentStudents;

            return response()->json([
                'status' => 'success',
                'data' => [
                    'date' => $today->format('Y-m-d'),
                    'summary' => [
                        'total_students' => $totalActiveStudents,
                        'present' => $presentStudents,
                        'absent' => $absentStudents,
                        'attendance_rate' => $totalActiveStudents > 0 
                            ? round(($presentStudents / $totalActiveStudents) * 100, 2) 
                            : 0,
                        'total_check_ins' => $stats['check_in'] ?? 0,
                        'total_check_outs' => $stats['check_out'] ?? 0,
                    ],
                    'present_students' => $checkedInStudents
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate attendance summary',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get attendance history for a specific student
     */
    public function getStudentAttendance(Request $request, int $studentId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'limit' => 'nullable|integer|min:1|max:365',
            ]);

            $student = Student::with('schoolClass.level.option.section')->findOrFail($studentId);

            $query = StudentLog::with('device')
                ->where('student_id', $studentId);

            if (isset($validated['date_from'])) {
                $query->whereDate('created_at', '>=', $validated['date_from']);
            }

            if (isset($validated['date_to'])) {
                $query->whereDate('created_at', '<=', $validated['date_to']);
            }

            $limit = $validated['limit'] ?? 30;
            $logs = $query->orderBy('created_at', 'desc')->limit($limit * 2)->get(); // Multiply by 2 to get both check-in/out

            // Group by date for daily attendance view
            $dailyAttendance = $logs->groupBy(function ($log) {
                return $log->created_at->format('Y-m-d');
            })->map(function ($dailyLogs, $date) {
                $checkIn = $dailyLogs->where('event_type', 'check_in')->first();
                $checkOut = $dailyLogs->where('event_type', 'check_out')->first();

                return [
                    'date' => $date,
                    'check_in' => $checkIn ? [
                        'time' => $checkIn->created_at->format('H:i:s'),
                        'device' => $checkIn->device->name,
                        'confidence' => $checkIn->confidence_score,
                    ] : null,
                    'check_out' => $checkOut ? [
                        'time' => $checkOut->created_at->format('H:i:s'),
                        'device' => $checkOut->device->name,
                        'confidence' => $checkOut->confidence_score,
                    ] : null,
                    'status' => $checkIn ? ($checkOut ? 'completed' : 'present') : 'absent',
                    'total_events' => $dailyLogs->count(),
                ];
            })->take($limit)->values();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'student' => [
                        'id' => $student->id,
                        'name' => $student->first_name . ' ' . $student->last_name,
                        'student_number' => $student->student_number,
                        'class' => $student->schoolClass->name ?? 'N/A',
                        'section' => $student->schoolClass->level->option->section->name ?? 'N/A',
                    ],
                    'attendance' => $dailyAttendance,
                    'summary' => [
                        'total_days' => $dailyAttendance->count(),
                        'present_days' => $dailyAttendance->where('status', '!=', 'absent')->count(),
                        'attendance_rate' => $dailyAttendance->count() > 0 
                            ? round(($dailyAttendance->where('status', '!=', 'absent')->count() / $dailyAttendance->count()) * 100, 2)
                            : 0
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve student attendance',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get real-time attendance status (who's currently in school)
     */
    public function getCurrentStatus(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'school_id' => 'required|exists:schools,id',
            ]);

            $schoolId = $validated['school_id'];

            // Get students who checked in today but haven't checked out yet
            $currentlyPresent = DB::select("
                SELECT DISTINCT s.id, s.first_name, s.last_name, s.student_number,
                       sc.name as class_name,
                       first_checkin.check_in_time,
                       last_checkout.check_out_time
                FROM students s
                LEFT JOIN school_classes sc ON s.class_id = sc.id
                LEFT JOIN (
                    SELECT student_id, MIN(created_at) as check_in_time
                    FROM student_logs 
                    WHERE event_type = 'check_in' AND DATE(created_at) = CURDATE()
                    GROUP BY student_id
                ) first_checkin ON s.id = first_checkin.student_id
                LEFT JOIN (
                    SELECT student_id, MAX(created_at) as check_out_time
                    FROM student_logs 
                    WHERE event_type = 'check_out' AND DATE(created_at) = CURDATE()
                    GROUP BY student_id
                ) last_checkout ON s.id = last_checkout.student_id
                WHERE s.school_id = ? AND s.is_active = 1 
                AND first_checkin.check_in_time IS NOT NULL
                AND (last_checkout.check_out_time IS NULL OR last_checkout.check_out_time < first_checkin.check_in_time)
                ORDER BY first_checkin.check_in_time ASC
            ", [$schoolId]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'currently_present' => collect($currentlyPresent)->map(function ($student) {
                        return [
                            'id' => $student->id,
                            'name' => $student->first_name . ' ' . $student->last_name,
                            'student_number' => $student->student_number,
                            'class' => $student->class_name,
                            'check_in_time' => Carbon::parse($student->check_in_time)->format('H:i'),
                            'duration' => Carbon::parse($student->check_in_time)->diffForHumans(null, true),
                        ];
                    }),
                    'total_present' => count($currentlyPresent),
                    'last_updated' => now()->toISOString(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get current attendance status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Format a log entry for API response
     */
    private function formatLogEntry(StudentLog $log): array
    {
        return [
            'id' => $log->id,
            'student' => [
                'id' => $log->student->id,
                'name' => $log->student->first_name . ' ' . $log->student->last_name,
                'student_number' => $log->student->student_number,
                'class' => $log->student->schoolClass->name ?? 'N/A',
                'section' => $log->student->schoolClass->level->option->section->name ?? 'N/A',
            ],
            'device' => [
                'id' => $log->device->id,
                'name' => $log->device->name,
                'device_id' => $log->device->device_id,
                'location' => $log->device->location,
            ],
            'event_type' => $log->event_type,
            'confidence_score' => $log->confidence_score,
            'timestamp' => $log->created_at->toISOString(),
            'formatted_time' => $log->created_at->format('d/m/Y H:i:s'),
            'notes' => $log->notes,
        ];
    }

    /**
     * Get student's daily status (present, absent, checked out)
     */
    private function getStudentDailyStatus(int $studentId, Carbon $date): string
    {
        $logs = StudentLog::where('student_id', $studentId)
            ->whereDate('created_at', $date)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($logs->isEmpty()) {
            return 'absent';
        }

        $lastEvent = $logs->first()->event_type;
        return $lastEvent === 'check_in' ? 'present' : 'checked_out';
    }
}
