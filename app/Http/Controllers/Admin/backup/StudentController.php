<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Level;
use App\Models\Option;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class StudentController extends Controller
{
    /**
     * Display a listing of students.
     */
    public function index(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();
        
        $query = Student::with(['school', 'schoolClass', 'section', 'level', 'option'])
            ->when($request->search, function($query, $search) {
                $query->where(function($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('student_id', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->school_id, function($query, $schoolId) {
                $query->where('school_id', $schoolId);
            })
            ->when($request->class_id, function($query, $classId) {
                $query->where('class_id', $classId);
            });

        // Restrict to school admin's school
        if ($adminUser->role === "school_admin") {
            $query->where('school_id', $adminUser->school_id);
        }

        $students = $query->latest()->paginate(15);

        // Get filter options
        $schools = $adminUser->role === "super_admin" ? School::select('id', 'name')->get() : collect();
        $classes = SchoolClass::select('id', 'name')->get();

        return Inertia::render('Admin/Students/Index', [
            'students' => $students,
            'schools' => $schools,
            'classes' => $classes,
            'filters' => $request->only(['search', 'school_id', 'class_id']),
            'isSuper' => $adminUser->role === "super_admin",
        ]);
    }

    /**
     * Show the form for creating a new student.
     */
    public function create()
    {
        $adminUser = Auth::guard('admin')->user();

        $schools = $adminUser->role === "super_admin" 
            ? School::select('id', 'name')->get()
            : School::where('id', $adminUser->school_id)->select('id', 'name')->get();
            
        $classes = SchoolClass::select('id', 'name')->get();
        $sections = Section::select('id', 'name')->get();
        $levels = Level::select('id', 'name')->get();
        $options = Option::select('id', 'name')->get();

        return Inertia::render('Admin/Students/Create', [
            'schools' => $schools,
            'classes' => $classes,
            'sections' => $sections,
            'levels' => $levels,
            'options' => $options,
            'isSuper' => $adminUser->role === "super_admin",
        ]);
    }

    /**
     * Store a newly created student.
     */
    public function store(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'student_id' => 'required|string|max:50|unique:students',
            'email' => 'nullable|email|max:255|unique:students',
            'phone' => 'nullable|string|max:20',
            'date_of_birth' => 'required|date|before:today',
            'gender' => 'required|in:male,female',
            'address' => 'nullable|string|max:500',
            'school_id' => $adminUser->role === "super_admin" 
                ? 'required|exists:schools,id' 
                : 'nullable',
            'class_id' => 'required|exists:school_classes,id',
            'section_id' => 'nullable|exists:sections,id',
            'level_id' => 'nullable|exists:levels,id',
            'option_id' => 'nullable|exists:options,id',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',
        ]);

        // Set school_id for school admin
        if ($adminUser->role === "school_admin") {
            $validated['school_id'] = $adminUser->school_id;
        }

        $student = Student::create($validated);

        return redirect()->route('admin.students.show', $student)
            ->with('success', 'Student created successfully!');
    }

    /**
     * Display the specified student.
     */
    public function show(Student $student)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this student
        if ($adminUser->role === "school_admin" && $student->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $student->load([
            'school',
            'schoolClass',
            'section',
            'level',
            'option',
            'parents',
            'studentLogs' => function($query) {
                $query->with('biometricDevice:id,name')->latest()->limit(20);
            }
        ]);

        // Get attendance stats
        $attendanceStats = [
            'total_logs' => $student->studentLogs->count(),
            'check_ins_today' => $student->studentLogs()
                ->whereDate('created_at', today())
                ->where('event_type', 'check_in')
                ->count(),
            'check_outs_today' => $student->studentLogs()
                ->whereDate('created_at', today())
                ->where('event_type', 'check_out')
                ->count(),
            'this_month_attendance' => $student->studentLogs()
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->where('event_type', 'check_in')
                ->distinct('created_at')
                ->count('created_at'),
        ];

        return Inertia::render('Admin/Students/Show', [
            'student' => $student,
            'attendanceStats' => $attendanceStats,
        ]);
    }

    /**
     * Show the form for editing the specified student.
     */
    public function edit(Student $student)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this student
        if ($adminUser->role === "school_admin" && $student->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $schools = $adminUser->role === "super_admin" 
            ? School::select('id', 'name')->get()
            : School::where('id', $adminUser->school_id)->select('id', 'name')->get();
            
        $classes = SchoolClass::select('id', 'name')->get();
        $sections = Section::select('id', 'name')->get();
        $levels = Level::select('id', 'name')->get();
        $options = Option::select('id', 'name')->get();

        return Inertia::render('Admin/Students/Edit', [
            'student' => $student,
            'schools' => $schools,
            'classes' => $classes,
            'sections' => $sections,
            'levels' => $levels,
            'options' => $options,
            'isSuper' => $adminUser->role === "super_admin",
        ]);
    }

    /**
     * Update the specified student.
     */
    public function update(Request $request, Student $student)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this student
        if ($adminUser->role === "school_admin" && $student->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'student_id' => ['required', 'string', 'max:50', Rule::unique('students')->ignore($student->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('students')->ignore($student->id)],
            'phone' => 'nullable|string|max:20',
            'date_of_birth' => 'required|date|before:today',
            'gender' => 'required|in:male,female',
            'address' => 'nullable|string|max:500',
            'school_id' => $adminUser->role === "super_admin" 
                ? 'required|exists:schools,id' 
                : 'nullable',
            'class_id' => 'required|exists:school_classes,id',
            'section_id' => 'nullable|exists:sections,id',
            'level_id' => 'nullable|exists:levels,id',
            'option_id' => 'nullable|exists:options,id',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',
        ]);

        // Ensure school admin can't change school
        if ($adminUser->role === "school_admin") {
            $validated['school_id'] = $adminUser->school_id;
        }

        $student->update($validated);

        return redirect()->route('admin.students.show', $student)
            ->with('success', 'Student updated successfully!');
    }

    /**
     * Remove the specified student.
     */
    public function destroy(Student $student)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this student
        if ($adminUser->role === "school_admin" && $student->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        // Check if student has attendance records
        if ($student->studentLogs()->count() > 0) {
            return back()->withErrors(['error' => 'Cannot delete student with attendance records.']);
        }

        $student->delete();

        return redirect()->route('admin.students.index')
            ->with('success', 'Student deleted successfully!');
    }

    /**
     * Export students data.
     */
    public function export(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        $query = Student::with(['school', 'schoolClass'])
            ->when($request->school_id, function($query, $schoolId) {
                $query->where('school_id', $schoolId);
            });

        // Restrict to school admin's school
        if ($adminUser->role === "school_admin") {
            $query->where('school_id', $adminUser->school_id);
        }

        $students = $query->get();

        // This would typically generate a CSV or Excel file
        // For now, just return JSON
        return response()->json($students);
    }
}
