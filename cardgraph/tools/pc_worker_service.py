"""
Card Graph - PC Worker Service

Local HTTP server that the web UI talks to for integrated PC transcription.
Runs on localhost:8891. The web UI auto-detects it and shows inline controls.

Usage:
    python pc_worker_service.py
    python pc_worker_service.py --port 8891

Endpoints:
    GET  /status  — worker state, current segment, model info
    POST /start   — begin transcribing (body: {"session_id": 15, "model": "large"})
    POST /stop    — stop after current segment
"""
import glob
import json
import os
import sys
import threading
import time
from datetime import datetime
from http.server import HTTPServer, BaseHTTPRequestHandler

# Ensure ffmpeg is on PATH (winget installs may not be in PATH until shell restart)
_ffmpeg_pattern = os.path.join(
    os.environ.get('LOCALAPPDATA', ''),
    'Microsoft', 'WinGet', 'Packages', '*FFmpeg*', '*', 'bin'
)
for _ffdir in glob.glob(_ffmpeg_pattern):
    if os.path.isfile(os.path.join(_ffdir, 'ffmpeg.exe')):
        os.environ['PATH'] = _ffdir + os.pathsep + os.environ.get('PATH', '')
        break

import pymysql

DB_CONFIG = {
    'host': '192.168.0.215',
    'port': 3307,
    'user': 'cg_app',
    'password': 'ACe!sysD#0kVnBWF',
    'database': 'card_graph',
    'charset': 'utf8mb4',
}

NAS_LINUX_PREFIX = '/volume1/web/cardgraph/'
NAS_UNC_PREFIX = r'\\192.168.0.215\web\cardgraph' + '\\'

# ─── Worker State ────────────────────────────────────────────

worker = {
    'status': 'idle',           # idle | loading | transcribing | stopping
    'session_id': None,
    'model': None,
    'current_segment': None,    # segment_number
    'current_file': None,
    'started_at': None,
    'completed': 0,
    'errors': 0,
    'total_pending': 0,
}
worker_lock = threading.Lock()
worker_thread = None
whisper_model_obj = None
whisper_model_name = None


# ─── DB / Utility ────────────────────────────────────────────

def get_db():
    return pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor, autocommit=True)


def nas_to_unc(linux_path):
    if linux_path and linux_path.startswith(NAS_LINUX_PREFIX):
        remainder = linux_path[len(NAS_LINUX_PREFIX):]
        return NAS_UNC_PREFIX + remainder.replace('/', '\\')
    return linux_path


def log_event(db, session_id, level, event_type, message):
    with db.cursor() as cur:
        cur.execute(
            "INSERT INTO CG_TranscriptionLogs (session_id, log_level, event_type, message) "
            "VALUES (%s, %s, %s, %s)",
            (session_id, level, event_type, message)
        )
    ts = datetime.now().strftime('%H:%M:%S')
    print(f"  [{ts}] [{level.upper()}] {message}", flush=True)


def claim_segment(db, session_id):
    """Atomically claim one pending segment."""
    with db.cursor() as cur:
        cur.execute(
            "SELECT segment_id FROM CG_TranscriptionSegments "
            "WHERE session_id = %s AND recording_status = 'complete' "
            "AND transcription_status = 'pending' "
            "ORDER BY segment_number ASC LIMIT 1",
            (session_id,)
        )
        row = cur.fetchone()
        if not row:
            return None

        cur.execute(
            "UPDATE CG_TranscriptionSegments "
            "SET transcription_status = 'transcribing' "
            "WHERE segment_id = %s AND transcription_status = 'pending'",
            (row['segment_id'],)
        )
        if cur.rowcount == 0:
            return None

        cur.execute(
            "SELECT * FROM CG_TranscriptionSegments WHERE segment_id = %s",
            (row['segment_id'],)
        )
        return cur.fetchone()


def get_session_dir(db, session_id):
    with db.cursor() as cur:
        cur.execute(
            "SELECT session_dir FROM CG_TranscriptionSessions WHERE session_id = %s",
            (session_id,)
        )
        row = cur.fetchone()
        return row['session_dir'] if row else None


