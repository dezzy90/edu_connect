# 🚀 How to Start the Complete System

This guide explains what needs to be running for the system to work properly.

---

## 📋 Components That Need to Be Running

Your system has 3 main components that need to be running simultaneously:

### 1. MQTT Broker (Mosquitto) ✅ Already Running
- **What it does:** Acts as message broker between Laravel and devices
- **Status:** Already running (your device is connected)
- **No action needed**

### 2. Laravel Web Server
- **What it does:** Serves the web interface for managing schools/students
- **How to start:** See below

### 3. Laravel MQTT Listener ⭐ IMPORTANT
- **What it does:** Listens for messages from devices (attendance check-ins)
- **How to start:** See below

---

## 🎯 Quick Start (3 Terminals)

You need to open **3 separate terminal windows**:

### Terminal 1: Laravel Web Server

```bash
# Navigate to project directory
cd c:/Users/NTech/rod-connect

# Start Laravel development server
php artisan serve
```

**What you'll see:**
```
Starting Laravel development server: http://127.0.0.1:8000
```

**Keep this terminal open!** This serves your web interface.

**Access at:** http://localhost:8000

---

### Terminal 2: Laravel MQTT Listener ⭐ CRITICAL

```bash
# Navigate to project directory
cd c:/Users/NTech/rod-connect

# Start MQTT subscriber (listens for device messages)
php artisan mqtt:subscribe
```

**What you'll see:**
```
🎧 MQTT Subscriber started
📡 Listening for device messages...
Connected to MQTT broker at 192.168.1.137:1883
Subscribed to: mqtt/face/+/RecPush
```

**Keep this terminal open!** This receives attendance check-ins from devices.

**What it does:**
- Listens for check-in/check-out events from devices
- Creates attendance logs in database
- Processes biometric recognition events

---

### Terminal 3: Vite Dev Server (for Frontend)

```bash
# Navigate to project directory
cd c:/Users/NTech/rod-connect

# Start Vite (compiles React/TypeScript frontend)
npm run dev
```

**What you'll see:**
```
VITE v5.x.x  ready in xxx ms

➜  Local:   http://localhost:5173/
➜  Network: use --host to expose
```

**Keep this terminal open!** This compiles your React frontend.

---

## 📊 Visual Overview

```
┌─────────────────────────────────────────────────────────┐
│                    YOUR COMPUTER                         │
│                                                          │
│  Terminal 1          Terminal 2           Terminal 3    │
│  ┌──────────┐       ┌──────────┐        ┌──────────┐   │
│  │ Laravel  │       │  MQTT    │        │  Vite    │   │
│  │  Server  │       │ Listener │        │   Dev    │   │
│  │  :8000   │       │ (Always  │        │  :5173   │   │
│  │          │       │  On!)    │        │          │   │
│  └──────────┘       └──────────┘        └──────────┘   │
│       │                   │                    │        │
└───────┼───────────────────┼────────────────────┼────────┘
        │                   │                    │
        │                   │                    │
        ▼                   ▼                    ▼
┌─────────────────────────────────────────────────────────┐
│              MQTT Broker (Mosquitto)                     │
│              192.168.1.137:1883                          │
└─────────────────────────────────────────────────────────┘
        ▲                   │
        │                   │
        │                   ▼
┌───────┴───────┐   ┌──────────────┐
│   Your Web    │   │  Biometric   │
│   Browser     │   │   Device     │
└───────────────┘   └──────────────┘
```

---

## 🔄 Complete Workflow

### When You Create a Student:

1. **You:** Fill form in web browser → Submit
2. **Laravel Server (Terminal 1):** Receives request, saves to database
3. **Laravel:** Calls PersonnelManagementService
4. **PersonnelManagementService:** Publishes EditPerson message to MQTT
5. **MQTT Broker:** Routes message to device
6. **Device:** Receives student data, stores in personnel list
7. **Web Browser:** Shows success message "Synced to X/Y devices"

### When Student Checks In:

1. **Student:** Scans finger/face on device
2. **Device:** Recognizes student, publishes RecPush message to MQTT
3. **MQTT Broker:** Routes message to Laravel
4. **MQTT Listener (Terminal 2):** ⭐ Receives RecPush message
5. **MQTT Listener:** Creates StudentLog record in database
6. **Web Interface:** Attendance log now visible

---

## ⚠️ IMPORTANT: MQTT Listener Must Always Run

**The MQTT Listener (Terminal 2) is CRITICAL because:**

- ✅ Without it: Students sync TO devices (you can create students)
- ❌ Without it: Attendance check-ins are LOST (device sends but nobody listens)

