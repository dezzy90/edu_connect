# Students CRUD Implementation Plan

## Overview
Complete implementation of Students CRUD operations with automatic device synchronization via MQTT for the rod-connect attendance system.

---

## Documentation References
- **Device Protocol**: `STUDENT_DEVICE_INTEGRATION_GUIDE.md`
- **Error Codes**: `DEVICE_ERROR_CODES.md`
- **Schools CRUD**: `SCHOOLS_CRUD_IMPLEMENTATION.md` (completed)

---

## Database Schema

### Students Table
Based on migration: `database/migrations/2025_09_26_033319_create_students_table.php`

**Key Fields:**
- `id`: Primary key
- `school_id`: Foreign key to schools
- `school_class_id`: Foreign key to school_classes
- `student_id`: Unique student identifier
- `biometric_id`: Unique ID for device sync (customId)
- `first_name`, `last_name`: Student names
- `gender`: male/female
- `date_of_birth`: Birth date
- `photo`: Student photo path
- `parent_phone`: Contact number
- `address`: Residential address
- `is_active`: Active status
- `timestamps`, `softDeletes`

---

## Implementation Strategy

### Phase 1: Backend Controller (StudentController)
**File**: `app/Http/Controllers/Admin/StudentControllerUtf8.php`

#### Methods to Implement:

1. **index()** - List all students
   - Filter by school (school admins see only their school)
   - Super admins see all schools
   - Pagination, search, filters
   - Load relationships: school, class, parents

2. **create()** - Show create form
   - Load schools (super admin only)
   - Load classes for selected school
   - Load sections, levels, options

3. **store()** - Create new student
   - Validate input
   - Generate unique `biometric_id`
   - Save student to database
   - **Sync to ALL school devices via PersonnelManagementService**
   - Handle photo upload
   - Return success/error

4. **show()** - View single student
   - Load full student details
   - Load relationships
   - Show device sync status
   - Show attendance history

5. **edit()** - Show edit form
   - Load student data
   - Load available classes
   - Pre-fill form

6. **update()** - Update student
   - Validate changes
   - Update database
   - **Sync changes to ALL school devices**
   - Handle photo changes
   - Return success/error

7. **destroy()** - Delete student (soft delete)
   - Soft delete from database
   - **Remove from ALL school devices**
   - Log deletion
   - Return success/error

---

### Phase 2: Device Integration Service

**Service**: `app/Services/PersonnelManagementService.php`

#### Methods Needed:

```php
class PersonnelManagementService
{
    /**
     * Sync student to all devices in their school
     */
    public function syncStudentToDevices(Student $student): array
    {
        // Get all active devices for student's school
        // For each device:
        //   - Build EditPerson MQTT message
        //   - Publish to mqtt/face/{DEVICE_ID}
        //   - Listen for Ack on mqtt/face/{DEVICE_ID}/Ack
        //   - Log result
        // Return sync results
    }

    /**
     * Remove student from all devices in their school
     */
    public function removeStudentFromDevices(Student $student): array
    {
        // Get all active devices for student's school
        // For each device:
        //   - Build DelPerson MQTT message
        //   - Publish to mqtt/face/{DEVICE_ID}
        //   - Listen for Ack
        //   - Log result
        // Return removal results
    }

    /**
     * Batch sync multiple students (for bulk operations)
     */
    public function batchSyncStudents(Collection $students, BiometricDevice $device): array
    {
        // Build EditPersonsNew message (max 1000)
        // Publish to device
        // Monitor progress with QueryProgress
        // Return results
    }

    /**
     * Build personnel data from student model
     */
    private function buildPersonnelData(Student $student): array
    {
        return [
            'customId' => $student->biometric_id,
            'name' => $student->first_name . ' ' . $student->last_name,
            'gender' => $student->gender === 'male' ? 0 : 1,
            'birthday' => $student->date_of_birth?->format('Y-m-d'),
            'telnum1' => $student->parent_phone,
            'address' => $student->address,
            'personType' => 0, // Whitelist
            'tempCardType' => 0, // Permanent
            'picURI' => $this->getStudentPhotoUrl($student),
            // ... other fields
        ];
    }
}
```

---

### Phase 3: Frontend Pages (React/Inertia)

**Directory**: `resources/js/pages/Admin/Students/`

#### Pages to Create:

1. **Index.tsx** - Students list (EXISTS, needs enhancement)
   - Table with student data
   - Search and filters
   - Pagination
   - Actions: View, Edit, Delete
   - Bulk actions support

2. **Create.tsx** - Create student form
   - Form fields for all student data
   - Photo upload
   - Class selection (cascading: section → level → class)
   - Parent linking
   - Validation

3. **Edit.tsx** - Edit student form
   - Pre-filled form
   - Photo change option
   - Update validation
   - Device sync status

4. **Show.tsx** - Student details
   - Full student information
   - Photo display
   - Attendance history
   - Device sync status
   - Parent information
   - QR code for student

---

### Phase 4: Routes

**File**: `routes/admin.php`

```php
// Already exists:
Route::resource('students', \App\Http\Controllers\Admin\StudentControllerUtf8::class)
    ->names('admin.students');

// May need additional routes:
Route::post('students/{student}/sync-devices', [StudentController::class, 'syncToDevices'])
    ->name('admin.students.sync-devices');
Route::get('students/{student}/sync-status', [StudentController::class, 'syncStatus'])
    ->name('admin.students.sync-status');
```

