# 🦟 Mosquitto MQTT Broker Setup Guide

Complete guide to install, configure, and start Mosquitto MQTT broker on Windows.

---

## 📥 Installation

### Option 1: Download Installer (Recommended)

1. **Download Mosquitto:**
   - Visit: https://mosquitto.org/download/
   - Download Windows 64-bit installer (e.g., `mosquitto-2.0.18-install-windows-x64.exe`)

2. **Run Installer:**
   - Double-click the downloaded file
   - Follow installation wizard
   - Default location: `C:\Program Files\mosquitto`

3. **Add to PATH (Optional but recommended):**
   - Right-click "This PC" → Properties
   - Advanced system settings → Environment Variables
   - Under System Variables, find "Path"
   - Click Edit → New
   - Add: `C:\Program Files\mosquitto`
   - Click OK on all dialogs

### Option 2: Using Chocolatey

```powershell
# Run PowerShell as Administrator
choco install mosquitto
```

---

## ⚙️ Configuration

### 1. Create Configuration File

Navigate to Mosquitto installation directory:
```powershell
cd "C:\Program Files\mosquitto"
```

Create/Edit `mosquitto.conf`:

```conf
# Mosquitto Configuration for Rod-Connect

# Listener Configuration
listener 1883
protocol mqtt

# Allow anonymous connections (for testing)
# Set to false in production
allow_anonymous false

# Password file
password_file C:\Program Files\mosquitto\passwd

# Logging
log_dest file C:\Program Files\mosquitto\mosquitto.log
log_type all

# Persistence
persistence true
persistence_location C:\Program Files\mosquitto\data\

# Connection settings
max_connections -1
max_keepalive 65535

# Security
require_certificate false
```

### 2. Create Password File

```powershell
# Navigate to Mosquitto directory
cd "C:\Program Files\mosquitto"

# Create password for rodadmin user
mosquitto_passwd -c passwd rodadmin
# Enter password when prompted: YOUR_MQTT_PASSWORD
# Enter again to confirm
```

### 3. Create Data Directory

```powershell
mkdir "C:\Program Files\mosquitto\data"
```

---

## 🚀 Starting Mosquitto

### Method 1: As Windows Service (Recommended for Production)

**Install Service:**
```powershell
# Run PowerShell as Administrator
cd "C:\Program Files\mosquitto"

# Install service
mosquitto install

# Start service
net start mosquitto

# Check service status
sc query mosquitto
```

**Service Management:**
```powershell
# Start service
net start mosquitto

# Stop service
net stop mosquitto

# Restart service
net stop mosquitto && net start mosquitto

# Uninstall service (if needed)
mosquitto uninstall
```

### Method 2: Run Manually (For Testing)

**In PowerShell:**
```powershell
# Navigate to Mosquitto directory
cd "C:\Program Files\mosquitto"

# Start with config file
mosquitto -c mosquitto.conf -v
```

**Keep this window open** - Mosquitto will run in foreground and show logs.

### Method 3: Run in Background

**Create a batch file** `start-mosquitto.bat`:
```batch
@echo off
cd "C:\Program Files\mosquitto"
start /B mosquitto -c mosquitto.conf
echo Mosquitto started in background
```

Run the batch file to start Mosquitto in background.

---

## ✅ Verify Mosquitto is Running

### Check if Service is Running:

```powershell
# Check service status
sc query mosquitto

# Or use Task Manager
# Press Ctrl+Shift+Esc
# Go to Services tab
# Look for "Mosquitto Broker"
```

### Check if Port is Listening:

```powershell
# Check if port 1883 is open
netstat -an | findstr "1883"

# Should show something like:
# TCP    0.0.0.0:1883           0.0.0.0:0              LISTENING
```

### Test Connection:

```powershell
# Subscribe to test topic
mosquitto_sub -h localhost -p 1883 -u rodadmin -P YOUR_MQTT_PASSWORD -t "test/topic" -v

# In another terminal, publish a message
mosquitto_pub -h localhost -p 1883 -u rodadmin -P YOUR_MQTT_PASSWORD -t "test/topic" -m "Hello MQTT!"

# You should see the message in the subscriber terminal
```

---

## 🔧 Troubleshooting

### Issue 1: "mosquitto: command not found"

**Solution:** Add Mosquitto to PATH or use full path:
```powershell
"C:\Program Files\mosquitto\mosquitto.exe" -c "C:\Program Files\mosquitto\mosquitto.conf"
```

### Issue 2: "Error: Address already in use"

**Solution:** Port 1883 is already in use. Check what's using it:
```powershell
netstat -ano | findstr "1883"
# Note the PID (last column)

# Kill the process
taskkill /PID <PID> /F
```

### Issue 3: "Error: Unable to open config file"

**Solution:** Specify full path to config:
```powershell
mosquitto -c "C:\Program Files\mosquitto\mosquitto.conf"
```

### Issue 4: Permission Denied

**Solution:** Run PowerShell as Administrator:
- Right-click PowerShell
- Select "Run as Administrator"

### Issue 5: Service Won't Start

**Check logs:**
```powershell
# View log file
type "C:\Program Files\mosquitto\mosquitto.log"

# Or use tail equivalent
Get-Content "C:\Program Files\mosquitto\mosquitto.log" -Tail 50 -Wait
```

**Common fixes:**
```powershell
# Reinstall service
mosquitto uninstall
mosquitto install
net start mosquitto
```

---

## 🔐 Security Configuration (Production)

### 1. Disable Anonymous Access

Edit `mosquitto.conf`:
```conf
allow_anonymous false
password_file C:\Program Files\mosquitto\passwd
```

