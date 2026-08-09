<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Option;
use App\Models\School;
use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class OptionController extends Controller
{
    /**
     * Display a listing of options.
     */
    public function index(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();
        
        $query = Option::with(['school', 'section.school'])
            ->withCount(['levels'])
            ->addSelect([
                'classes_count' => DB::table('school_classes')
                    ->join('levels', 'school_classes.level_id', '=', 'levels.id')
                    ->whereColumn('levels.option_id', 'options.id')
                    ->selectRaw('count(*)'),
                'students_count' => DB::table('students')
                    ->whereColumn('students.option_id', 'options.id')
                    ->selectRaw('count(*)')
            ])
            ->when($request->search, function($query, $search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('type', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->school_id, function($query, $schoolId) {
                $query->where('school_id', $schoolId);
            })
            ->when($request->section_id, function($query, $sectionId) {
                $query->where('section_id', $sectionId);
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

        $options = $query->latest()->paginate(15);

        // Get filter options
        $schools = $adminUser->role === "super_admin" ? School::select('id', 'name')->get() : collect();
        $sections = $adminUser->role === "super_admin" 
            ? Section::with('school')->select('id', 'name', 'school_id')->get()
            : Section::where('school_id', $adminUser->school_id)->select('id', 'name', 'school_id')->get();

        return Inertia::render('Admin/Options/Index', [
            'options' => $options,
            'schools' => $schools,
            'sections' => $sections,
            'filters' => $request->only(['search', 'school_id', 'section_id', 'status']),
            'isSuper' => $adminUser->role === "super_admin",
            'admin' => $adminUser,
        ]);
    }

    /**
     * Show the form for creating a new option.
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

        return Inertia::render('Admin/Options/Create', [
            'schools' => $schools,
            'sections' => $sections,
            'isSuper' => $adminUser->role === "super_admin",
            'admin' => $adminUser,
        ]);
    }

    /**
     * Store a newly created option.
     */
    public function store(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:10|unique:options',
            'type' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
            'section_id' => 'required|exists:sections,id',
            'school_id' => $adminUser->role === "super_admin" 
                ? 'required|exists:schools,id' 
                : 'nullable',
        ]);

        // Set school_id for school admin
        if ($adminUser->role === "school_admin") {
            $validated['school_id'] = $adminUser->school_id;
            
            // Verify section belongs to admin's school
            $section = Section::findOrFail($validated['section_id']);
            if ($section->school_id !== $adminUser->school_id) {
                abort(403, 'Section does not belong to your school.');
            }
        } else {
            // Verify section belongs to selected school
            $section = Section::findOrFail($validated['section_id']);
            if ($section->school_id !== $validated['school_id']) {
                return back()->withErrors(['section_id' => 'Section does not belong to the selected school.']);
            }
        }

        $validated['is_active'] = true;

        $option = Option::create($validated);

        return redirect()->route('admin.options.show', $option)
            ->with('success', 'Option created successfully!');
    }

    /**
     * Display the specified option.
     */
    public function show(Option $option)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this option
        if ($adminUser->role === "school_admin" && $option->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $option->load(['school', 'section', 'levels.classes']);

        // Get statistics
        $stats = [
            'total_levels' => $option->levels()->count(),
            'active_levels' => $option->levels()->where('is_active', true)->count(),
            'total_classes' => $option->levels()->withCount('classes')->get()->sum('classes_count'),
            'total_students' => $option->levels()->with('classes.students')->get()
                ->pluck('classes')->flatten()->sum(fn($class) => $class->students->count()),
        ];

        return Inertia::render('Admin/Options/Show', [
            'option' => $option,
            'stats' => $stats,
            'admin' => $adminUser,
        ]);
    }

    /**
     * Show the form for editing the specified option.
     */
    public function edit(Option $option)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this option
        if ($adminUser->role === "school_admin" && $option->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $schools = $adminUser->role === "super_admin" 
            ? School::select('id', 'name')->get()
            : School::where('id', $adminUser->school_id)->select('id', 'name')->get();

        $sections = $adminUser->role === "super_admin"
            ? Section::with('school')->active()->get()
            : Section::where('school_id', $adminUser->school_id)->active()->get();

        return Inertia::render('Admin/Options/Edit', [
            'option' => $option,
            'schools' => $schools,
            'sections' => $sections,
            'isSuper' => $adminUser->role === "super_admin",
            'admin' => $adminUser,
        ]);
    }

    /**
     * Update the specified option.
     */
    public function update(Request $request, Option $option)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this option
        if ($adminUser->role === "school_admin" && $option->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:10', Rule::unique('options')->ignore($option->id)],
            'type' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
            'section_id' => 'required|exists:sections,id',
            'school_id' => $adminUser->role === "super_admin" 
                ? 'required|exists:schools,id' 
                : 'nullable',
        ]);

        // Handle school admin restrictions
        if ($adminUser->role === "school_admin") {
            $validated['school_id'] = $adminUser->school_id;
            
            // Verify section belongs to admin's school
            $section = Section::findOrFail($validated['section_id']);
            if ($section->school_id !== $adminUser->school_id) {
                abort(403, 'Section does not belong to your school.');
            }
        } else {
            // Verify section belongs to selected school
            $section = Section::findOrFail($validated['section_id']);
            if ($section->school_id !== $validated['school_id']) {
                return back()->withErrors(['section_id' => 'Section does not belong to the selected school.']);
            }
        }

        $option->update($validated);

        return redirect()->route('admin.options.show', $option)
            ->with('success', 'Option updated successfully!');
    }

    /**
     * Remove the specified option.
     */
    public function destroy(Option $option)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this option
        if ($adminUser->role === "school_admin" && $option->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        // Check if option has levels
        if ($option->levels()->exists()) {
            return back()->withErrors(['error' => 'Cannot delete option with existing levels.']);
        }

        $option->delete();

        return redirect()->route('admin.options.index')
            ->with('success', 'Option deleted successfully!');
    }

    /**
     * Toggle option active status.
     */
    public function toggleStatus(Option $option)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this option
        if ($adminUser->role === "school_admin" && $option->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $option->update([
            'is_active' => !$option->is_active
        ]);

        $status = $option->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Option has been {$status} successfully!");
    }
}
