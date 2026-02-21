@echo off
title Card Graph - PC Worker Service
echo ============================================================
echo   Card Graph - PC Worker Service
echo ============================================================
echo.

set PYTHON=%LOCALAPPDATA%\Programs\Python\Python312\python.exe
set SCRIPT=%~dp0pc_worker_service.py

if not exist "%PYTHON%" (
    echo ERROR: Python not found at %PYTHON%
    echo Install Python 3.12 or update the path in this script.
    pause
    exit /b 1
)

echo Starting local worker service...
echo The web UI will auto-detect this service.
echo Press Ctrl+C to stop.
echo.

"%PYTHON%" "%SCRIPT%" %*
pause
