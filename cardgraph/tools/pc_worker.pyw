"""
Card Graph — PC Worker Launcher (Silent / No Console)

Double-click this file to start the PC Worker in the background.
The web app will detect it automatically and show the GO button as active.
To stop: close this from Task Manager (pythonw.exe) or use the web app's stop button.
"""
import subprocess
import sys
import os

# Ensure dependencies are installed
try:
    import flask
    import flask_cors
    import pymysql
    import whisper
except ImportError:
    # Install missing packages silently
    subprocess.check_call(
        [sys.executable, '-m', 'pip', 'install', 'openai-whisper', 'flask', 'flask-cors', 'pymysql'],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
    )

# Run the worker
script_dir = os.path.dirname(os.path.abspath(__file__))
worker_path = os.path.join(script_dir, 'pc_worker.py')

# Import and run directly
sys.path.insert(0, script_dir)
import pc_worker
pc_worker.app.run(host='0.0.0.0', port=pc_worker.PORT, debug=False)
