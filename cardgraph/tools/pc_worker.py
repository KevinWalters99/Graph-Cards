"""
Card Graph — PC Worker (Local Whisper Transcription Server)

Runs on your PC (with GPU) and serves a simple HTTP API on port 8891.
The web UI talks to this server to start/stop transcription and check status.

Usage:
    python pc_worker.py
    (or run transcribe_local.bat)

Requires: pip install openai-whisper flask flask-cors pymysql
"""
import argparse
import os
import sys
import threading
import time
from datetime import datetime

import pymysql
from flask import Flask, jsonify, request
from flask_cors import CORS

# ─── Config ─────────────────────────────────────────────────────
DB_CONFIG = {
    'host': '192.168.0.215',
    'port': 3307,
    'user': 'cg_app',
    'password': 'ACe!sysD#0kVnBWF',
    'database': 'card_graph',
    'charset': 'utf8mb4',
}

# NAS share path (mapped or UNC)
NAS_SHARE = r'\\192.168.0.215\web\cardgraph'

PORT = 8891

# ─── State ──────────────────────────────────────────────────────
app = Flask(__name__)
CORS(app)

worker_state = {
    'status': 'idle',        # idle | loading | transcribing | stopping
    'model': None,
    'loaded_model': None,
    'session_id': None,
    'current_segment': None,
    'completed': 0,
    'errors': 0,
}
whisper_model = None
worker_thread = None
stop_flag = threading.Event()


def get_db():
    return pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor, autocommit=True)


def log_event(db, session_id, level, event_type, message):
    with db.cursor() as cur:
        cur.execute(
            "INSERT INTO CG_TranscriptionLogs (session_id, log_level, event_type, message) "
            "VALUES (%s, %s, %s, %s)",
            (session_id, level, event_type, message)
        )
    print(f"[{level.upper()}] [{event_type}] {message}")


def nas_path(unix_path):
    """Convert NAS unix path to Windows UNC path."""
    # /volume1/web/cardgraph/archive/... → \\192.168.0.215\web\cardgraph\archive\...
    if unix_path and unix_path.startswith('/volume1/web/cardgraph/'):
        rel = unix_path.replace('/volume1/web/cardgraph/', '')
        return os.path.join(NAS_SHARE, rel.replace('/', '\\'))
    return unix_path