# ─── Transcription Worker Thread ─────────────────────────────

def worker_loop(session_id, model_name, max_segments=0):
    global whisper_model_obj, whisper_model_name

    with worker_lock:
        worker['status'] = 'loading'
        worker['session_id'] = session_id
        worker['model'] = model_name
        worker['max_segments'] = max_segments or 0
        worker['completed'] = 0
        worker['errors'] = 0
        worker['current_segment'] = None
        worker['current_file'] = None
        worker['started_at'] = datetime.now().strftime('%H:%M:%S')

    # Load model (reuse if same model already loaded)
    if whisper_model_obj is None or whisper_model_name != model_name:
        print(f"Loading Whisper model: {model_name}...", flush=True)
        try:
            import whisper
            whisper_model_obj = whisper.load_model(model_name)
            whisper_model_name = model_name
            print(f"Model loaded successfully", flush=True)
        except Exception as e:
            print(f"ERROR loading model: {e}", flush=True)
            with worker_lock:
                worker['status'] = 'idle'
            return

    db = get_db()

    # Count pending
    with db.cursor() as cur:
        cur.execute(
            "SELECT COUNT(*) as cnt FROM CG_TranscriptionSegments "
            "WHERE session_id = %s AND recording_status = 'complete' "
            "AND transcription_status = 'pending'",
            (session_id,)
        )
        with worker_lock:
            worker['total_pending'] = cur.fetchone()['cnt']
            worker['status'] = 'transcribing'

    print(f"Starting: session {session_id}, {worker['total_pending']} pending segments", flush=True)
    session_dir_linux = get_session_dir(db, session_id)
    session_dir = nas_to_unc(session_dir_linux) if session_dir_linux else None

    while True:
        with worker_lock:
            if worker['status'] == 'stopping':
                break
            # Stop if we hit the segment limit
            if max_segments > 0 and worker['completed'] >= max_segments:
                break

        segment = claim_segment(db, session_id)
        if not segment:
            # Check if there are still pending (might be new ones from ongoing recording)
            with db.cursor() as cur:
                cur.execute(
                    "SELECT COUNT(*) as cnt FROM CG_TranscriptionSegments "
                    "WHERE session_id = %s AND recording_status = 'complete' "
                    "AND transcription_status = 'pending'",
                    (session_id,)
                )
                remaining = cur.fetchone()['cnt']
            if remaining == 0:
                break
            time.sleep(5)
            continue

        seg_id = segment['segment_id']
        seg_num = segment['segment_number']
        audio_file = segment['filename_audio']

        with worker_lock:
            worker['current_segment'] = seg_num
            worker['current_file'] = audio_file

        if not audio_file or not session_dir:
            with db.cursor() as cur:
                cur.execute(
                    "UPDATE CG_TranscriptionSegments SET transcription_status = 'skipped' "
                    "WHERE segment_id = %s", (seg_id,)
                )
            continue

        audio_path = os.path.join(session_dir, 'audio', audio_file)
        tx_dir = os.path.join(session_dir, 'transcripts')
        tx_filename = os.path.splitext(audio_file)[0] + '.txt'
        tx_path = os.path.join(tx_dir, tx_filename)

        if not os.path.exists(audio_path):
            log_event(db, session_id, 'warning', 'audio_missing',
                      f"PC: Audio file not found: {audio_file}")
            with db.cursor() as cur:
                cur.execute(
                    "UPDATE CG_TranscriptionSegments SET transcription_status = 'skipped', "
                    "error_message = 'Audio file not found (PC)' WHERE segment_id = %s",
                    (seg_id,)
                )
            continue

        log_event(db, session_id, 'info', 'pc_transcribing',
                  f"PC transcribing SEG {seg_num}: {audio_file} (model: {model_name})")

        start_time = time.time()
        try:
            result = whisper_model_obj.transcribe(audio_path, language='en', fp16=False)
            text = result.get('text', '').strip()

            os.makedirs(os.path.dirname(tx_path), exist_ok=True)
            with open(tx_path, 'w', encoding='utf-8') as f:
                f.write(text)
                f.write('\n')

            elapsed = time.time() - start_time
            word_count = len(text.split()) if text else 0

            with db.cursor() as cur:
                cur.execute(
                    "UPDATE CG_TranscriptionSegments SET "
                    "transcription_status = 'complete', transcription_progress = 100, "
                    "filename_transcript = %s WHERE segment_id = %s",
                    (tx_filename, seg_id)
                )

            log_event(db, session_id, 'info', 'pc_transcription_complete',
                      f"PC SEG {seg_num} done: {word_count} words in {elapsed:.1f}s")
            print(f"  SEG {seg_num:03d}: {word_count} words, {elapsed:.1f}s", flush=True)

            with worker_lock:
                worker['completed'] += 1

        except Exception as e:
            elapsed = time.time() - start_time
            log_event(db, session_id, 'error', 'pc_transcription_error',
                      f"PC SEG {seg_num} failed: {str(e)[:200]}")
            with db.cursor() as cur:
                cur.execute(
                    "UPDATE CG_TranscriptionSegments SET transcription_status = 'error', "
                    "error_message = %s WHERE segment_id = %s",
                    (str(e)[:500], seg_id)
                )
            with worker_lock:
                worker['errors'] += 1

    db.close()
    with worker_lock:
        worker['status'] = 'idle'
        worker['current_segment'] = None
        worker['current_file'] = None
    print("Worker finished.", flush=True)


