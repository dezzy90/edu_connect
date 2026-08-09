# 🧪 Real Device Testing Guide

This guide will help you test the student synchronization with a real biometric device.

---

## 📋 Prerequisites

Before testing, ensure you have:

1. ✅ A physical biometric device (face/fingerprint reader)
2. ✅ Device connected to the same network as your server
3. ✅ MQTT broker running (192.168.1.137:1883)
4. ✅ Device registered in the system
5. ✅ At least one school created
6. ✅ At least one student created

---

## 🔧 Step 1: Verify Device Registration

### Check if device exists in database:

```bash
php artisan tinker
```

```php
// List all devices
\App\Models\BiometricDevice::all(['id', 'name', 'device_id', 'status', 'school_id']);

// Check specific device
$device = \App\Models\BiometricDevice::where('device_id', 'YOUR_DEVICE_ID')->first();
echo "Device: {$device->name}\n";
echo "Status: {$device->status}\n";
echo "School: {$device->school->name}\n";
```

### If device doesn't exist, register it:

```bash
php artisan tinker
```

```php
$device = \App\Models\BiometricDevice::create([
    'school_id' => 1, // Your school ID
    'device_id' => 'YOUR_DEVICE_SERIAL', // e.g., 'NDZI123456'
    'name' => 'Main Entrance Device',
    'location' => 'Main Building Entrance',
    'device_type' => 'face', // or 'fingerprint'
    'status' => 'active',
    'ip_address' => '192.168.1.100', // Device IP
]);

echo "Device registered: {$device->id}\n";
```

---

## 🧑‍🎓 Step 2: Create a Test Student

### Option A: Via Web Interface (Recommended)

1. Navigate to: `http://localhost:8000/admin/students/create`
2. Fill in the form:
   - First Name: Test
   - Last Name: Student
   - Student Number: TEST-001
   - Date of Birth: Select a date
   - Gender: Select
   - School: Select your school
   - Section → Option → Level → Class (cascading dropdowns)
3. Click "Create Student"
4. **Check the success message** - it should say "Synced to X/Y devices"

### Option B: Via Tinker (For Testing)

```bash
php artisan tinker
```

```php
$student = \App\Models\Student::create([
    'school_id' => 1,
    'class_id' => 1,
    'section_id' => 2,
    'level_id' => 13,
    'option_id' => 3,
    'first_name' => 'Test',
    'last_name' => 'Student',
    'student_number' => 'TEST-001',
    'date_of_birth' => '2010-01-01',
    'gender' => 'male',
    'biometric_id' => 'STU_1_' . time() . '_TEST',
    'enrollment_date' => now(),
]);

// Generate parent link code
$student->generateParentLinkCode(30);

echo "Student created: {$student->full_name}\n";
echo "Biometric ID: {$student->biometric_id}\n";
echo "Parent Link Code: {$student->formatted_link_code}\n";
```

---

## 📡 Step 3: Verify MQTT Message Sent

### Check Laravel Logs:

```bash
tail -f storage/logs/laravel.log
```

Look for entries like:
```
[2025-09-30 17:30:00] local.INFO: Personnel sync sent {"student_id":1,"device_id":"NDZI123456","topic":"mqtt/face/NDZI123456","message_id":"ROD:..."}
```

### Monitor MQTT Traffic (Optional):

Install MQTT Explorer or use mosquitto_sub:

```bash
# Subscribe to all device topics
mosquitto_sub -h 192.168.1.137 -p 1883 -u rodadmin -P YOUR_MQTT_PASSWORD -t "mqtt/face/#" -v
```

You should see messages like:
```
mqtt/face/NDZI123456 {"messageId":"ROD:...","operator":"EditPerson","info":{...}}
```

---

## 🔍 Step 4: Check Device Received the Message

### Method 1: Check Device Display
- Most devices show a notification when personnel data is received
- Look for "New person added" or similar message