---

## Device Synchronization Flow

### Create Student Flow:
```
1. User submits create form
2. Controller validates data
3. Generate unique biometric_id
4. Save student to database
5. Call PersonnelManagementService->syncStudentToDevices()
   a. Get all active devices for school
   b. For each device:
      - Build EditPerson message with student data
      - Publish to mqtt/face/{DEVICE_ID}
      - Wait for Ack on mqtt/face/{DEVICE_ID}/Ack
      - Log result (success/failure with error code)
6. Return response with sync results
7. Show success message to user
```

### Update Student Flow:
```
1. User submits edit form
2. Controller validates changes
3. Update student in database
4. Call PersonnelManagementService->syncStudentToDevices()
   - Same process as create (EditPerson handles both)
5. Return response with sync results
```

### Delete Student Flow:
```
1. User confirms deletion
2. Controller soft deletes student
3. Call PersonnelManagementService->removeStudentFromDevices()
   a. Get all active devices for school
   b. For each device:
      - Build DelPerson message
      - Publish to mqtt/face/{DEVICE_ID}
      - Wait for Ack
      - Log result
4. Return success response
```

---

## Key Implementation Details

### 1. Biometric ID Generation
```php
// Generate unique biometric_id for device sync
$biometricId = 'STU_' . $school->id . '_' . Str::random(32);
// Or use: hash('sha256', $school->id . $student->student_id . time())
```

### 2. Photo Handling
- Store photos in `storage/app/public/students/photos/`
- Generate public URL for picURI in MQTT messages
- Validate: max 1MB, JPEG/PNG only
- Resize to optimal dimensions (e.g., 600x800)

### 3. Device Selection Logic
```php
// Get all active devices for student's school
$devices = BiometricDevice::where('school_id', $student->school_id)
    ->where('is_active', true)
    ->where('status', 'online') // Optional: only sync to online devices
    ->get();
```

### 4. Error Handling
- Log all device communication attempts
- Store sync status in database (optional: device_sync_logs table)
- Retry failed syncs (queue for offline devices)
- Show user-friendly error messages

### 5. Validation Rules
```php
$rules = [
    'school_id' => 'required|exists:schools,id',
    'school_class_id' => 'required|exists:school_classes,id',
    'student_id' => 'required|string|unique:students,student_id',
    'first_name' => 'required|string|max:255',
    'last_name' => 'required|string|max:255',
    'gender' => 'required|in:male,female',
    'date_of_birth' => 'required|date|before:today',
    'photo' => 'nullable|image|max:1024', // 1MB max
    'parent_phone' => 'nullable|string|max:20',
    'address' => 'nullable|string|max:500',
];
```

---

## Testing Checklist

### Unit Tests:
- [ ] Biometric ID generation is unique
- [ ] Personnel data builder creates correct format
- [ ] Photo upload and URL generation
- [ ] Validation rules work correctly

### Integration Tests:
- [ ] Create student syncs to all school devices
- [ ] Update student syncs changes to devices
- [ ] Delete student removes from devices
- [ ] Offline devices are queued for later sync
- [ ] Error codes are handled correctly

### Manual Tests:
- [ ] Create student with photo
- [ ] Create student without photo
- [ ] Update student details
- [ ] Update student photo
- [ ] Delete student
- [ ] Verify device receives correct MQTT messages
- [ ] Check device Ack responses
- [ ] Test with offline devices
- [ ] Test with multiple devices per school

---

## Migration Considerations

### Existing Data:
- Students may already exist without `biometric_id`
- Need migration script to generate biometric_ids for existing students
- Option to bulk sync existing students to devices

### Backward Compatibility:
- Ensure existing StudentController methods still work
- Don't break existing attendance logging
- Maintain existing relationships

---

## Performance Optimization

### 1. Async Device Sync
- Use Laravel queues for device synchronization
- Don't block user response waiting for all devices
- Show "Syncing..." status, update via polling/websockets

### 2. Batch Operations
- For bulk student imports, use EditPersonsNew (max 1000)
- Monitor progress with QueryProgress
- Show progress bar to user

### 3. Caching
- Cache school devices list
- Cache student photos URLs
- Invalidate on updates

---

## Security Considerations

1. **Authorization**:
   - School admins can only manage their school's students
   - Super admins can manage all students
   - Verify school_id matches admin's school

2. **Photo Security**:
   - Validate file types strictly
   - Scan for malware
   - Generate unique filenames
   - Serve via authenticated routes

3. **MQTT Security**:
   - Use authenticated MQTT connections
   - Validate device IDs
   - Log all device communications
   - Rate limit device commands

---

## Next Steps

1. ✅ Complete documentation (DONE)
2. ⏳ Implement StudentController CRUD methods
3. ⏳ Implement PersonnelManagementService
4. ⏳ Create frontend pages (Create, Edit, Show)
5. ⏳ Test device synchronization
6. ⏳ Handle edge cases and errors
7. ⏳ Add bulk operations support
8. ⏳ Performance testing and optimization

---

**Status**: Ready for Implementation
**Last Updated**: 2025-09-30
