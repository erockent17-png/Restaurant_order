@echo off
cd /d "%~dp0"
if not exist node_modules\express (
  echo Installing dependencies...
  call npm install
)
echo Starting Gauteng Order. The server will print a Wi-Fi URL for other devices.
start "Gauteng Order" /min cmd /c "npm start"
timeout /t 2 /nobreak >nul
start "" http://localhost:3000
pause
