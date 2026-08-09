@echo off
echo ========================================
echo Starting Rod-Connect System
echo ========================================
echo.
echo Opening 3 terminals...
echo.

REM Terminal 1: Laravel Server
start "Laravel Server - Port 8000" cmd /k "cd /d %~dp0 && echo Starting Laravel Server... && php artisan serve"

REM Wait 2 seconds
timeout /t 2 /nobreak >nul

REM Terminal 2: MQTT Listener (CRITICAL!)
start "MQTT Listener - KEEP THIS RUNNING!" cmd /k "cd /d %~dp0 && echo Starting MQTT Listener... && echo THIS TERMINAL MUST STAY OPEN! && echo. && php artisan mqtt:subscribe"

REM Wait 2 seconds
timeout /t 2 /nobreak >nul

REM Terminal 3: Vite Dev Server
start "Vite Dev Server - Port 5173" cmd /k "cd /d %~dp0 && echo Starting Vite Dev Server... && npm run dev"

REM Wait 5 seconds for servers to start
timeout /t 5 /nobreak >nul

REM Open browser
echo.
echo Opening browser...
start http://localhost:8000

echo.
echo ========================================
echo System Started!
echo ========================================
echo.
echo 3 terminals have been opened:
echo   1. Laravel Server (Port 8000)
echo   2. MQTT Listener (CRITICAL - Keep Running!)
echo   3. Vite Dev Server (Port 5173)
echo.
echo Browser opening at: http://localhost:8000
echo.
echo IMPORTANT: Do NOT close the terminal windows!
echo ========================================
pause
