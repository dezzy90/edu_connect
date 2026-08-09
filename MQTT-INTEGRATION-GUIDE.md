# MQTT Integration Guide for Rod-Connect

This guide provides comprehensive information about the MQTT integration with biometric devices in the Rod-Connect system.

## MQTT Topic Structure

Based on the biometric device documentation, our system supports the following topic patterns:

### Device Subscription Topics (Downlink)
Devices subscribe to receive commands from the server:
```
mqtt/face/{device_id}
```
Example: `mqtt/face/1306612` for device with ID 1306612

### Device Publishing Topics (Uplink)

#### Fixed Topics (Old Version - Cannot be Modified)
1. **Application Layer Heartbeat**
   ```
   mqtt/face/heartbeat
   ```

2. **Up/Down Notifications**
   ```
   mqtt/face/basic
   ```

#### Variable Topics (New Version - Can be Modified)

1. **Stranger Capture Records**
   ```
   mqtt/face/{device_id}/Snap
   ```
   Example: `mqtt/face/1306612/Snap`

2. **Identity Recognition Records** (Main check-in/check-out)
   ```
   mqtt/face/{device_id}/Rec
   ```
   Example: `mqtt/face/1306612/Rec`

3. **QR Code Transmission**
   ```
   mqtt/face/{device_id}/QRCode
   ```

4. **ID Card Information**
   ```
   mqtt/face/{device_id}/IDCard
   ```

5. **IC/RF Card Information**
   ```
   mqtt/face/{device_id}/Card
   ```

6. **Door Magnet/Alarm Messages**
   ```
   mqtt/face/{device_id}/Alarm
   ```

7. **Downlink Execution Results**
   ```
   mqtt/face/{device_id}/Ack
   ```

## Configuration

### Environment Variables

Add the following to your `.env` file:

```env
# MQTT Broker Configuration
MQTT_HOST=your-mqtt-broker.com
MQTT_PORT=1883
MQTT_USERNAME=your_username
MQTT_PASSWORD=your_password
MQTT_CLIENT_ID_PREFIX=rod-connect
MQTT_USE_TLS=false

# Message Processing Options
MQTT_LOG_ALL_MESSAGES=false
MQTT_LOG_UNKNOWN_MESSAGES=true

# Broadcasting for Real-time Notifications
BROADCAST_CONNECTION=pusher
PUSHER_APP_ID=your_app_id
PUSHER_APP_KEY=your_app_key
PUSHER_APP_SECRET=your_app_secret
PUSHER_APP_CLUSTER=your_cluster
```

### Configuration File

The system uses `config/mqtt.php` for detailed MQTT configuration:

```php
'topics' => [
    'recognition' => 'mqtt/face/+/Rec',        // Main check-in/out
    'capture' => 'mqtt/face/+/Snap',           // Stranger detection
    'qr_code' => 'mqtt/face/+/QRCode',         // QR code scans
    'id_card' => 'mqtt/face/+/IDCard',         // ID card scans
    'ic_rf_card' => 'mqtt/face/+/Card',        // IC/RF card scans
    'alarm' => 'mqtt/face/+/Alarm',            // Alarm messages
    'acknowledgment' => 'mqtt/face/+/Ack',     // Command acknowledgments
    'heartbeat' => 'mqtt/face/heartbeat',      // Device heartbeats
    'basic' => 'mqtt/face/basic',              // Basic notifications
],
```

## Running the MQTT Subscriber

### Basic Usage

```bash
# Subscribe to essential topics (recognition, capture, heartbeat, basic)
php artisan mqtt:subscribe

# Subscribe to all configured topics
php artisan mqtt:subscribe --all

# Subscribe with custom broker settings
php artisan mqtt:subscribe --host=192.168.1.100 --port=1883 --username=admin --password=secret

# Subscribe to specific topics
php artisan mqtt:subscribe --topics="mqtt/face/+/Rec" --topics="mqtt/face/+/Snap"
```

### Production Deployment with Supervisor

Create `/etc/supervisor/conf.d/mqtt-subscriber.conf`:

```ini
[program:rod-connect-mqtt]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/rod-connect/artisan mqtt:subscribe
directory=/var/www/rod-connect
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/rod-connect/storage/logs/mqtt-subscriber.log
stopwaitsecs=3600
```

