<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Level;
use App\Models\School;
use App\Models\Section;
use App\Models\Option;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class LevelController extends Controller
{
    /**
     * Display a listing of levels.
     */
    public function index(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();
        
        $query = Level::with(['school', 'option.section'])
            ->withCount(['classes'])
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
            ->when($request->option_id, function($query, $optionId) {
                $query->where('option_id', $optionId);
            })
            ->when($request->status, function($query, $status) {
                if ($status === 'active') {
                    $query->where('is_active', true);
                } elseif ($status === 'inactive') {
                    $query->where('is_active', false);
                }
            })
            ->orderBy('order');

        // Restrict to school admin's school
        if ($adminUser->role === "school_admin") {
            $query->where('school_id', $adminUser->school_id);
        }

        $levels = $query->paginate(15);

        // Get filter options
        $schools = $adminUser->role === "super_admin" ? School::select('id', 'name')->get() : collect();
        $options = $adminUser->role === "super_admin" 
            ? Option::with(['school', 'section'])->select('id', 'name', 'school_id', 'section_id')->get()
            : Option::where('school_id', $adminUser->school_id)->with('section')->select('id', 'name', 'section_id')->get();

        return Inertia::render('Admin/Levels/Index', [
            'levels' => $levels,
            'schools' => $schools,
            'options' => $options,
            'filters' => $request->only(['search', 'school_id', 'option_id', 'status']),
            'isSuper' => $adminUser->role === "super_admin",
            'admin' => $adminUser,
        ]);
    }

    /**
     * Show the form for creating a new level.
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

        return Inertia::render('Admin/Levels/Create', [
            'schools' => $schools,
            'sections' => $sections,
            'options' => $options,
            'isSuper' => $adminUser->role === "super_admin",
            'admin' => $adminUser,
        ]);
    }

    /**
     * Store a newly created level.
     */
    public function store(Request $request)
    {
        $adminUser = Auth::guard('admin')->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:10|unique:levels',
            'description' => 'nullable|string|max:500',
            'order' => 'required|integer|min:1',
            'option_id' => 'required|exists:options,id',
            'school_id' => $adminUser->role === "super_admin" 
                ? 'required|exists:schools,id' 
                : 'nullable',
        ]);

        // Set school_id for school admin
        if ($adminUser->role === "school_admin") {
            $validated['school_id'] = $adminUser->school_id;
            
            // Verify option belongs to admin's school
            $option = Option::findOrFail($validated['option_id']);
            if ($option->school_id !== $adminUser->school_id) {
                abort(403, 'Option does not belong to your school.');
            }
        } else {
            // Verify option belongs to selected school
            $option = Option::findOrFail($validated['option_id']);
            if ($option->school_id !== $validated['school_id']) {
                return back()->withErrors(['option_id' => 'Option does not belong to the selected school.']);
            }
        }

        $validated['is_active'] = true;

        $level = Level::create($validated);

        return redirect()->route('admin.levels.show', $level)
            ->with('success', 'Level created successfully!');
    }

    /**
     * Display the specified level.
     */
    public function show(Level $level)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this level
        if ($adminUser->role === "school_admin" && $level->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $level->load(['school', 'option.section', 'classes.students']);

        // Get statistics
        $stats = [
            'total_classes' => $level->classes()->count(),
            'active_classes' => $level->classes()->where('is_active', true)->count(),
            'total_students' => $level->classes()->withCount('students')->get()->sum('students_count'),
            'total_capacity' => $level->classes()->sum('capacity'),
        ];

        return Inertia::render('Admin/Levels/Show', [
            'level' => $level,
            'stats' => $stats,
            'admin' => $adminUser,
        ]);
    }

    /**
     * Show the form for editing the specified level.
     */
    public function edit(Level $level)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this level
        if ($adminUser->role === "school_admin" && $level->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $schools = $adminUser->role === "super_admin" 
            ? School::select('id', 'name')->get()
            : School::where('id', $adminUser->school_id)->select('id', 'name')->get();

        $options = $adminUser->role === "super_admin"
            ? Option::with(['school', 'section'])->active()->get()
            : Option::where('school_id', $adminUser->school_id)->with('section')->active()->get();

        return Inertia::render('Admin/Levels/Edit', [
            'level' => $level,
            'schools' => $schools,
            'options' => $options,
            'isSuper' => $adminUser->role === "super_admin",
            'admin' => $adminUser,
        ]);
    }

    /**
     * Update the specified level.
     */
    public function update(Request $request, Level $level)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this level
        if ($adminUser->role === "school_admin" && $level->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:10', Rule::unique('levels')->ignore($level->id)],
            'description' => 'nullable|string|max:500',
            'order' => 'required|integer|min:1',
            'option_id' => 'required|exists:options,id',
            'school_id' => $adminUser->role === "super_admin" 
                ? 'required|exists:schools,id' 
                : 'nullable',
        ]);

        // Handle school admin restrictions
        if ($adminUser->role === "school_admin") {
            $validated['school_id'] = $adminUser->school_id;
            
            // Verify option belongs to admin's school
            $option = Option::findOrFail($validated['option_id']);
            if ($option->school_id !== $adminUser->school_id) {
                abort(403, 'Option does not belong to your school.');
            }
        } else {
            // Verify option belongs to selected school
            $option = Option::findOrFail($validated['option_id']);
            if ($option->school_id !== $validated['school_id']) {
                return back()->withErrors(['option_id' => 'Option does not belong to the selected school.']);
            }
        }

        $level->update($validated);

        return redirect()->route('admin.levels.show', $level)
            ->with('success', 'Level updated successfully!');
    }

    /**
     * Remove the specified level.
     */
    public function destroy(Level $level)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this level
        if ($adminUser->role === "school_admin" && $level->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        // Check if level has classes
        if ($level->classes()->exists()) {
            return back()->withErrors(['error' => 'Cannot delete level with existing classes.']);
        }

        $level->delete();

        return redirect()->route('admin.levels.index')
            ->with('success', 'Level deleted successfully!');
    }

    /**
     * Toggle level active status.
     */
    public function toggleStatus(Level $level)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this level
        if ($adminUser->role === "school_admin" && $level->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $level->update([
            'is_active' => !$level->is_active
        ]);

        $status = $level->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Level has been {$status} successfully!");
    }
}
