@echo off
echo Card Graph — PC Worker (Local Whisper Transcription)
echo.
echo Starting local server on http://localhost:8891
echo Use the web app's PC Worker controls to start/stop transcription.
echo.
pip install openai-whisper flask flask-cors pymysql >nul 2>&1
python "%~dp0pc_worker.py"
pause
