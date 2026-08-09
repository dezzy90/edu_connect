# Heartbeat Update Implementation TODO

## Goal
Ensure that when a device sends a heartbeat (or any message), it updates the `last_heartbeat` column in the `biometric_devices` table reliably.

## Tasks

### 1. Update DeviceRegistrationService.php
- [x] Reduce cache duration from 5 minutes to 1 minute for more responsive updates
- [x] Add option to force immediate heartbeat update
- [x] Improve logging for heartbeat updates

### 2. Update BiometricMessageProcessor.php
- [x] Improve `processHeartbeat()` method to handle messages without explicit device_id
- [x] Add device_id parameter to processHeartbeat() method
- [x] Ensure all message processing methods update heartbeat (already implemented)

### 3. Update RealDeviceMessageProcessor.php
- [x] Add explicit heartbeat update at the start of `processRealDeviceMessage()`
- [x] Ensure heartbeat updates even if message processing fails

### 4. Update MqttSubscriberCommand.php
- [x] Extract device_id from topic for heartbeat messages
- [x] Pass device_id to processHeartbeat() when available

## Testing
- [ ] Test with real device heartbeat messages
- [ ] Verify online/offline status detection
- [ ] Check dashboard displays correct device status
- [ ] Monitor logs for heartbeat confirmations

## Status
✅ Implementation Complete - Ready for Testing

## Summary of Changes

### Files Modified:
1. **app/Services/DeviceRegistrationService.php**
   - Reduced cache duration from 5 minutes to 1 minute
   - Added `forceUpdateHeartbeat()` method for immediate updates
   - Added debug logging for heartbeat updates

2. **app/Services/BiometricMessageProcessor.php**
   - Updated `processHeartbeat()` to accept optional `$deviceId` parameter
   - Added logic to update heartbeat from topic-extracted device_id
   - Improved logging for heartbeat updates

3. **app/Services/RealDeviceMessageProcessor.php**
   - Added immediate heartbeat update at the start of `processRealDeviceMessage()`
   - Ensures heartbeat is updated before message processing begins
   - Added debug logging

4. **app/Console/Commands/MqttSubscriberCommand.php**
   - Modified heartbeat case to pass device_id to `processHeartbeat()`
   - Extracts device_id from message info when available

### How It Works Now:
1. **All Device Messages**: Every message type (recognition, capture, QR, etc.) updates heartbeat
2. **Dedicated Heartbeat Messages**: Can extract device_id from topic or message content
3. **Real Device Messages**: Heartbeat updated immediately upon message receipt
4. **Faster Updates**: Cache reduced to 1 minute for more responsive status tracking
5. **Better Logging**: Debug logs show when and how heartbeat is updated

### Next Steps:
- Test the implementation with real devices
- Monitor logs to confirm heartbeat updates are working
- Verify device online/offline status in the dashboard
