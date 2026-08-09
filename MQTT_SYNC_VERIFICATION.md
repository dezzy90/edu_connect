# MQTT Device Sync Verification Guide

## Overview
This guide helps verify that student-to-device synchronization works correctly after centralizing MQTT configuration.

---

## 🔍 What Was Changed

### PersonnelManagementService.php
The service now uses centralized configuration:

```php
// BEFORE (with fallbacks)
$host = config('mqtt.host', '192.168.1.137');
$port = config('mqtt.port', 1883);
$username = config('mqtt.username', 'rodadmin');
$password = config('mqtt.password', 'YOUR_MQTT_PASSWORD');

// AFTER (no fallbacks - uses .env only)
$host = config('mqtt.host');
$port = config('mqtt.port');
$username = config('mqtt.username');
$password = config('mqtt.password');
```

---

## ✅ Verification Steps

### Step 1: Verify .env Configuration

Ensure your `.env` file has MQTT settings:

```bash
MQTT_HOST=test.mosquitto.org
MQTT_PORT=1883
MQTT_USERNAME=
MQTT_PASSWORD=
```

### Step 2: Test MQTT Connection

```bash
php test_mqtt_auth.php
```

**Expected Output**:
```
Testing MQTT Authentication
==================================================

MQTT Configuration (from .env):
  Host: test.mosquitto.org
  Port: 1883
  Username: (none)
  Password: (none)
  Client ID Prefix: rod-connect

Attempting connection...
✅ SUCCESS: Connected with authentication!
```

### Step 3: Verify Laravel Can Read Config

```bash
php artisan tinker
```

Then run:
```php
config('mqtt.host')
// Should return: "test.mosquitto.org"

config('mqtt.port')
// Should return: 1883
```

### Step 4: Test Student Creation with Device Sync

#### Option A: Via Web Interface

1. Start Laravel server:
   ```bash
   php artisan serve
   ```

2. Navigate to: `http://localhost:8000/admin/students/create`

3. Fill in student details and submit

4. Check the success message - it should say:
   ```
   Student created successfully! Synced to X/Y devices.
   ```

5. Check Laravel logs for MQTT connection:
   ```bash
   tail -f storage/logs/laravel.log
   ```

   Look for:
   ```
   Personnel sync sent
   student_id: XXX
   device_id: XXX
   topic: mqtt/face/DEVICE_ID
   ```

#### Option B: Via Tinker

```bash
php artisan tinker
```

```php
// Create a test student
$student = new App\Models\Student();
$student->school_id = 1;
$student->first_name = 'Test';
$student->last_name = 'Student';
$student->student_number = 'TEST' . time();
$student->biometric_id = 'STU_TEST_' . time();
$student->date_of_birth = '2010-01-01';
$student->gender = 'male';
$student->class_id = 1;
$student->save();

// Test sync
$service = new App\Services\PersonnelManagementService();
$results = $service->syncStudentToSchool($student);

// Check results
print_r($results);
```

**Expected Output**:
```php
Array
(
    [0] => Array
        (
            [success] => 1
            [message_id] => ROD:HOSTNAME-...
            [topic] => mqtt/face/DEVICE_ID
            [student] => Test Student
            [device] => DEVICE_ID
        )
)
```

### Step 5: Monitor MQTT Messages

In a separate terminal, run the MQTT subscriber:

```bash
php laravel_mqtt_subscriber.php
```

**Expected Output**:
```
🚀 Laravel-Integrated MQTT Device Subscriber
=============================================

MQTT Configuration (from .env):
  Host: test.mosquitto.org
  Port: 1883
  Username: (none)
  Password: (none)
  Client ID Prefix: rod-connect

📡 Connecting to MQTT broker...
Client ID: rod-connect-laravel-subscriber-1234567890

✅ Connected to MQTT broker successfully!

📥 Subscribing to topics:
  ✅ mqtt/face/+/Rec
  ✅ mqtt/face/2581924_ipobexa/Rec
  ✅ mqtt/face/heartbeat
  ✅ mqtt/face/basic

🔄 Laravel-integrated listening active...
```

Now create a student via web interface and watch for messages.

---

## 🧪 Test Scenarios

