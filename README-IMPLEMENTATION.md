# Rod-Connect: Multi-Tenant Student Follow-Up SaaS Application

## Overview

This Laravel application provides a comprehensive multi-tenant SaaS solution for student follow-up across multiple schools, featuring biometric check-in/check-out via MQTT and real-time notifications through WebSockets.

## Architecture Summary

### 1. Database & Multi-Tenancy Structure

#### Core Models and Relationships

**School (Tenant Model)**
- Main tenant entity with subscription management
- Related to all school-specific entities
- Fields: name, code, address, timezone, subscription_expires_at

**Academic Structure Models:**
- **Section**: Academic sections within a school
- **Option**: Academic options/streams
- **Level**: Grade/year levels (with ordering)
- **SchoolClass**: Combination of section, option, level with capacity management

**User Management:**
- **User**: School staff with role-based permissions (super_admin, school_admin, principal, teacher, staff)
- Scoped to schools with proper authorization levels

**Student Management:**
- **Student**: Core student entity with biometric_id for device recognition
- **SchoolParent**: Parent/guardian management
- **parent_student**: Pivot table with unique link_code system (max 2 parents per student)

**Biometric & Logging:**
- **BiometricDevice**: School-scoped devices with heartbeat monitoring
- **StudentLog**: Check-in/check-out logs with daily constraints

#### Key Relationships
```
School (1) -> (Many) Section, Option, Level, SchoolClass, Student, BiometricDevice, User
SchoolClass (Many) -> (1) Section, Option, Level
Student (Many) -> (1) SchoolClass
Student (Many) <-> (Many) SchoolParent (via parent_student pivot with link_code)
StudentLog (Many) -> (1) Student, BiometricDevice
```

### 2. Multi-Tenancy Implementation

#### Global Scoping
- `BelongsToSchool` trait with `SchoolScope` for automatic school filtering
- All school-related models automatically scope to current school context

#### Context Resolution
- `SetSchoolContext` middleware resolves school from:
  1. Authenticated user's school
  2. Subdomain (e.g., school-code.yourdomain.com)
  3. Request headers (X-School-Code)
  4. Session fallback

#### Helper Functions
```php
current_school()    // Get current school instance
school_id()         // Get current school ID
with_school($school, $callback) // Execute with specific school context
```

### 3. Student-Parent Linking System

#### Unique Link Code Generation
- 6-character alphanumeric codes for parent-student association
- Enforces maximum 2 parents per student
- Parents can link to maximum 5 students
- Automatic cleanup of expired pending links (30 days)

#### Linking Methods
```php
// Generate pending link code
$code = $student->createPendingParentLink();

// Link parent with code
$student->linkParent($parent, 'father', $isPrimary = true);

// Link using existing code
Student::linkParentByCode($code, $parent, 'mother');
```

### 4. Check-In/Check-Out System

#### Daily Constraints
- One check-in and one check-out per student per day
- Automatic validation in StudentLog model
- Status tracking: not_arrived, checked_in, checked_out

#### Student Status Methods
```php
$student->isCurrentlyCheckedIn()
$student->getCurrentStatus()
$student->getTodaysCheckIn()
$student->getTodaysCheckOut()
```

#### Log Creation
```php
StudentLog::createCheckIn($studentId, $deviceId, $additionalData);
StudentLog::createCheckOut($studentId, $deviceId, $additionalData);
```

### 5. MQTT Integration

#### Package: `php-mqtt/client`
Well-maintained PHP MQTT client with reliable connection handling.

#### Background Command
```bash
php artisan mqtt:subscribe --host=localhost --port=1883 --topic="mqtt/face/+/Rec"
```

#### Message Processing Flow
1. MQTT subscriber receives message from topic pattern `mqtt/face/{device_id}/Rec`
2. `BiometricMessageProcessor` service processes the message:
   - Validates and finds biometric device
   - Parses message (JSON/XML/custom formats)
   - Identifies student by biometric_id
   - Determines check-in/check-out based on current status
   - Creates StudentLog entry
   - Fires real-time events

