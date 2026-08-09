<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\School;
use App\Models\Level;
use App\Models\Option;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SchoolClassController extends Controller
{
    /**
     * Display a listing of classes.
     */
    public function index(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();
        
        $query = SchoolClass::with(['school', 'level.option.section'])
            ->withCount(['students'])
            ->when($request->search, function($query, $search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('academic_year', 'like', "%{$search}%");
                });
            })
            ->when($request->school_id, function($query, $schoolId) {
                $query->where('school_id', $schoolId);
            })
            ->when($request->level_id, function($query, $levelId) {
                $query->where('level_id', $levelId);
            })
            ->when($request->academic_year, function($query, $year) {
                $query->where('academic_year', $year);
            })
            ->when($request->status, function($query, $status) {
                if ($status === 'active') {
                    $query->where('is_active', true);
                } elseif ($status === 'inactive') {
                    $query->where('is_active', false);
                }
            });

        // Restrict to school admin's school
        if ($adminUser->role === "school_admin") {
            $query->where('school_id', $adminUser->school_id);
        }

        $classes = $query->latest()->paginate(15);

        // Get filter options
        $schools = $adminUser->role === "super_admin" ? School::select('id', 'name')->get() : collect();
        $levels = $adminUser->role === "super_admin" 
            ? Level::with(['option.section', 'school'])->select('id', 'name', 'school_id', 'option_id')->get()
            : Level::where('school_id', $adminUser->school_id)->with('option.section')->select('id', 'name', 'option_id')->get();

        // Get distinct academic years
        $academicYears = SchoolClass::when($adminUser->role === "school_admin", function($query) use ($adminUser) {
            $query->where('school_id', $adminUser->school_id);
        })->distinct()->pluck('academic_year')->filter()->sort()->values();

        return Inertia::render('Admin/Classes/Index', [
            'classes' => $classes,
            'schools' => $schools,
            'levels' => $levels,
            'academicYears' => $academicYears,
            'filters' => $request->only(['search', 'school_id', 'level_id', 'academic_year', 'status']),
            'isSuper' => $adminUser->role === "super_admin",
            'admin' => $adminUser,
        ]);
    }

    /**
     * Show the form for creating a new class.
     */
    public function create()
    {
        $adminUser = Auth::guard('admin')->user();

        $schools = $adminUser->role === "super_admin" 
            ? School::select('id', 'name')->get()
            : School::where('id', $adminUser->school_id)->select('id', 'name')->get();

        $sections = $adminUser->role === "super_admin"
            ? Section::with('school')->active()->get()
            : Section::where('school_id', $adminUser->school_id)->active()->get();

        $options = $adminUser->role === "super_admin"
            ? Option::with(['school', 'section'])->active()->get()
            : Option::where('school_id', $adminUser->school_id)->with('section')->active()->get();

        $levels = $adminUser->role === "super_admin"
            ? Level::with(['option.section', 'school'])->active()->ordered()->get()
            : Level::where('school_id', $adminUser->school_id)->with('option.section')->active()->ordered()->get();

        return Inertia::render('Admin/Classes/Create', [
            'schools' => $schools,
            'sections' => $sections,
            'options' => $options,
            'levels' => $levels,
            'isSuper' => $adminUser->role === "super_admin",
            'admin' => $adminUser,
        ]);
    }

    /**
     * Store a newly created class.
     */
    public function store(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:10|unique:school_classes',
            'academic_year' => 'required|string|max:20',
            'capacity' => 'nullable|integer|min:1|max:1000',
            'level_id' => 'required|exists:levels,id',
            'school_id' => $adminUser->role === "super_admin" 
                ? 'required|exists:schools,id' 
                : 'nullable',
        ]);

        // Set school_id for school admin
        if ($adminUser->role === "school_admin") {
            $validated['school_id'] = $adminUser->school_id;
            
            // Verify level belongs to admin's school
            $level = Level::findOrFail($validated['level_id']);
            if ($level->school_id !== $adminUser->school_id) {
                abort(403, 'Level does not belong to your school.');
            }
        } else {
            // Verify level belongs to selected school
            $level = Level::findOrFail($validated['level_id']);
            if ($level->school_id !== $validated['school_id']) {
                return back()->withErrors(['level_id' => 'Level does not belong to the selected school.']);
            }
        }

        $validated['is_active'] = true;

        $class = SchoolClass::create($validated);

        return redirect()->route('admin.classes.show', $class)
            ->with('success', 'Class created successfully!');
    }

    /**
     * Display the specified class.
     */
    public function show(SchoolClass $class)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this class
        if ($adminUser->role === "school_admin" && $class->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $class->load(['school', 'level.option.section', 'students']);

        // Get statistics
        $stats = [
            'total_students' => $class->students()->count(),
            'active_students' => $class->students()->where('is_active', true)->count(),
            'capacity_used' => $class->capacity ? ($class->students()->count() / $class->capacity * 100) : 0,
            'has_capacity_limit' => (bool) $class->capacity,
        ];

        return Inertia::render('Admin/Classes/Show', [
            'class' => $class,
            'stats' => $stats,
            'admin' => $adminUser,
        ]);
    }

    /**
     * Show the form for editing the specified class.
     */
    public function edit(SchoolClass $class)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this class
        if ($adminUser->role === "school_admin" && $class->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $schools = $adminUser->role === "super_admin" 
            ? School::select('id', 'name')->get()
            : School::where('id', $adminUser->school_id)->select('id', 'name')->get();

        $levels = $adminUser->role === "super_admin"
            ? Level::with(['option.section', 'school'])->active()->ordered()->get()
            : Level::where('school_id', $adminUser->school_id)->with('option.section')->active()->ordered()->get();

        return Inertia::render('Admin/Classes/Edit', [
            'class' => $class,
            'schools' => $schools,
            'levels' => $levels,
            'isSuper' => $adminUser->role === "super_admin",
            'admin' => $adminUser,
        ]);
    }

    /**
     * Update the specified class.
     */
    public function update(Request $request, SchoolClass $class)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this class
        if ($adminUser->role === "school_admin" && $class->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:10', Rule::unique('school_classes')->ignore($class->id)],
            'academic_year' => 'required|string|max:20',
            'capacity' => 'nullable|integer|min:1|max:1000',
            'level_id' => 'required|exists:levels,id',
            'school_id' => $adminUser->role === "super_admin" 
                ? 'required|exists:schools,id' 
                : 'nullable',
        ]);

        // Handle school admin restrictions
        if ($adminUser->role === "school_admin") {
            $validated['school_id'] = $adminUser->school_id;
            
            // Verify level belongs to admin's school
            $level = Level::findOrFail($validated['level_id']);
            if ($level->school_id !== $adminUser->school_id) {
                abort(403, 'Level does not belong to your school.');
            }
        } else {
            // Verify level belongs to selected school
            $level = Level::findOrFail($validated['level_id']);
            if ($level->school_id !== $validated['school_id']) {
                return back()->withErrors(['level_id' => 'Level does not belong to the selected school.']);
            }
        }

        // Check capacity constraint
        if ($validated['capacity'] && $class->students()->count() > $validated['capacity']) {
            return back()->withErrors(['capacity' => 'Capacity cannot be less than current student count (' . $class->students()->count() . ').']);
        }

        $class->update($validated);

        return redirect()->route('admin.classes.show', $class)
            ->with('success', 'Class updated successfully!');
    }

    /**
     * Remove the specified class.
     */
    public function destroy(SchoolClass $class)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this class
        if ($adminUser->role === "school_admin" && $class->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        // Check if class has students
        if ($class->students()->exists()) {
            return back()->withErrors(['error' => 'Cannot delete class with enrolled students.']);
        }

        $class->delete();

        return redirect()->route('admin.classes.index')
            ->with('success', 'Class deleted successfully!');
    }

    /**
     * Toggle class active status.
     */
    public function toggleStatus(SchoolClass $class)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this class
        if ($adminUser->role === "school_admin" && $class->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $class->update([
            'is_active' => !$class->is_active
        ]);

        $status = $class->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Class has been {$status} successfully!");
    }
}
