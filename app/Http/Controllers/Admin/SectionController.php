<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SectionController extends Controller
{
    /**
     * Display a listing of sections.
     */
    public function index(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();
        
        $query = Section::with(['school'])
            ->withCount([
                'options',
            ])
            ->addSelect([
                'levels_count' => DB::table('levels')
                    ->join('options', 'levels.option_id', '=', 'options.id')
                    ->whereColumn('options.section_id', 'sections.id')
                    ->selectRaw('count(*)'),
                'classes_count' => DB::table('school_classes')
                    ->join('levels', 'school_classes.level_id', '=', 'levels.id')
                    ->join('options', 'levels.option_id', '=', 'options.id')
                    ->whereColumn('options.section_id', 'sections.id')
                    ->selectRaw('count(*)'),
                'students_count' => DB::table('students')
                    ->whereColumn('students.section_id', 'sections.id')
                    ->selectRaw('count(*)')
            ])
            ->when($request->search, function($query, $search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->school_id, function($query, $schoolId) {
                $query->where('school_id', $schoolId);
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

        $sections = $query->latest()->paginate(15);

        // Get filter options
        $schools = $adminUser->role === "super_admin" ? School::select('id', 'name')->get() : collect();

        return Inertia::render('Admin/Sections/Index', [
            'sections' => $sections,
            'schools' => $schools,
            'filters' => $request->only(['search', 'school_id', 'status']),
            'isSuper' => $adminUser->role === "super_admin",
            'admin' => $adminUser,
        ]);
    }

    /**
     * Show the form for creating a new section.
     */
    public function create()
    {
        $adminUser = Auth::guard('admin')->user();

        $schools = $adminUser->role === "super_admin" 
            ? School::select('id', 'name')->get()
            : School::where('id', $adminUser->school_id)->select('id', 'name')->get();

        return Inertia::render('Admin/Sections/Create', [
            'schools' => $schools,
            'isSuper' => $adminUser->role === "super_admin",
            'admin' => $adminUser,
        ]);
    }

    /**
     * Store a newly created section.
     */
    public function store(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:10|unique:sections',
            'description' => 'nullable|string|max:500',
            'school_id' => $adminUser->role === "super_admin" 
                ? 'required|exists:schools,id' 
                : 'nullable',
        ]);

        // Set school_id for school admin
        if ($adminUser->role === "school_admin") {
            $validated['school_id'] = $adminUser->school_id;
        }

        $validated['is_active'] = true;

        $section = Section::create($validated);

        return redirect()->route('admin.sections.show', $section)
            ->with('success', 'Section created successfully!');
    }

    /**
     * Display the specified section.
     */
    public function show(Section $section)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this section
        if ($adminUser->role === "school_admin" && $section->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        // Load section with school and options with their counts
        $section->load(['school']);
        
        // Load options with counts
        $section->load(['options' => function($query) {
            $query->withCount('levels')
                ->addSelect([
                    'classes_count' => DB::table('school_classes')
                        ->join('levels', 'school_classes.level_id', '=', 'levels.id')
                        ->whereColumn('levels.option_id', 'options.id')
                        ->selectRaw('count(*)'),
                    'students_count' => DB::table('students')
                        ->whereColumn('students.option_id', 'options.id')
                        ->selectRaw('count(*)')
                ]);
        }]);

        // Get statistics
        $stats = [
            'total_options' => $section->options()->count(),
            'active_options' => $section->options()->where('is_active', true)->count(),
            'total_levels' => $section->options()->withCount('levels')->get()->sum('levels_count'),
            'total_classes' => $section->classes()->count(),
        ];

        return Inertia::render('Admin/Sections/Show', [
            'section' => $section,
            'stats' => $stats,
            'admin' => $adminUser,
        ]);
    }

    /**
     * Show the form for editing the specified section.
     */
    public function edit(Section $section)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this section
        if ($adminUser->role === "school_admin" && $section->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $schools = $adminUser->role === "super_admin" 
            ? School::select('id', 'name')->get()
            : School::where('id', $adminUser->school_id)->select('id', 'name')->get();

        return Inertia::render('Admin/Sections/Edit', [
            'section' => $section,
            'schools' => $schools,
            'isSuper' => $adminUser->role === "super_admin",
            'admin' => $adminUser,
        ]);
    }

    /**
     * Update the specified section.
     */
    public function update(Request $request, Section $section)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this section
        if ($adminUser->role === "school_admin" && $section->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:10', Rule::unique('sections')->ignore($section->id)],
            'description' => 'nullable|string|max:500',
            'school_id' => $adminUser->role === "super_admin" 
                ? 'required|exists:schools,id' 
                : 'nullable',
        ]);

        // Ensure school admin can't change school
        if ($adminUser->role === "school_admin") {
            $validated['school_id'] = $adminUser->school_id;
        }

        $section->update($validated);

        return redirect()->route('admin.sections.show', $section)
            ->with('success', 'Section updated successfully!');
    }

    /**
     * Remove the specified section.
     */
    public function destroy(Section $section)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this section
        if ($adminUser->role === "school_admin" && $section->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        // Check if section has options
        if ($section->options()->exists()) {
            return back()->withErrors(['error' => 'Cannot delete section with existing options.']);
        }

        $section->delete();

        return redirect()->route('admin.sections.index')
            ->with('success', 'Section deleted successfully!');
    }

    /**
     * Toggle section active status.
     */
    public function toggleStatus(Section $section)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this section
        if ($adminUser->role === "school_admin" && $section->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $section->update([
            'is_active' => !$section->is_active
        ]);

        $status = $section->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Section has been {$status} successfully!");
    }
}