Then start the service:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start rod-connect-mqtt:*
```

## Message Processing Flow

### 1. Recognition Messages (`mqtt/face/{device_id}/Rec`)

These are the primary messages for student check-in/check-out:

**Sample Message Format:**
```json
{
  "biometric_id": "12345",
  "confidence": 98.5,
  "timestamp": "2024-01-01T10:30:00Z",
  "event": "recognition",
  "device_info": {
    "id": "1306612",
    "location": "Main Entrance"
  }
}
```

**Processing Logic:**
1. Find biometric device by device_id
2. Update device heartbeat
3. Parse message to extract biometric_id
4. Find student by biometric_id within the school
5. Determine if this is a check-in or check-out based on current status
6. Create StudentLog entry with daily constraints
7. Fire real-time event (StudentCheckedIn/StudentCheckedOut)

### 2. Capture Messages (`mqtt/face/{device_id}/Snap`)

For stranger/unknown person detection:

**Processing Logic:**
1. Log the capture event
2. Store capture data for security review
3. Optionally send alerts to school administrators

### 3. Heartbeat Messages (`mqtt/face/heartbeat`)

Device health monitoring:

**Processing Logic:**
1. Update device last_heartbeat timestamp
2. Log device status for monitoring

### 4. Other Message Types

The system logs and processes QR codes, ID cards, IC/RF cards, and alarm messages for comprehensive device integration.

## Database Integration

### Student Check-in/Check-out

The system enforces business rules:
- One check-in per student per day
- One check-out per student per day
- Must check-in before checking-out
- Automatic status determination

### Device Management

- Tracks device heartbeats for health monitoring
- Updates `last_heartbeat` field on any message received
- Supports device status queries (online/offline)

## Real-time Notifications

### Broadcasting Channels

Events are broadcast on multiple channels for granular subscriptions:

```javascript
// School-wide notifications
Echo.private('school.123')
    .listen('.student.checked-in', (event) => {
        console.log(`${event.student.full_name} checked in`);
    });

// Class-specific notifications  
Echo.private('class.456')
    .listen('.student.checked-out', (event) => {
        console.log(`${event.student.full_name} checked out`);
    });

// Individual student notifications
Echo.private('student.789')
    .listen('.student.checked-in', (event) => {
        // Handle individual student events
    });
```

### Event Data Structure

```javascript
{
  student: {
    id: 123,
    student_number: "STU001",
    full_name: "John Doe",
    class_id: 456,
    photo: "path/to/photo.jpg"
  },
  log: {
    id: 789,
    event_type: "check_in",
    created_at: "2024-01-01T10:30:00.000000Z",
    formatted_time: "10:30:00",
    device_id: 1,
    confidence_score: 98.50
  },
  timestamp: "2024-01-01T10:30:00.000000Z"
}
```

## Troubleshooting

### Common Issues

1. **Device Not Found**
   - Ensure the device is registered in the `biometric_devices` table
   - Check that the device_id matches between MQTT topic and database
   - Verify the device is marked as active (`is_active = true`)

2. **Student Not Found**
   - Confirm the student has a `biometric_id` set
   - Verify the student is active and in the correct school
   - Check biometric enrollment on the device

3. **Daily Constraint Violations**
   - The system prevents duplicate check-ins/check-outs per day
   - Check existing `student_logs` entries for the date
   - Use `StudentLog::canCreateLog()` to verify constraints

### Debugging Commands

```bash
# Test MQTT connection
php artisan mqtt:subscribe --topics="mqtt/face/heartbeat"

# Enable verbose logging
MQTT_LOG_ALL_MESSAGES=true php artisan mqtt:subscribe

# Check database for recent logs
php artisan tinker
>>> StudentLog::today()->latest()->limit(10)->get()

# Verify device status
>>> BiometricDevice::online()->get()
```

## Testing

### Manual Testing

1. **Simulate Device Message:**
   ```bash
   # Using mosquitto_pub (if available)
   mosquitto_pub -h localhost -t "mqtt/face/1306612/Rec" -m '{"biometric_id":"12345","confidence":98.5}'
   ```

2. **Check Database:**
   ```sql
   SELECT * FROM student_logs WHERE created_at >= CURDATE() ORDER BY created_at DESC LIMIT 10;
   ```

3. **Monitor Real-time Events:**
   Check your browser console with Laravel Echo configured to see real-time events.

This comprehensive MQTT integration provides robust communication with biometric devices while maintaining data integrity and providing real-time notifications for the Rod-Connect system.