### 2. Add Multiple Users

```powershell
# Add more users
mosquitto_passwd passwd deviceuser
mosquitto_passwd passwd adminuser

# Update existing user password
mosquitto_passwd passwd rodadmin
```

### 3. Configure ACL (Access Control List)

Create `acl.conf`:
```conf
# Admin has full access
user rodadmin
topic readwrite #

# Devices can only publish to their topics
user deviceuser
topic write mqtt/face/+
topic read mqtt/face/+/Ack

# Read-only user
user readonly
topic read #
```

Update `mosquitto.conf`:
```conf
acl_file C:\Program Files\mosquitto\acl.conf
```

### 4. Enable TLS/SSL (Optional)

```conf
listener 8883
protocol mqtt
cafile C:\Program Files\mosquitto\certs\ca.crt
certfile C:\Program Files\mosquitto\certs\server.crt
keyfile C:\Program Files\mosquitto\certs\server.key
require_certificate false
```

---

## 📊 Monitoring Mosquitto

### View Live Logs:

```powershell
# PowerShell
Get-Content "C:\Program Files\mosquitto\mosquitto.log" -Tail 50 -Wait

# Or use a log viewer tool
```

### Monitor All Topics:

```powershell
# Subscribe to all topics
mosquitto_sub -h localhost -p 1883 -u rodadmin -P YOUR_MQTT_PASSWORD -t "#" -v
```

### Monitor Device Topics Only:

```powershell
# Subscribe to device topics
mosquitto_sub -h localhost -p 1883 -u rodadmin -P YOUR_MQTT_PASSWORD -t "mqtt/face/#" -v
```

### Check Broker Statistics:

```powershell
# Subscribe to system topics
mosquitto_sub -h localhost -p 1883 -u rodadmin -P YOUR_MQTT_PASSWORD -t "\$SYS/#" -v
```

---

## 🔄 Integration with Rod-Connect

### 1. Update Laravel Configuration

Edit `config/mqtt.php`:
```php
return [
    'host' => env('MQTT_HOST', 'localhost'),
    'port' => env('MQTT_PORT', 1883),
    'username' => env('MQTT_USERNAME', 'rodadmin'),
    'password' => env('MQTT_PASSWORD', 'YOUR_MQTT_PASSWORD'),
    'client_id' => env('MQTT_CLIENT_ID', 'rod-connect'),
];
```

Edit `.env`:
```env
MQTT_HOST=localhost
MQTT_PORT=1883
MQTT_USERNAME=rodadmin
MQTT_PASSWORD=YOUR_MQTT_PASSWORD
```

### 2. Start MQTT Subscriber

```bash
# In your Laravel project directory
php artisan mqtt:subscribe
```

Keep this running to receive device messages.

### 3. Test Connection from Laravel

```bash
php artisan tinker
```

```php
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

$client = new MqttClient('localhost', 1883, 'test-' . time());
$settings = (new ConnectionSettings())
    ->setUsername('rodadmin')
    ->setPassword('YOUR_MQTT_PASSWORD');

try {
    $client->connect($settings);
    echo "✅ Connected to Mosquitto!\n";
    
    // Publish test message
    $client->publish('test/laravel', 'Hello from Laravel!', 0);
    echo "✅ Message published!\n";
    
    $client->disconnect();
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
```

---

## 🎯 Quick Start Checklist

- [ ] Mosquitto installed
- [ ] Configuration file created
- [ ] Password file created with rodadmin user
- [ ] Data directory created
- [ ] Service installed and started
- [ ] Port 1883 is listening
- [ ] Test connection successful
- [ ] Laravel can connect to Mosquitto
- [ ] MQTT subscriber running

---

## 📝 Useful Commands Reference

```powershell
# Service Management
net start mosquitto          # Start service
net stop mosquitto           # Stop service
sc query mosquitto           # Check status

# Testing
mosquitto_sub -h localhost -p 1883 -u rodadmin -P YOUR_MQTT_PASSWORD -t "test" -v
mosquitto_pub -h localhost -p 1883 -u rodadmin -P YOUR_MQTT_PASSWORD -t "test" -m "Hello"

# Monitoring
netstat -an | findstr "1883"                    # Check port
Get-Content mosquitto.log -Tail 50 -Wait        # View logs
mosquitto_sub -t "#" -v                         # Monitor all topics

# User Management
mosquitto_passwd -c passwd username             # Create user
mosquitto_passwd passwd username                # Update password
mosquitto_passwd -D passwd username             # Delete user
```

---

## 🚀 Production Deployment

### Recommended Setup:

1. **Install as Windows Service**
2. **Configure automatic startup:**
   ```powershell
   sc config mosquitto start= auto
   ```

3. **Set up monitoring:**
   - Use Windows Event Viewer
   - Set up log rotation
   - Monitor disk space

4. **Backup configuration:**
   - Backup `mosquitto.conf`
   - Backup `passwd` file
   - Backup `acl.conf` (if used)

5. **Security hardening:**
   - Disable anonymous access
   - Use strong passwords
   - Configure ACL
   - Consider TLS/SSL

---

## 📞 Support

If Mosquitto won't start:
1. Check logs: `C:\Program Files\mosquitto\mosquitto.log`
2. Verify port 1883 is not in use
3. Run as Administrator
4. Check configuration file syntax
5. Verify password file exists

---

**Mosquitto is now ready for Rod-Connect! 🎉**

Next steps:
1. Start Mosquitto service
2. Start Laravel MQTT subscriber: `php artisan mqtt:subscribe`
3. Create a test student and verify device sync
4. Follow `DEVICE_TESTING_GUIDE.md` for complete testing
