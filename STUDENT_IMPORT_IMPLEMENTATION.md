# Student Import Feature Implementation

## Overview
Implemented bulk student import functionality with cascading class selection, Excel file parsing, auto-code generation, and ZIP image upload with base64 conversion.

## Features Implemented

### 1. Frontend (React/Inertia)
- **Import Button**: Added to Students Index page (`resources/js/pages/Admin/Students/Index.tsx`)
- **Import Page**: Created comprehensive import form (`resources/js/pages/Admin/Students/Import.tsx`)
  - Cascading dropdowns: School → Section → Option → Level → Class
  - Excel file upload (.xlsx, .xls, .csv)
  - Optional ZIP file upload for student photos
  - Clear instructions and validation

### 2. Backend (Laravel)
- **Controller Methods** (`app/Http/Controllers/Admin/StudentControllerUtf8.php`):
  - `import()`: Display import form with cascading data
  - `processImport()`: Process uploaded files and create students
  - `processExcelFile()`: Parse Excel/CSV files
  - `processImagesZip()`: Extract and sort images alphabetically
  - `saveImageFromZip()`: Save images with dual storage (file + base64)

### 3. Routes
- Added to `routes/admin.php`:
  - `GET /admin/students/import` → Show import form
  - `POST /admin/students/process-import` → Process import

## Import Specifications

### Excel File Format
- **Single column** with student names (first names only)
- Supported formats: `.xlsx`, `.xls`, `.csv`
- Each row = one student

### Image Handling
- **ZIP file** containing student photos
- Images sorted **alphabetically by filename**
- Matching: 1st image → 1st student, 2nd image → 2nd student, etc.
- Supported formats: `.jpg`, `.jpeg`, `.png`, `.gif`
- Dual storage:
  - File path stored in `photo` field
  - Base64 string stored in `photo_base64` field

### Auto-Generated Fields
1. **student_id**: Format `STU-{YEAR}-{NUMBER}`
   - Example: `STU-2024-001`, `STU-2024-002`
   - Sequential numbering per school

2. **student_number**: 3-digit padded number
   - Example: `001`, `002`, `003`

3. **parent_link_code**: 12-character unique code
   - Auto-generated using existing model method
   - Valid for 30 days

4. **biometric_id**: Unique device sync identifier
   - Format: `STU_{SCHOOL_ID}_{TIMESTAMP}_{RANDOM}`

### Fields Set During Import
- `school_id`: From form selection
- `class_id`: From form selection
- `section_id`: From form selection
- `option_id`: From form selection
- `level_id`: From form selection
- `first_name`: From Excel file
- `last_name`: Empty string (as per requirement)
- `is_active`: `true`
- `enrollment_date`: Current timestamp
- `photo`: File path (if image provided)
- `photo_base64`: Base64 string (if image provided)

### Fields Left Empty/Null
- `middle_name`
- `email`
- `phone`
- `date_of_birth`
- `gender`
- `address`
- `emergency_contact_name`
- `emergency_contact_phone`
- `guardian_name`
- `guardian_phone`
- `medical_info`

## Device Synchronization
- All imported students automatically synced to biometric devices
- Uses existing `PersonnelManagementService`
- Errors logged but don't fail the import

## Dependencies Required

### PHP Package
```bash
composer require phpoffice/phpspreadsheet
```

This package is needed for:
- Reading Excel files (.xlsx, .xls)
- CSV parsing is handled natively by PHP

### Already Available
- `ZipArchive`: Built into PHP
- Image processing: Native PHP functions

## File Structure

```
app/Http/Controllers/Admin/
└── StudentControllerUtf8.php (updated)

resources/js/pages/Admin/Students/
├── Index.tsx (updated - added Import button)
└── Import.tsx (new)

routes/
└── admin.php (updated - added import routes)
```

## Usage Flow

1. **Navigate**: Admin → Students → "Import Students" button
2. **Select Hierarchy**:
   - School (super admin only)
   - Section
   - Option
   - Level
   - Class
3. **Upload Files**:
   - Excel file (required)
   - Images ZIP (optional)
4. **Process**: System creates students with auto-generated codes
5. **Sync**: Students automatically synced to devices
6. **Result**: Success message with import count and any errors

## Error Handling

- Validation errors shown per field
- Row-level errors collected and displayed
- Import continues even if some rows fail
- Device sync failures logged but don't stop import
- Temporary files cleaned up automatically

## Security

- Admin authentication required
- School admin restricted to their school
- File type validation
- File size limits:
  - Excel: 10MB max
  - ZIP: 100MB max
- Proper file storage in school-specific directories

## Testing Checklist

- [x] Install PhpSpreadsheet: `composer require phpoffice/phpspreadsheet --ignore-platform-req=ext-gd`
- [x] Build frontend assets: `npm run build`
- [ ] Test Excel file upload (.xlsx, .xls, .csv)
- [ ] Test ZIP file upload with images
- [ ] Test image matching (alphabetical order)
- [ ] Verify student_id format (STU-2024-001)
- [ ] Verify parent_link_code generation
- [ ] Verify dual image storage (file + base64)
- [ ] Test cascading dropdowns
- [ ] Test school admin restrictions
- [ ] Test device synchronization
- [ ] Test error handling

## Build Status

✅ **PhpSpreadsheet Installed**: v5.1.0 (with --ignore-platform-req=ext-gd)
✅ **Frontend Built**: Import page compiled successfully
✅ **Routes Registered**: Import routes added to admin.php
✅ **Ready for Testing**: All files in place and compiled

## Important Notes

### GD Extension
The PhpSpreadsheet package requires PHP GD extension for image processing. It was installed with `--ignore-platform-req=ext-gd` for development. 

**To install GD extension:**
- **Windows**: Enable `extension=gd` in php.ini
- **Linux**: `sudo apt-get install php-gd && sudo systemctl restart apache2`
- **macOS**: `brew install php-gd`

### Build Output
The Import page was successfully compiled:
- `public/build/assets/Import-CvFnjGeH.js` (11.01 kB │ gzip: 3.03 kB)

## Next Steps

1. ✅ Install PhpSpreadsheet package
2. ✅ Build frontend assets
3. Test the import functionality
4. Create sample Excel template for users
5. Add export functionality (optional)
6. Add import history/logs (optional)