**Think of it like:**
- **Laravel Server** = Your office (where you work)
- **MQTT Listener** = Your phone (receives calls from devices)
- **Device** = Customer calling you

If your phone (MQTT Listener) is off, customers (devices) can't reach you!

---

## 🎯 Step-by-Step Startup Procedure

### Step 1: Open PowerShell/CMD (Terminal 1)
```bash
cd c:/Users/NTech/rod-connect
php artisan serve
```
✅ Leave this running

### Step 2: Open Another PowerShell/CMD (Terminal 2)
```bash
cd c:/Users/NTech/rod-connect
php artisan mqtt:subscribe
```
✅ Leave this running - THIS IS CRITICAL!

### Step 3: Open Another PowerShell/CMD (Terminal 3)
```bash
cd c:/Users/NTech/rod-connect
npm run dev
```
✅ Leave this running

### Step 4: Open Web Browser
```
Navigate to: http://localhost:8000
```

---

## 🧪 Test Everything is Working

### Test 1: Web Interface
```
Open: http://localhost:8000/admin/login
Login with your admin credentials
You should see the dashboard
```

### Test 2: MQTT Listener
In Terminal 2 (MQTT Listener), you should see:
```
🎧 MQTT Subscriber started
📡 Listening for device messages...
Connected to MQTT broker
```

### Test 3: Create a Student
```
1. Go to: http://localhost:8000/admin/students/create
2. Fill the form
3. Submit
4. Check Terminal 2 - you should see sync messages
5. Success message should show "Synced to X/Y devices"
```

### Test 4: Device Check-In (If device is ready)
```
1. Student scans on device
2. Check Terminal 2 - you should see RecPush message
3. Check web interface - attendance log should appear
```

---

## 🔍 Troubleshooting

### "Port 8000 already in use"
```bash
# Find what's using port 8000
netstat -ano | findstr "8000"

# Kill the process (replace PID with actual number)
taskkill /PID <PID> /F

# Or use different port
php artisan serve --port=8001
```

### "MQTT connection failed"
```bash
# Check if Mosquitto is running
sc query mosquitto

# If not running, start it
net start mosquitto
```

### "No messages in MQTT Listener"
```bash
# Check if listener is actually running
# You should see "Listening for device messages..."

# If not, restart it:
# Press Ctrl+C to stop
# Then run again:
php artisan mqtt:subscribe
```

### "Frontend not loading"
```bash
# Check if Vite is running
# You should see "ready in xxx ms"

# If not, restart it:
npm run dev
```

---

## 📝 Daily Startup Checklist

Every time you start working:

- [ ] Open Terminal 1: `php artisan serve`
- [ ] Open Terminal 2: `php artisan mqtt:subscribe` ⭐ CRITICAL
- [ ] Open Terminal 3: `npm run dev`
- [ ] Open Browser: `http://localhost:8000`
- [ ] Verify MQTT Listener shows "Listening for device messages..."

---

## 🎯 Production Deployment (Future)

For production, you'll want to:

1. **Use a process manager** (like Supervisor) to keep MQTT listener running
2. **Build frontend** instead of dev server: `npm run build`
3. **Use proper web server** (Nginx/Apache) instead of `php artisan serve`
4. **Set up systemd/Windows Service** for MQTT listener

But for development/testing, the 3-terminal approach works perfectly!

---

## 💡 Pro Tips

### Keep Terminals Organized
```
Terminal 1 (Laravel): Title it "Laravel Server"
Terminal 2 (MQTT): Title it "MQTT LISTENER - KEEP RUNNING!"
Terminal 3 (Vite): Title it "Vite Dev"
```

### Monitor Everything
```bash
# In Terminal 2, you'll see real-time activity:
- Student sync messages when you create students
- RecPush messages when devices send check-ins
- Any MQTT errors or connection issues
```

### Quick Restart All
Create a batch file `start-all.bat`:
```batch
@echo off
start cmd /k "cd c:\Users\NTech\rod-connect && php artisan serve"
start cmd /k "cd c:\Users\NTech\rod-connect && php artisan mqtt:subscribe"
start cmd /k "cd c:\Users\NTech\rod-connect && npm run dev"
start http://localhost:8000
```

Double-click this file to start everything at once!

---

## ✅ Summary

**What needs to be running:**

1. ✅ Mosquitto (MQTT Broker) - Already running
2. ✅ Laravel Server - `php artisan serve`
3. ⭐ **MQTT Listener** - `php artisan mqtt:subscribe` - **CRITICAL!**
4. ✅ Vite Dev Server - `npm run dev`

**The MQTT Listener is the key component you were missing!**

Without it, your devices can send messages but nobody is listening, so attendance check-ins would be lost.

**Now you're ready to test the complete flow!** 🎉