### Method 2: Check Device Web Interface
- Access device web interface (usually http://DEVICE_IP)
- Navigate to Personnel Management
- Look for the student with biometric_id: `STU_1_XXXXX_XXXX`

### Method 3: Use Test Script

Create a file `test_device_sync.php`:

```php
<?php
require_once 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Student;
use App\Services\PersonnelManagementService;

echo "🧪 Testing Device Synchronization\n";
echo str_repeat("=", 50) . "\n\n";

// Get the student
$student = Student::where('student_number', 'TEST-001')->first();

if (!$student) {
    echo "❌ Student not found!\n";
    exit(1);
}

echo "✅ Student found: {$student->full_name}\n";
echo "   Biometric ID: {$student->biometric_id}\n";
echo "   School: {$student->school->name}\n\n";

// Get devices
$devices = \App\Models\BiometricDevice::where('school_id', $student->school_id)
    ->where('status', 'active')
    ->get();

echo "📱 Found {$devices->count()} active device(s)\n\n";

// Sync to each device
$service = new PersonnelManagementService();

foreach ($devices as $device) {
    echo "Syncing to: {$device->name} ({$device->device_id})...\n";
    
    $result = $service->syncStudentToDevice($student, $device);
    
    if ($result['success']) {
        echo "✅ SUCCESS - Message ID: {$result['message_id']}\n";
    } else {
        echo "❌ FAILED - Error: {$result['error']}\n";
    }
    echo "\n";
}

echo str_repeat("=", 50) . "\n";
echo "🎯 Next Steps:\n";
echo "1. Check device display for confirmation\n";
echo "2. Try scanning student's finger/face on device\n";
echo "3. Device should recognize student by biometric_id\n";
```

Run it:
```bash
php test_device_sync.php
```

---

## 👆 Step 5: Enroll Biometric Data on Device

### For Face Recognition Devices:
1. On the device, navigate to: **Personnel → Add Face**
2. Enter the **biometric_id** (e.g., `STU_1_1759252112_BKPjG6cv`)
3. Position student's face in front of camera
4. Follow device prompts to capture face from multiple angles
5. Confirm enrollment

### For Fingerprint Devices:
1. On the device, navigate to: **Personnel → Add Fingerprint**
2. Enter the **biometric_id**
3. Place student's finger on scanner
4. Scan multiple times as prompted (usually 3-5 times)
5. Confirm enrollment

---

## ✅ Step 6: Test Recognition

### Test Check-In:
1. Have the student scan their finger/face on the device
2. Device should:
   - Recognize the student
   - Display their name
   - Send check-in event to server

### Verify in System:
```bash
php artisan tinker
```

```php
// Check latest student logs
$student = \App\Models\Student::where('student_number', 'TEST-001')->first();
$logs = $student->studentLogs()->latest()->take(5)->get();

foreach ($logs as $log) {
    echo "{$log->event_type} at {$log->created_at} via {$log->biometricDevice->name}\n";
}
```

Or check in web interface:
- Navigate to: `http://localhost:8000/admin/students/{student_id}`
- Scroll to "Recent Attendance Logs"
- You should see the check-in event

---

## 🐛 Troubleshooting

### Issue 1: Device Not Receiving Messages

**Check MQTT Connection:**
```bash
php artisan tinker
```

```php
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

$client = new MqttClient('192.168.1.137', 1883, 'test-client-' . time());
$settings = (new ConnectionSettings())
    ->setUsername('rodadmin')
    ->setPassword('YOUR_MQTT_PASSWORD');

try {
    $client->connect($settings);
    echo "✅ MQTT connection successful!\n";
    $client->disconnect();
} catch (Exception $e) {
    echo "❌ MQTT connection failed: " . $e->getMessage() . "\n";
}
```

### Issue 2: Student Not Found on Device

**Manually sync:**
```bash
php artisan tinker
```

```php
$student = \App\Models\Student::find(1); // Your student ID
$service = new \App\Services\PersonnelManagementService();
$results = $service->syncStudentToSchool($student);

print_r($results);
```

Or use the web interface:
- Go to student show page
- Click "Sync to Devices" button

### Issue 3: Device Shows "Person Not Found"

**Verify biometric_id on device:**
1. Check device personnel list
2. Ensure biometric_id matches exactly
3. Re-sync if needed

### Issue 4: Check-In Not Recorded

**Check MQTT subscriber is running:**
```bash
php artisan mqtt:subscribe
```

This should be running continuously to receive check-in events from devices.

---

## 📊 Monitoring Device Sync Status

### Via Web Interface:
```
GET http://localhost:8000/admin/students/{student_id}/sync-status
```

### Via Tinker:
```php
$student = \App\Models\Student::find(1);
$devices = \App\Models\BiometricDevice::where('school_id', $student->school_id)->get();

foreach ($devices as $device) {
    echo "{$device->name}: {$device->status} (Last seen: {$device->last_seen_at})\n";
}
```

---

## 🎯 Complete Testing Checklist

- [ ] Device registered in database
- [ ] Device status is "active"
- [ ] Student created successfully
- [ ] Success message shows "Synced to X/Y devices"
- [ ] MQTT message logged in laravel.log
- [ ] Device received EditPerson message
- [ ] Student appears in device personnel list
- [ ] Biometric data enrolled on device
- [ ] Device recognizes student
- [ ] Check-in event sent to server
- [ ] Check-in appears in student logs
- [ ] Check-in visible in web interface

---

## 📝 Example Complete Test Flow

```bash
# 1. Start MQTT subscriber (in separate terminal)
php artisan mqtt:subscribe

# 2. Create test student via web interface
# Navigate to: http://localhost:8000/admin/students/create
# Fill form and submit

# 3. Check logs
tail -f storage/logs/laravel.log | grep "Personnel sync"

# 4. Verify on device
# - Check device personnel list
# - Enroll biometric data
# - Test recognition

# 5. Verify check-in recorded
php artisan tinker
$student = \App\Models\Student::where('student_number', 'TEST-001')->first();
$student->studentLogs()->latest()->first();
```

---

## 🚀 Production Deployment Notes

When deploying to production:

1. **Ensure MQTT subscriber runs continuously:**
   ```bash
   # Use supervisor or systemd
   php artisan mqtt:subscribe
   ```

2. **Monitor device connectivity:**
   - Set up alerts for offline devices
   - Regular health checks

3. **Backup biometric data:**
   - Export device personnel lists regularly
   - Keep sync logs

4. **Test failover:**
   - What happens if MQTT broker goes down?
   - What happens if device loses connection?

---

## 📞 Support

If you encounter issues:
1. Check `storage/logs/laravel.log`
2. Verify MQTT broker is running
3. Confirm device network connectivity
4. Test MQTT connection manually
5. Review device documentation

---

**Good luck with your testing! 🎉**
