# 🇨🇲 Real Biometric Device Integration Guide for Rod-Connect

## MQTT Broker Configuration

**Current Broker Details:**
- Host: `172.17.31.181`
- Port: `1883`
- Username: `rodadmin` 
- Password: `YOUR_MQTT_PASSWORD`

## Authentication Issue Resolution

⚠️ **Current Issue**: The MQTT subscriber is getting "unauthorized" responses from the broker.

**Troubleshooting Steps:**

1. **Verify Mosquitto User Setup:**
   ```bash
   # On the Mosquitto server (172.17.31.181), check users:
   mosquitto_passwd -U /etc/mosquitto/passwd
   
   # Add/update user if needed:
   mosquitto_passwd /etc/mosquitto/passwd rodadmin
   ```

2. **Check Mosquitto Configuration** (`/etc/mosquitto/mosquitto.conf`):
   ```conf
   # Allow anonymous access (for testing)
   allow_anonymous true
   
   # OR use password file
   password_file /etc/mosquitto/passwd
   
   # Listener
   listener 1883
   
   # Log settings
   log_dest file /var/log/mosquitto/mosquitto.log
   log_type all
   ```

3. **Restart Mosquitto:**
   ```bash
   sudo systemctl restart mosquitto
   sudo systemctl status mosquitto
   ```

## Biometric Device Configuration

Configure your biometric device with these settings:

### Connection Settings
```
MQTT Host: 172.17.31.181
MQTT Port: 1883
Username: rodadmin
Password: YOUR_MQTT_PASSWORD
Client ID: face_device_[UNIQUE_ID]
```

### Topics Configuration

**1. Recognition Messages (Check-in/Check-out):**
- Topic Pattern: `mqtt/face/{device_id}/Rec`
- Example: `mqtt/face/DEVICE_1_01/Rec`

**2. Photo Capture:**
- Topic Pattern: `mqtt/face/{device_id}/Snap`
- Example: `mqtt/face/DEVICE_1_01/Snap`

**3. Heartbeat Messages:**
- Topic: `mqtt/face/heartbeat`

**4. Basic Status:**
- Topic: `mqtt/face/basic`

### Message Formats

**Recognition Message (JSON):**
```json
{
    "biometric_id": "91c4a611-a390-3049-b5d6-d0af4c44df84",
    "confidence": 95.8,
    "timestamp": "2025-09-26T12:00:00Z",
    "event": "recognition",
    "device_location": "Main Entrance",
    "user_id": "12345",
    "photo_path": "/photos/capture_123.jpg"
}
```

**Heartbeat Message (JSON):**
```json
{
    "device_id": "DEVICE_1_01",
    "status": "online",
    "timestamp": "2025-09-26T12:00:00Z",
    "battery": 85,
    "memory_usage": 45,
    "network_signal": "strong"
}
```

## Device Registration in System

Your biometric devices are already configured in the database:

1. **Lycée Général Leclerc Douala**: `DEVICE_1_01`, `DEVICE_1_02`
2. **Collège Libermann Douala**: `DEVICE_2_01`, `DEVICE_2_02`  
3. **Lycée Bilingue de Bafoussam**: `DEVICE_3_01`, `DEVICE_3_02`
4. **Collège Notre-Dame de la Paix Yaoundé**: `DEVICE_4_01`, `DEVICE_4_02`

## Student Biometric IDs (Sample)

Here are some student biometric IDs you can use for testing:

**School 1 (Lycée Général Leclerc Douala):**
- Isaac Biya: `91c4a611-a390-3049-b5d6-d0af4c44df84`
- Marie Kotto: `[get from database]`

**To get all student IDs:**
```bash
php artisan tinker --execute="App\Models\Student::with('school')->whereNotNull('biometric_id')->get(['first_name', 'last_name', 'biometric_id', 'school_id'])->each(function(\$s) { echo \$s->first_name . ' ' . \$s->last_name . ' - ' . \$s->biometric_id . ' - School: ' . \$s->school_id . PHP_EOL; });"
```

## Testing Steps

1. **First, resolve MQTT authentication**
2. **Configure your device with the above settings**
3. **Test device registration** (heartbeat messages)
4. **Enroll student fingerprints/faces** on the device
5. **Test recognition** and verify check-in/check-out logging

## Laravel Commands for Monitoring

```bash
# Start MQTT subscriber (after auth is fixed)
php artisan mqtt:subscribe -vvv

# Test MQTT connection
php test_mqtt_connection.php

# Check recent logs
php check_logs.php

# Manual MQTT test
php artisan mqtt:test --device=DEVICE_1_01
```

## Next Steps

1. Fix the MQTT broker authentication issue
2. Configure your biometric device with the provided settings
3. Test device connectivity and registration
4. Enroll student biometrics on the device
5. Test real recognition and attendance logging