<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Section;
use App\Models\Option;
use App\Models\Level;
use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CascadingDataController extends Controller
{
    /**
     * Get sections for a specific school
     */
    public function getSections(Request $request)
    {
        $request->validate([
            'school_id' => 'required|exists:schools,id'
        ]);

        $sections = Section::where('school_id', $request->school_id)
            ->where('is_active', true)
            ->select('id', 'name', 'code')
            ->orderBy('name')
            ->get();

        return response()->json($sections);
    }

    /**
     * Get options for a specific section
     */
    public function getOptions(Request $request)
    {
        $request->validate([
            'section_id' => 'required|exists:sections,id'
        ]);

        $options = Option::where('section_id', $request->section_id)
            ->where('is_active', true)
            ->select('id', 'name', 'code', 'type')
            ->orderBy('name')
            ->get();

        return response()->json($options);
    }

    /**
     * Get levels for a specific option
     */
    public function getLevels(Request $request)
    {
        $request->validate([
            'option_id' => 'required|exists:options,id'
        ]);

        $levels = Level::where('option_id', $request->option_id)
            ->where('is_active', true)
            ->select('id', 'name', 'code', 'order')
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        return response()->json($levels);
    }

    /**
     * Get classes for a specific level
     */
    public function getClasses(Request $request)
    {
        $request->validate([
            'level_id' => 'required|exists:levels,id'
        ]);

        $classes = SchoolClass::where('level_id', $request->level_id)
            ->where('is_active', true)
            ->select('id', 'name', 'code', 'capacity')
            ->orderBy('name')
            ->get();

        return response()->json($classes);
    }

    /**
     * Get all cascading data for a school (useful for edit forms)
     */
    public function getSchoolData(Request $request)
    {
        $request->validate([
            'school_id' => 'required|exists:schools,id',
            'section_id' => 'nullable|exists:sections,id',
            'option_id' => 'nullable|exists:options,id',
            'level_id' => 'nullable|exists:levels,id',
        ]);

        $data = [];

        // Get sections for the school
        $data['sections'] = Section::where('school_id', $request->school_id)
            ->where('is_active', true)
            ->select('id', 'name', 'code')
            ->orderBy('name')
            ->get();

        // Get options if section is provided
        if ($request->section_id) {
            $data['options'] = Option::where('section_id', $request->section_id)
                ->where('is_active', true)
                ->select('id', 'name', 'code', 'type')
                ->orderBy('name')
                ->get();
        }

        // Get levels if option is provided
        if ($request->option_id) {
            $data['levels'] = Level::where('option_id', $request->option_id)
                ->where('is_active', true)
                ->select('id', 'name', 'code', 'order')
                ->orderBy('order')
                ->orderBy('name')
                ->get();
        }

        // Get classes if level is provided
        if ($request->level_id) {
            $data['classes'] = SchoolClass::where('level_id', $request->level_id)
                ->where('is_active', true)
                ->select('id', 'name', 'code', 'capacity')
                ->orderBy('name')
                ->get();
        }

        return response()->json($data);
    }
}