#### Supported Message Formats
- **JSON**: `{"biometric_id": "12345", "confidence": 98.5, ...}`
- **Simple**: `ID:12345,CONF:98.5`
- **XML**: Custom XML parsing support

### 6. Real-Time Notifications

#### Broadcasting Events
- `StudentCheckedIn`: Fired on successful check-in
- `StudentCheckedOut`: Fired on successful check-out

#### Broadcasting Channels
```php
// Multi-level broadcasting for granular subscriptions
'school.{school_id}'     // School-wide notifications
'class.{class_id}'       // Class-specific notifications
'student.{student_id}'   // Individual student notifications
```

#### Event Data Structure
```javascript
{
  student: {
    id, student_number, full_name, class_id, photo
  },
  log: {
    id, event_type, created_at, formatted_time, device_id, confidence_score
  },
  timestamp: "2024-01-01T10:30:00Z"
}
```

### 7. Laravel Echo & WebSocket Setup

#### Broadcasting Configuration
- Uses Pusher for reliable WebSocket connections
- Private channels for security (school-scoped access)
- Queue-based event processing for performance

#### Frontend Integration Example
```javascript
// Laravel Echo setup
window.Echo.private(`school.${schoolId}`)
    .listen('.student.checked-in', (event) => {
        // Handle check-in notification
        showNotification(`${event.student.full_name} checked in`);
    })
    .listen('.student.checked-out', (event) => {
        // Handle check-out notification
        showNotification(`${event.student.full_name} checked out`);
    });
```

## Database Schema Summary

### Key Tables Created
1. `schools` - Tenant management
2. `sections`, `options`, `levels` - Academic structure
3. `school_classes` - Combined academic entities
4. `students` - Student records with biometric_id
5. `parents` - Parent/guardian information
6. `parent_student` - Pivot with link_code system
7. `biometric_devices` - Device management
8. `student_logs` - Check-in/check-out records
9. `users` - Staff with school_id and roles

### Important Indexes
- Multi-column indexes for tenant scoping
- Performance indexes for daily log queries
- Unique constraints for business rules

## Deployment & Production Considerations

### MQTT Supervisor Configuration
```ini
[program:mqtt-subscriber]
process_name=%(program_name)s
command=php /path/to/artisan mqtt:subscribe
directory=/path/to/project
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/logs/mqtt-subscriber.log
```

### Broadcasting Queue Setup
```bash
# Process broadcasting events
php artisan queue:work --queue=broadcasting

# Monitor queue jobs
php artisan queue:monitor
```

### Environment Variables
```env
# MQTT Configuration
MQTT_HOST=localhost
MQTT_PORT=1883
MQTT_USERNAME=
MQTT_PASSWORD=

# Broadcasting
BROADCAST_DRIVER=pusher
PUSHER_APP_ID=
PUSHER_APP_KEY=
PUSHER_APP_SECRET=
PUSHER_APP_CLUSTER=

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rod_connect
DB_USERNAME=
DB_PASSWORD=
```

## Usage Examples

### Running the System

1. **Migrate Database**
```bash
php artisan migrate
```

2. **Start MQTT Subscriber**
```bash
php artisan mqtt:subscribe --host=your-mqtt-broker.com --port=1883
```

3. **Process Broadcasting Queue**
```bash
php artisan queue:work --queue=broadcasting
```

4. **Create School & Users**
```php
$school = School::create([
    'name' => 'Demo School',
    'code' => 'demo',
    'address' => '123 Education St',
    'timezone' => 'America/New_York'
]);

$admin = User::create([
    'school_id' => $school->id,
    'name' => 'School Admin',
    'email' => 'admin@demo.school',
    'password' => Hash::make('password'),
    'role' => User::ROLE_SCHOOL_ADMIN
]);
```

### API Integration Example

```php
// Set school context in API middleware
Route::middleware(['auth:sanctum', 'school.context'])->group(function () {
    Route::get('/students', [StudentController::class, 'index']);
    Route::post('/students/{student}/link-parent', [StudentController::class, 'linkParent']);
    Route::get('/logs/today', [StudentLogController::class, 'today']);
});
```

This comprehensive implementation provides a robust foundation for a multi-tenant student management system with real-time biometric tracking capabilities.