### Scenario 1: Create Student
1. Create a new student via admin panel
2. Verify sync message appears in logs
3. Check that biometric_id was generated
4. Confirm success message shows device count

### Scenario 2: Update Student
1. Edit an existing student
2. Change name or other details
3. Verify sync message is sent to update devices
4. Check success message

### Scenario 3: Delete Student
1. Delete a student
2. Verify removal message is sent to devices
3. Check success message

### Scenario 4: Manual Sync
1. Go to student detail page
2. Click "Sync to Devices" button
3. Verify sync occurs
4. Check success message

---

## 🔧 Troubleshooting

### Issue: "Connection refused"

**Cause**: MQTT broker not accessible

**Solutions**:
1. Check if broker is running:
   ```bash
   # For local broker
   sc query mosquitto
   
   # For public broker
   ping test.mosquitto.org
   ```

2. Verify .env settings:
   ```bash
   php artisan config:clear
   php artisan config:cache
   ```

3. Test connection:
   ```bash
   php test_mqtt_auth.php
   ```

### Issue: "No devices found"

**Cause**: No devices registered in school

**Solution**:
1. Check devices table:
   ```bash
   php artisan tinker
   ```
   ```php
   App\Models\BiometricDevice::where('school_id', 1)->get();
   ```

2. Register a device if needed

### Issue: "Sync failed" message

**Cause**: MQTT connection error or device offline

**Solutions**:
1. Check Laravel logs:
   ```bash
   tail -f storage/logs/laravel.log
   ```

2. Verify MQTT config:
   ```bash
   php artisan tinker
   ```
   ```php
   config('mqtt.host');
   config('mqtt.port');
   ```

3. Test MQTT connection:
   ```bash
   php test_mqtt_auth.php
   ```

### Issue: Config not updating

**Cause**: Config cache

**Solution**:
```bash
php artisan config:clear
php artisan cache:clear
```

---

## 📊 Expected Behavior

### When Student is Created:

1. **Controller** (`StudentControllerUtf8::store()`):
   - Validates input
   - Generates `biometric_id`
   - Creates student record
   - Calls `PersonnelManagementService->syncStudentToSchool()`

2. **Service** (`PersonnelManagementService`):
   - Reads MQTT config from `.env` via `config('mqtt.host')` etc.
   - Connects to MQTT broker
   - Gets all active devices in student's school
   - For each device:
     - Builds `EditPerson` message
     - Publishes to `mqtt/face/{device_id}`
     - Waits for acknowledgment
   - Returns sync results

3. **Result**:
   - Success message: "Student created successfully! Synced to X/Y devices."
   - Student record in database with `biometric_id`
   - MQTT messages sent to all school devices
   - Devices receive and store student data

---

## ✅ Success Criteria

- [ ] `.env` file has MQTT configuration
- [ ] `php test_mqtt_auth.php` connects successfully
- [ ] `config('mqtt.host')` returns correct value
- [ ] Creating student shows sync success message
- [ ] Laravel logs show "Personnel sync sent" messages
- [ ] MQTT subscriber receives messages (if running)
- [ ] No hardcoded IP addresses in code
- [ ] All test scripts use centralized config

---

## 📝 Quick Verification Checklist

```bash
# 1. Check .env has MQTT settings
cat .env | grep MQTT

# 2. Clear config cache
php artisan config:clear

# 3. Test MQTT connection
php test_mqtt_auth.php

# 4. Verify config in Laravel
php artisan tinker
>>> config('mqtt.host')
>>> exit

# 5. Check for hardcoded IPs (should return 0)
grep -r "192.168.1.137" app/ --include="*.php" | wc -l

# 6. Test student creation
# (Use web interface or tinker)

# 7. Check logs
tail -f storage/logs/laravel.log
```

---

## 🎯 Conclusion

If all verification steps pass, the MQTT configuration centralization is working correctly and student-to-device synchronization uses the `.env` configuration.

**Key Points**:
- ✅ All MQTT connections use `.env` configuration
- ✅ No hardcoded values remain
- ✅ Easy to switch between brokers
- ✅ Student sync works with centralized config
- ✅ All test scripts updated

---

*Last Updated: January 2025*
*Status: Ready for Testing ✅*