# ─── Worker Thread ──────────────────────────────────────────────
def worker_loop(session_id, model_name):
    global whisper_model, worker_state

    db = get_db()

    # Load model
    worker_state['status'] = 'loading'
    worker_state['model'] = model_name
    try:
        import whisper
        print(f"Loading Whisper model: {model_name}")
        whisper_model = whisper.load_model(model_name)
        worker_state['loaded_model'] = model_name
        print(f"Model loaded: {model_name}")
    except Exception as e:
        print(f"ERROR loading model: {e}")
        log_event(db, session_id, 'error', 'pc_model_error', f'Failed to load {model_name}: {e}')
        worker_state['status'] = 'idle'
        db.close()
        return

    log_event(db, session_id, 'info', 'pc_worker_started', f'PC Worker started (model: {model_name})')
    worker_state['status'] = 'transcribing'
    worker_state['completed'] = 0
    worker_state['errors'] = 0

    # Reset skipped/error segments so they can be retried
    print(f"[DEBUG] Resetting skipped/error segments for session {session_id}")
    with db.cursor() as cur:
        cur.execute(
            "UPDATE CG_TranscriptionSegments SET transcription_status = 'pending', error_message = NULL "
            "WHERE session_id = %s AND recording_status = 'complete' "
            "AND transcription_status IN ('skipped', 'error')",
            (session_id,)
        )
        reset_count = cur.rowcount
        print(f"[DEBUG] Reset {reset_count} segment(s) to pending")
        # Verify
        cur.execute(
            "SELECT segment_id, transcription_status FROM CG_TranscriptionSegments "
            "WHERE session_id = %s AND recording_status = 'complete'",
            (session_id,)
        )
        rows = cur.fetchall()
        for r in rows:
            print(f"[DEBUG] Segment {r['segment_id']}: status={r['transcription_status']}")

    try:
        while not stop_flag.is_set():
            # Find next pending segment
            with db.cursor() as cur:
                cur.execute(
                    "SELECT s.segment_id, s.segment_number, s.filename_audio, "
                    "       sess.session_dir "
                    "FROM CG_TranscriptionSegments s "
                    "JOIN CG_TranscriptionSessions sess ON sess.session_id = s.session_id "
                    "WHERE s.session_id = %s AND s.recording_status = 'complete' "
                    "AND s.transcription_status = 'pending' "
                    "ORDER BY s.segment_number ASC LIMIT 1",
                    (session_id,)
                )
                segment = cur.fetchone()

            if not segment:
                print("No more pending segments.")
                log_event(db, session_id, 'info', 'pc_worker_done', 'All segments transcribed')
                break

            seg_id = segment['segment_id']
            seg_num = segment['segment_number']
            audio_file = segment['filename_audio']
            session_dir = nas_path(segment['session_dir'])

            worker_state['current_segment'] = seg_num

            audio_path = os.path.join(session_dir, 'audio', audio_file)
            tx_dir = os.path.join(session_dir, 'transcripts')
            tx_filename = os.path.splitext(audio_file)[0] + '.txt'
            tx_path = os.path.join(tx_dir, tx_filename)

            if not os.path.exists(audio_path):
                print(f"Audio not found: {audio_path}")
                with db.cursor() as cur:
                    cur.execute(
                        "UPDATE CG_TranscriptionSegments SET transcription_status = 'skipped', "
                        "error_message = 'Audio file not found (PC)' WHERE segment_id = %s",
                        (seg_id,)
                    )
                worker_state['errors'] += 1
                continue

            # Mark transcribing
            with db.cursor() as cur:
                cur.execute(
                    "UPDATE CG_TranscriptionSegments SET transcription_status = 'transcribing' "
                    "WHERE segment_id = %s", (seg_id,)
                )

            log_event(db, session_id, 'info', 'pc_transcribing',
                      f'Transcribing segment {seg_num}: {audio_file}')

            try:
                # Run Whisper
                result = whisper_model.transcribe(str(audio_path), language='en', fp16=True)
                text = result.get('text', '').strip()

                # Ensure transcripts dir exists
                os.makedirs(tx_dir, exist_ok=True)

                # Write transcript
                with open(tx_path, 'w', encoding='utf-8') as f:
                    f.write(text)
                    f.write('\n')

                # Mark complete
                with db.cursor() as cur:
                    cur.execute(
                        "UPDATE CG_TranscriptionSegments SET "
                        "transcription_status = 'complete', transcription_progress = 100, "
                        "filename_transcript = %s WHERE segment_id = %s",
                        (tx_filename, seg_id)
                    )

                word_count = len(text.split()) if text else 0
                log_event(db, session_id, 'info', 'pc_transcription_complete',
                          f'Segment {seg_num} done: {word_count} words')
                worker_state['completed'] += 1

            except Exception as e:
                print(f"ERROR transcribing segment {seg_num}: {e}")
                log_event(db, session_id, 'error', 'pc_transcription_error',
                          f'Segment {seg_num} failed: {e}')
                with db.cursor() as cur:
                    cur.execute(
                        "UPDATE CG_TranscriptionSegments SET transcription_status = 'error', "
                        "error_message = %s WHERE segment_id = %s",
                        (str(e)[:500], seg_id)
                    )
                worker_state['errors'] += 1

    finally:
        worker_state['status'] = 'idle'
        worker_state['current_segment'] = None
        worker_state['session_id'] = None
        stop_flag.clear()
        db.close()


# ─── HTTP API ───────────────────────────────────────────────────
@app.route('/status', methods=['GET'])
def status():
    return jsonify(worker_state)


@app.route('/start', methods=['POST'])
def start():
    global worker_thread
    if worker_state['status'] != 'idle':
        return jsonify({'ok': False, 'error': 'Already running'}), 409

    data = request.get_json(silent=True) or {}
    session_id = data.get('session_id')
    model = data.get('model', 'small')

    if not session_id:
        return jsonify({'ok': False, 'error': 'session_id required'}), 400

    worker_state['session_id'] = session_id
    stop_flag.clear()

    worker_thread = threading.Thread(target=worker_loop, args=(session_id, model), daemon=True)
    worker_thread.start()

    return jsonify({'ok': True, 'session_id': session_id, 'model': model})


@app.route('/stop', methods=['POST'])
def stop():
    if worker_state['status'] in ('transcribing', 'loading'):
        worker_state['status'] = 'stopping'
        stop_flag.set()
        return jsonify({'ok': True, 'message': 'Stopping after current segment'})
    return jsonify({'ok': False, 'error': 'Not running'}), 400


if __name__ == '__main__':
    print(f"Card Graph PC Worker — listening on http://localhost:{PORT}")
    print("Open the web app and use the PC Worker controls in the session monitor.")
    app.run(host='0.0.0.0', port=PORT, debug=False)
