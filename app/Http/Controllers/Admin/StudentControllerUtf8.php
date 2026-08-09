<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Level;
use App\Models\Option;
use App\Services\PersonnelManagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use PhpOffice\PhpSpreadsheet\IOFactory;

class StudentControllerUtf8 extends Controller
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
                      ->orWhere('student_number', 'like', "%{$search}%")
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
            'admin' => $adminUser,
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
            'admin' => $adminUser,
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
            'middle_name' => 'nullable|string|max:255',
            'student_number' => 'required|string|max:50|unique:students',
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
            'guardian_name' => 'nullable|string|max:255',
            'guardian_phone' => 'nullable|string|max:20',
            'medical_info' => 'nullable|string|max:1000',
            'photo' => 'nullable|image|mimes:jpeg,jpg,png|max:1024', // 1MB max
        ]);

        // Set school_id for school admin
        if ($adminUser->role === "school_admin") {
            $validated['school_id'] = $adminUser->school_id;
        }

        // Generate unique biometric_id for device synchronization
        $validated['biometric_id'] = $this->generateBiometricId($validated['school_id']);

        // Handle photo upload with dual storage (file + base64)
        if ($request->hasFile('photo')) {
            $photoData = $this->handlePhotoUploadWithBase64($request->file('photo'), $validated['school_id']);
            $validated['photo'] = $photoData['file_path'];
            $validated['photo_base64'] = $photoData['base64'];
        }

        // Set enrollment date if not provided
        if (!isset($validated['enrollment_date'])) {
            $validated['enrollment_date'] = now();
        }

        $student = Student::create($validated);

        // Automatically generate parent link code (valid for 30 days)
        $student->generateParentLinkCode(30);

        // Sync student to all devices in the school
        try {
            $personnelService = new PersonnelManagementService();
            $syncResults = $personnelService->syncStudentToSchool($student);
            
            $successCount = collect($syncResults)->where('success', true)->count();
            $totalDevices = count($syncResults);
            
            if ($successCount > 0) {
                $message = "Student created successfully! Synced to {$successCount}/{$totalDevices} devices.";
            } else {
                $message = "Student created successfully! Note: Device synchronization failed. Please sync manually.";
            }
            
            return redirect()->route('admin.students.show', $student)
                ->with('success', $message);
                
        } catch (\Exception $e) {
            // Student created but sync failed
            return redirect()->route('admin.students.show', $student)
                ->with('warning', 'Student created successfully, but device synchronization failed: ' . $e->getMessage());
        }
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
            'admin' => $adminUser,
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
            'admin' => $adminUser,
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
            'middle_name' => 'nullable|string|max:255',
            'student_number' => ['required', 'string', 'max:50', Rule::unique('students')->ignore($student->id)],
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
            'guardian_name' => 'nullable|string|max:255',
            'guardian_phone' => 'nullable|string|max:20',
            'medical_info' => 'nullable|string|max:1000',
            'photo' => 'nullable|image|mimes:jpeg,jpg,png|max:1024',
            'is_active' => 'nullable|boolean',
        ]);

        // Ensure school admin can't change school
        if ($adminUser->role === "school_admin") {
            $validated['school_id'] = $adminUser->school_id;
        }

        // Handle photo upload with dual storage (file + base64)
        if ($request->hasFile('photo')) {
            // Delete old photo file if exists
            if ($student->photo) {
                Storage::disk('public')->delete($student->photo);
            }
            
            $photoData = $this->handlePhotoUploadWithBase64($request->file('photo'), $student->school_id);
            $validated['photo'] = $photoData['file_path'];
            $validated['photo_base64'] = $photoData['base64'];
        }

        $student->update($validated);

        // Sync updated student data to all devices in the school
        try {
            $personnelService = new PersonnelManagementService();
            $syncResults = $personnelService->syncStudentToSchool($student);
            
            $successCount = collect($syncResults)->where('success', true)->count();
            $totalDevices = count($syncResults);
            
            if ($successCount > 0) {
                $message = "Student updated successfully! Synced to {$successCount}/{$totalDevices} devices.";
            } else {
                $message = "Student updated successfully! Note: Device synchronization failed. Please sync manually.";
            }
            
            return redirect()->route('admin.students.show', $student)
                ->with('success', $message);
                
        } catch (\Exception $e) {
            return redirect()->route('admin.students.show', $student)
                ->with('warning', 'Student updated successfully, but device synchronization failed: ' . $e->getMessage());
        }
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

        // Store student info for device sync before deletion
        $studentBiometricId = $student->biometric_id;
        $studentSchoolId = $student->school_id;
        $studentName = $student->full_name;

        // Soft delete the student
        $student->delete();

        // Remove student from all devices in the school
        if ($studentBiometricId) {
            try {
                // Create a temporary student object for deletion
                $tempStudent = new Student();
                $tempStudent->biometric_id = $studentBiometricId;
                $tempStudent->school_id = $studentSchoolId;
                $tempStudent->first_name = $studentName;
                $tempStudent->last_name = '';
                
                $personnelService = new PersonnelManagementService();
                $deleteResults = $personnelService->syncStudentToSchool($tempStudent);
                
                $successCount = collect($deleteResults)->where('success', true)->count();
                $totalDevices = count($deleteResults);
                
                if ($successCount > 0) {
                    $message = "Student deleted successfully! Removed from {$successCount}/{$totalDevices} devices.";
                } else {
                    $message = "Student deleted successfully! Note: Device removal failed. Please remove manually.";
                }
                
                return redirect()->route('admin.students.index')
                    ->with('success', $message);
                    
            } catch (\Exception $e) {
                return redirect()->route('admin.students.index')
                    ->with('warning', 'Student deleted successfully, but device removal failed: ' . $e->getMessage());
            }
        }

        return redirect()->route('admin.students.index')
            ->with('success', 'Student deleted successfully!');
    }

    /**
     * Show the import form
     */
    public function import()
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

        return Inertia::render('Admin/Students/Import', [
            'schools' => $schools,
            'sections' => $sections,
            'options' => $options,
            'levels' => $levels,
            'isSuper' => $adminUser->role === "super_admin",
            'admin' => $adminUser,
        ]);
    }

    /**
     * Process the import
     */
    public function processImport(Request $request)
    {
        Log::info('=== IMPORT PROCESS STARTED ===');
        
        try {
            $adminUser = Auth::guard('admin')->user();
            Log::info('Admin user found', ['admin_id' => $adminUser->id, 'role' => $adminUser->role]);

            // Log the request data for debugging
            Log::info('Import request received', [
                'has_excel' => $request->hasFile('excel_file'),
                'has_images' => $request->hasFile('student_images'),
                'school_id' => $request->input('school_id'),
                'section_id' => $request->input('section_id'),
                'option_id' => $request->input('option_id'),
                'level_id' => $request->input('level_id'),
                'class_id' => $request->input('class_id'),
                'admin_role' => $adminUser->role,
                'admin_school_id' => $adminUser->school_id,
                'files_in_request' => array_keys($request->allFiles()),
                'all_input_keys' => array_keys($request->all()),
                'student_images_count' => $request->hasFile('student_images') ? count($request->file('student_images')) : 0,
            ]);

            Log::info('About to validate import request', [
                'request_method' => $request->method(),
                'content_type' => $request->header('Content-Type'),
                'has_files' => !empty($request->allFiles()),
            ]);

            $validated = $request->validate([
                'school_id' => $adminUser->role === "super_admin" ? 'required|exists:schools,id' : 'nullable',
                'section_id' => 'required|exists:sections,id',
                'option_id' => 'required|exists:options,id',
                'level_id' => 'required|exists:levels,id',
                'class_id' => 'required|exists:school_classes,id',
                'excel_file' => 'required|file|mimes:xlsx,xls,csv|max:10240', // 10MB max
                'student_images' => 'nullable|array',
                'student_images.*' => 'image|mimes:jpeg,jpg,png,gif,bmp,webp|max:2048', // 2MB max per image
            ]);

            Log::info('Validation passed', [
                'validated_data_keys' => array_keys($validated),
                'has_student_images' => isset($validated['student_images']),
                'student_images_count' => isset($validated['student_images']) ? count($validated['student_images']) : 0,
            ]);

            // Set school_id for school admin
            if ($adminUser->role === "school_admin") {
                $validated['school_id'] = $adminUser->school_id;
            }

            Log::info('Processing files', [
                'school_id' => $validated['school_id'],
                'class_id' => $validated['class_id'],
            ]);

            // Get the class details
            $class = SchoolClass::with(['level.option.section'])->findOrFail($validated['class_id']);

            Log::info('Class found', ['class_name' => $class->name]);

            // Process Excel file
            Log::info('=== PROCESSING EXCEL FILE ===');
            $students = $this->processExcelFile($request->file('excel_file'));

            Log::info('=== EXCEL PROCESSED ===', [
                'student_count' => count($students), 
                'students' => $students,
                'isEmpty' => empty($students)
            ]);

            if (empty($students)) {
                Log::warning('=== NO STUDENTS FOUND IN EXCEL FILE ===');
                return back()->withErrors(['excel_file' => 'No students found in the Excel file. Please check the file format.'])->withInput();
            }

            // Process images if provided
            $images = [];
            if ($request->hasFile('student_images') && is_array($request->file('student_images'))) {
                Log::info('Processing multiple student images');
                $images = $this->processMultipleImages($request->file('student_images'), $validated['school_id']);
                Log::info('Images processed', ['image_count' => count($images)]);
            } else {
                Log::info('No student images to process', [
                    'hasFile' => $request->hasFile('student_images'),
                    'isArray' => $request->hasFile('student_images') ? is_array($request->file('student_images')) : false
                ]);
            }

            // Get the current year for student ID generation
            $currentYear = date('Y');

            // Get the last student number for this school - properly handle numeric conversion
            $lastStudent = Student::where('school_id', $validated['school_id'])
                ->orderByRaw('CAST(student_number AS UNSIGNED) DESC')
                ->first();
            
            $startingNumber = 1;
            if ($lastStudent && is_numeric($lastStudent->student_number)) {
                $startingNumber = (int)$lastStudent->student_number + 1;
            }

            Log::info('Student numbering info', [
                'last_student_id' => $lastStudent?->id,
                'last_student_number' => $lastStudent?->student_number,
                'starting_number' => $startingNumber,
            ]);

            $importedCount = 0;
            $errors = [];

            Log::info('Starting student creation loop', ['total_students' => count($students)]);

            foreach ($students as $index => $studentName) {
                try {
                    Log::info("=== PROCESSING STUDENT {$index} ===", ['name' => $studentName]);
                    
                    // Generate unique student number
                    $studentNumber = str_pad($startingNumber + $index, 3, '0', STR_PAD_LEFT);
                    
                    // Double-check for uniqueness
                    while (Student::where('student_number', $studentNumber)->exists()) {
                        $startingNumber++;
                        $studentNumber = str_pad($startingNumber + $index, 3, '0', STR_PAD_LEFT);
                        Log::warning("Student number {$studentNumber} already exists, incrementing");
                    }

                    Log::info("Generated unique student number", ['student_number' => $studentNumber]);

                    // Create student data
                    $studentData = [
                        'school_id' => $validated['school_id'],
                        'class_id' => $validated['class_id'],
                        'section_id' => $validated['section_id'],
                        'option_id' => $validated['option_id'],
                        'level_id' => $validated['level_id'],
                        'first_name' => trim($studentName),
                        'last_name' => '', // Empty as per requirement
                        'student_number' => $studentNumber,
                        'is_active' => true,
                        'enrollment_date' => now(),
                        'biometric_id' => $this->generateBiometricId($validated['school_id']),
                        'gender' => 'male', // Default gender, can be updated later
                    ];

                    // Verify foreign key constraints before creating
                    Log::info("Verifying foreign key constraints", [
                        'school_exists' => DB::table('schools')->where('id', $validated['school_id'])->exists(),
                        'class_exists' => DB::table('school_classes')->where('id', $validated['class_id'])->exists(),
                        'section_exists' => DB::table('sections')->where('id', $validated['section_id'])->exists(),
                        'option_exists' => DB::table('options')->where('id', $validated['option_id'])->exists(),
                        'level_exists' => DB::table('levels')->where('id', $validated['level_id'])->exists(),
                    ]);

                    Log::info("Student data prepared", ['student_data' => $studentData]);

                    // Handle image if available
                    if (isset($images[$index])) {
                        Log::info("Processing image for student {$index}");
                        $photoData = $this->saveUploadedImage($images[$index], $validated['school_id']);
                        $studentData['photo'] = $photoData['file_path'];
                        $studentData['photo_base64'] = $photoData['base64'];
                        Log::info("Image processed successfully for student {$index}");
                    }

                    // Create the student
                    Log::info("=== ABOUT TO CREATE STUDENT IN DATABASE ===", ['data' => $studentData]);
                    $student = Student::create($studentData);
                    Log::info("=== STUDENT CREATED SUCCESSFULLY ===", ['student_id' => $student->id, 'student_number' => $student->student_number]);

                    // Generate parent link code
                    Log::info("Generating parent link code for student {$student->id}");
                    $student->generateParentLinkCode(30);
                    Log::info("Parent link code generated successfully");

                    $importedCount++;
                    Log::info("Imported count incremented to: {$importedCount}");

                } catch (\Exception $e) {
                    Log::error("=== ERROR CREATING STUDENT {$index} ===", [
                        'student_name' => $studentName,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'student_data' => isset($studentData) ? $studentData : 'Not set'
                    ]);
                    $errors[] = "Row " . ($index + 1) . ": " . $e->getMessage();
                }
            }

            Log::info('Import completed', [
                'imported_count' => $importedCount,
                'error_count' => count($errors),
                'errors' => $errors,
            ]);

            // Sync all imported students to devices
            if ($importedCount > 0) {
                try {
                    Log::info('Starting device sync for imported students');
                    $personnelService = new PersonnelManagementService();
                    $importedStudents = Student::where('school_id', $validated['school_id'])
                        ->where('class_id', $validated['class_id'])
                        ->latest()
                        ->limit($importedCount)
                        ->get();

                    foreach ($importedStudents as $student) {
                        // Use retry mechanism for better device compatibility
                        $syncResults = $personnelService->syncStudentToSchool($student);
                        Log::info('Student sync completed', [
                            'student_id' => $student->id,
                            'student_name' => $student->full_name,
                            'sync_results' => $syncResults,
                        ]);
                    }
                    Log::info('Device sync completed');
                } catch (\Exception $e) {
                    // Log but don't fail the import
                    Log::warning('Device sync failed during import: ' . $e->getMessage());
                }
            }

            $message = "Successfully imported {$importedCount} student(s)!";
            if (count($errors) > 0) {
                $message .= " " . count($errors) . " error(s) occurred.";
            }

            Log::info('Redirecting with success message', ['message' => $message]);

            return redirect()->route('admin.students.index')
                ->with('success', $message)
                ->with('import_errors', $errors);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation failed during import', [
                'errors' => $e->errors(),
                'message' => $e->getMessage()
            ]);
            return back()->withErrors($e->errors())->withInput();
        } catch (\Exception $e) {
            Log::error('Import failed with exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return back()->withErrors(['error' => 'Import failed: ' . $e->getMessage()])->withInput();
        }
    }

    /**
     * Process Excel file and extract student names
     */
    private function processExcelFile($file): array
    {
        Log::info('Processing Excel file', [
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);

        $extension = $file->getClientOriginalExtension();
        $students = [];

        if ($extension === 'csv') {
            Log::info('Processing as CSV file');
            // Handle CSV
            $handle = fopen($file->getRealPath(), 'r');
            $rowCount = 0;
            while (($row = fgetcsv($handle)) !== false) {
                $rowCount++;
                if (!empty($row[0])) {
                    $students[] = $row[0];
                    Log::debug("CSV Row {$rowCount}: " . $row[0]);
                }
            }
            fclose($handle);
        } else {
            Log::info('Processing as Excel file');
            // Handle Excel (xlsx, xls)
            require_once base_path('vendor/autoload.php');
            
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
                $worksheet = $spreadsheet->getActiveSheet();
                
                $rowCount = 0;
                foreach ($worksheet->getRowIterator() as $row) {
                    $rowCount++;
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(false);
                    
                    foreach ($cellIterator as $cell) {
                        $value = $cell->getValue();
                        if (!empty($value)) {
                            $students[] = $value;
                            Log::debug("Excel Row {$rowCount}: " . $value);
                        }
                        break; // Only first column
                    }
                }
            } catch (\Exception $e) {
                Log::error('Error processing Excel file', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
        }

        $filteredStudents = array_filter($students); // Remove empty values
        Log::info('Excel processing completed', [
            'total_raw_entries' => count($students),
            'filtered_entries' => count($filteredStudents),
            'students' => $filteredStudents,
        ]);

        return $filteredStudents;
    }

    /**
     * Process multiple uploaded images
     */
    private function processMultipleImages(array $imageFiles, int $schoolId): array
    {
        Log::info('Processing multiple images', [
            'image_count' => count($imageFiles),
            'school_id' => $schoolId,
        ]);

        $processedImages = [];

        // Sort images alphabetically by filename
        usort($imageFiles, function($a, $b) {
            return strcasecmp($a->getClientOriginalName(), $b->getClientOriginalName());
        });

        $sortedNames = array_map(function($file) {
            return $file->getClientOriginalName();
        }, $imageFiles);

        Log::info('Images sorted alphabetically', [
            'sorted_order' => $sortedNames,
        ]);

        foreach ($imageFiles as $index => $imageFile) {
            try {
                Log::info("Processing image {$index}", [
                    'filename' => $imageFile->getClientOriginalName(),
                    'size' => $imageFile->getSize(),
                    'mime_type' => $imageFile->getMimeType(),
                ]);

                // Validate image
                if (!$imageFile->isValid()) {
                    Log::warning("Invalid image file: {$imageFile->getClientOriginalName()}");
                    continue;
                }

                // Check if it's actually an image
                $imageInfo = @getimagesize($imageFile->getRealPath());
                if ($imageInfo === false) {
                    Log::warning("Not a valid image: {$imageFile->getClientOriginalName()}");
                    continue;
                }

                // Store the uploaded file directly (no temp processing needed)
                $processedImages[$index] = $imageFile;

                Log::debug("Image validated successfully", [
                    'index' => $index,
                    'filename' => $imageFile->getClientOriginalName(),
                    'dimensions' => $imageInfo[0] . 'x' . $imageInfo[1],
                ]);

            } catch (\Exception $e) {
                Log::error("Error processing image {$imageFile->getClientOriginalName()}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Multiple images processing completed', [
            'processed_count' => count($processedImages),
        ]);

        return $processedImages;
    }

    /**
     * Save uploaded image and convert to base64
     */
    private function saveUploadedImage($imageFile, int $schoolId): array
    {
        Log::info('Saving uploaded image', [
            'filename' => $imageFile->getClientOriginalName(),
            'school_id' => $schoolId,
        ]);

        try {
            // Create directory structure
            $directory = "students/photos/{$schoolId}";
            $extension = $imageFile->getClientOriginalExtension();
            $filename = Str::random(40) . '.' . $extension;
            
            // Store the uploaded file
            $filePath = $imageFile->storeAs($directory, $filename, 'public');
            
            if (!$filePath) {
                throw new \Exception("Failed to store image file");
            }

            // Convert to base64
            $imageData = file_get_contents($imageFile->getRealPath());
            if ($imageData === false) {
                throw new \Exception("Failed to read image data");
            }

            $mimeType = $imageFile->getMimeType();
            $base64 = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
            
            Log::info('Image saved successfully', [
                'original_filename' => $imageFile->getClientOriginalName(),
                'storage_path' => $filePath,
                'file_size' => strlen($imageData),
            ]);
            
            return [
                'file_path' => $filePath,
                'base64' => $base64
            ];

        } catch (\Exception $e) {
            Log::error('Error saving uploaded image', [
                'filename' => $imageFile->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
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

    /**
     * Generate unique biometric ID for device synchronization
     */
    private function generateBiometricId(int $schoolId): string
    {
        do {
            // Format: STU_SCHOOLID_TIMESTAMP_RANDOM
            $biometricId = 'STU_' . $schoolId . '_' . now()->timestamp . '_' . Str::random(8);
        } while (Student::where('biometric_id', $biometricId)->exists());

        return $biometricId;
    }

    /**
     * Handle photo upload and return storage path
     */
    private function handlePhotoUpload($photo, int $schoolId): string
    {
        // Create directory structure: students/photos/{school_id}/
        $directory = "students/photos/{$schoolId}";
        
        // Generate unique filename
        $filename = Str::random(40) . '.' . $photo->getClientOriginalExtension();
        
        // Store the photo
        $path = $photo->storeAs($directory, $filename, 'public');
        
        return $path;
    }

    /**
     * Manually sync student to devices
     */
    public function syncToDevices(Student $student)
    {
        $adminUser = Auth::guard('admin')->user();

        Log::info('=== SYNC TO DEVICES STARTED ===', [
            'student_id' => $student->id,
            'student_name' => $student->full_name,
            'school_id' => $student->school_id,
            'admin_user' => $adminUser->id,
            'admin_role' => $adminUser->role,
        ]);

        // Check if school admin can access this student
        if ($adminUser->role === "school_admin" && $student->school_id !== $adminUser->school_id) {
            Log::warning('Access denied for school admin', [
                'admin_school_id' => $adminUser->school_id,
                'student_school_id' => $student->school_id,
            ]);
            abort(403, 'Access denied.');
        }

        try {
            // Check if there are any devices in the school
            $deviceCount = \App\Models\BiometricDevice::where('school_id', $student->school_id)
                ->where('is_active', true)
                ->count();

            Log::info('Found devices in school', [
                'device_count' => $deviceCount,
                'school_id' => $student->school_id,
            ]);

            if ($deviceCount === 0) {
                Log::warning('No active devices found in school');
                return back()->withErrors(['error' => 'No active devices found in this school. Please add and activate devices first.']);
            }

            Log::info('Creating PersonnelManagementService');
            $personnelService = new PersonnelManagementService();
            
            Log::info('Calling syncStudentToSchool');
            $syncResults = $personnelService->syncStudentToSchool($student);
            
            Log::info('Sync results received', [
                'results_count' => count($syncResults),
                'sync_results' => $syncResults,
            ]);
            
            $successCount = collect($syncResults)->where('success', true)->count();
            $totalDevices = count($syncResults);
            
            Log::info('Sync completed', [
                'success_count' => $successCount,
                'total_devices' => $totalDevices,
            ]);
            
            if ($successCount > 0) {
                return back()->with('success', "Synced to {$successCount}/{$totalDevices} devices successfully!");
            } else {
                $errors = collect($syncResults)->pluck('error')->filter()->join(', ');
                return back()->withErrors(['error' => "Sync failed: {$errors}"]);
            }
            
        } catch (\Exception $e) {
            Log::error('Sync failed with exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->withErrors(['error' => 'Device synchronization failed: ' . $e->getMessage()]);
        }
    }

    /**
     * Get device sync status for a student
     */
    public function syncStatus(Student $student)
    {
        $adminUser = Auth::guard('admin')->user();

        // Check if school admin can access this student
        if ($adminUser->role === "school_admin" && $student->school_id !== $adminUser->school_id) {
            abort(403, 'Access denied.');
        }

        $devices = \App\Models\BiometricDevice::where('school_id', $student->school_id)
            ->select('id', 'name', 'device_id', 'is_active', 'last_heartbeat')
            ->get();

        return response()->json([
            'student' => [
                'id' => $student->id,
                'name' => $student->full_name,
                'biometric_id' => $student->biometric_id,
            ],
            'devices' => $devices,
            'total_devices' => $devices->count(),
            'online_devices' => $devices->where('is_active', true)->count(),
        ]);
    }

    /**
     * Convert uploaded file to base64 format
     */
    private function convertFileToBase64($file): string
    {
        $imageData = file_get_contents($file->getRealPath());
        $mimeType = $file->getMimeType();
        
        return 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
    }

    /**
     * Handle photo upload with dual storage (file + base64)
     */
    private function handlePhotoUploadWithBase64($photo, $schoolId): array
    {
        // Handle file upload (existing functionality)
        $filePath = $this->handlePhotoUpload($photo, $schoolId);
        
        // Convert to base64
        $base64 = $this->convertFileToBase64($photo);
        
        return [
            'file_path' => $filePath,
            'base64' => $base64
        ];
    }
}