# ─── HTTP Handler ────────────────────────────────────────────

class WorkerHandler(BaseHTTPRequestHandler):

    def _cors(self):
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type')

    def _json_response(self, data, status=200):
        body = json.dumps(data).encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self._cors()
        self.end_headers()
        self.wfile.write(body)

    def do_OPTIONS(self):
        self.send_response(204)
        self._cors()
        self.end_headers()

    def do_GET(self):
        if self.path == '/status':
            with worker_lock:
                data = dict(worker)
            data['available_models'] = ['tiny', 'base', 'small', 'medium', 'large']
            data['loaded_model'] = whisper_model_name
            self._json_response(data)
        else:
            self._json_response({'error': 'Not found'}, 404)

    def do_POST(self):
        global worker_thread

        if self.path == '/start':
            content_len = int(self.headers.get('Content-Length', 0))
            body = json.loads(self.rfile.read(content_len)) if content_len > 0 else {}

            session_id = body.get('session_id')
            model = body.get('model', 'large')
            count = int(body.get('count', 0))  # 0 = all pending

            if not session_id:
                self._json_response({'error': 'session_id required'}, 400)
                return

            with worker_lock:
                if worker['status'] not in ('idle',):
                    self._json_response({'error': 'Worker is busy', 'status': worker['status']}, 409)
                    return

            worker_thread = threading.Thread(
                target=worker_loop, args=(int(session_id), model, count), daemon=True
            )
            worker_thread.start()
            label = f'{count} segment{"s" if count != 1 else ""}' if count > 0 else 'all pending'
            self._json_response({'ok': True, 'message': f'Started {label} on session {session_id} ({model})'})

        elif self.path == '/stop':
            with worker_lock:
                if worker['status'] == 'transcribing':
                    worker['status'] = 'stopping'
                    self._json_response({'ok': True, 'message': 'Stopping after current segment'})
                else:
                    self._json_response({'ok': True, 'message': 'Not running'})

        else:
            self._json_response({'error': 'Not found'}, 404)

    def log_message(self, format, *args):
        # Suppress default HTTP logs
        pass


# ─── Main ────────────────────────────────────────────────────

def main():
    import argparse
    parser = argparse.ArgumentParser(description='PC Worker Service')
    parser.add_argument('--port', type=int, default=8891)
    args = parser.parse_args()

    print("=" * 60)
    print("  Card Graph - PC Worker Service")
    print(f"  http://localhost:{args.port}")
    print("=" * 60)
    print("The web UI will auto-detect this service.")
    print("Press Ctrl+C to stop.\n", flush=True)

    server = HTTPServer(('127.0.0.1', args.port), WorkerHandler)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nShutting down...")
        with worker_lock:
            if worker['status'] == 'transcribing':
                worker['status'] = 'stopping'
        server.shutdown()


if __name__ == '__main__':
    main()
