"""
Card Graph - PC Transcription Worker

Runs on your local PC to transcribe pending audio segments stored on the NAS.
Connects to MariaDB for coordination, reads/writes files via UNC share.

Usage:
    python pc_transcription_worker.py                        # all pending sessions
    python pc_transcription_worker.py --session-id 12        # specific session
    python pc_transcription_worker.py --model large          # use large model (default: large)
"""
import argparse
import glob
import os
import sys
import time
from datetime import datetime

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

# NAS path mapping: Linux -> Windows UNC
NAS_LINUX_PREFIX = '/volume1/web/cardgraph/'
NAS_UNC_PREFIX = r'\\192.168.0.215\web\cardgraph' + '\\'

whisper_model = None


def get_db():
    return pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor, autocommit=True)


def nas_to_unc(linux_path):
    """Convert NAS Linux path to Windows UNC path."""
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
    print(f"  [{ts}] [{level.upper()}] {message}")


def load_whisper_model(model_name):
    """Load the Whisper model once at startup."""
    global whisper_model
    try:
        import whisper
        print(f"Loading Whisper model: {model_name}")
        whisper_model = whisper.load_model(model_name)
        print(f"Whisper model loaded successfully")
        return True
    except ImportError:
        print("ERROR: whisper module not installed. Run: pip install openai-whisper")
        return False
    except Exception as e:
        print(f"ERROR loading Whisper model: {e}")
        return False


def transcribe_segment(audio_path, output_path):
    """Transcribe a single audio file using Whisper."""
    global whisper_model
    if whisper_model is None:
        raise RuntimeError("Whisper model not loaded")

    result = whisper_model.transcribe(audio_path, language='en', fp16=False)
    text = result.get('text', '').strip()

    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(text)
        f.write('\n')

    return text


def claim_segment(db, session_id=None):
    """Atomically claim one pending segment. Returns segment dict or None."""
    with db.cursor() as cur:
        # Find a candidate
        if session_id:
            cur.execute(
                "SELECT segment_id FROM CG_TranscriptionSegments "
                "WHERE session_id = %s AND recording_status = 'complete' "
                "AND transcription_status = 'pending' "
                "ORDER BY segment_number ASC LIMIT 1",
                (session_id,)
            )
        else:
            cur.execute(
                "SELECT segment_id FROM CG_TranscriptionSegments "
                "WHERE recording_status = 'complete' "
                "AND transcription_status = 'pending' "
                "ORDER BY session_id ASC, segment_number ASC LIMIT 1"
            )
        row = cur.fetchone()
        if not row:
            return None

        # Atomic claim — only succeeds if still pending
        cur.execute(
            "UPDATE CG_TranscriptionSegments "
            "SET transcription_status = 'transcribing' "
            "WHERE segment_id = %s AND transcription_status = 'pending'",
            (row['segment_id'],)
        )
        if cur.rowcount == 0:
            return None  # Someone else claimed it

        # Fetch full details
        cur.execute(
            "SELECT * FROM CG_TranscriptionSegments WHERE segment_id = %s",
            (row['segment_id'],)
        )
        return cur.fetchone()


def get_session_dir(db, session_id):
    """Get the session directory path from DB."""
    with db.cursor() as cur:
        cur.execute(
            "SELECT session_dir FROM CG_TranscriptionSessions WHERE session_id = %s",
            (session_id,)
        )
        row = cur.fetchone()
        return row['session_dir'] if row else None


def main():
    parser = argparse.ArgumentParser(description='PC Transcription Worker')
    parser.add_argument('--session-id', type=int, default=None,
                        help='Process specific session (default: all pending)')
    parser.add_argument('--model', type=str, default='large',
                        choices=['tiny', 'base', 'small', 'medium', 'large'])
    args = parser.parse_args()

    print("=" * 60)
    print("Card Graph - PC Transcription Worker")
    print("=" * 60)

    # Load Whisper model
    if not load_whisper_model(args.model):
        sys.exit(1)

    db = get_db()

    # Show what's pending
    with db.cursor() as cur:
        if args.session_id:
            cur.execute(
                "SELECT COUNT(*) as cnt FROM CG_TranscriptionSegments "
                "WHERE session_id = %s AND recording_status = 'complete' "
                "AND transcription_status = 'pending'",
                (args.session_id,)
            )
        else:
            cur.execute(
                "SELECT COUNT(*) as cnt FROM CG_TranscriptionSegments "
                "WHERE recording_status = 'complete' AND transcription_status = 'pending'"
            )
        pending = cur.fetchone()['cnt']

    scope = f"session {args.session_id}" if args.session_id else "all sessions"
    print(f"\nPending segments ({scope}): {pending}")
    if pending == 0:
        print("Nothing to transcribe.")
        db.close()
        return

    print(f"Starting transcription...\n")

    completed = 0
    errors = 0

    # Cache session dirs
    session_dirs = {}

    while True:
        segment = claim_segment(db, args.session_id)
        if not segment:
            break

        seg_id = segment['segment_id']
        sess_id = segment['session_id']
        seg_num = segment['segment_number']
        audio_file = segment['filename_audio']

        if not audio_file:
            with db.cursor() as cur:
                cur.execute(
                    "UPDATE CG_TranscriptionSegments SET transcription_status = 'skipped' "
                    "WHERE segment_id = %s", (seg_id,)
                )
            continue

        # Get session directory (cached)
        if sess_id not in session_dirs:
            session_dirs[sess_id] = get_session_dir(db, sess_id)

        session_dir_linux = session_dirs[sess_id]
        if not session_dir_linux:
            log_event(db, sess_id, 'error', 'pc_worker_error',
                      f"Session dir not found for session {sess_id}")
            errors += 1
            continue

        session_dir = nas_to_unc(session_dir_linux)
        audio_path = os.path.join(session_dir, 'audio', audio_file)
        print(f"  Path: {audio_path}  (exists: {os.path.exists(audio_path)})")
        tx_dir = os.path.join(session_dir, 'transcripts')
        tx_filename = os.path.splitext(audio_file)[0] + '.txt'
        tx_path = os.path.join(tx_dir, tx_filename)

        if not os.path.exists(audio_path):
            log_event(db, sess_id, 'warning', 'audio_missing',
                      f"Audio file not found: {audio_path}")
            with db.cursor() as cur:
                cur.execute(
                    "UPDATE CG_TranscriptionSegments SET transcription_status = 'skipped', "
                    "error_message = 'Audio file not found (PC worker)' WHERE segment_id = %s",
                    (seg_id,)
                )
            continue

        print(f"[{completed + 1}/{pending}] Session {sess_id} / SEG {seg_num:03d}: {audio_file}")
        log_event(db, sess_id, 'info', 'pc_transcribing',
                  f"PC worker transcribing segment {seg_num}: {audio_file} (model: {args.model})")

        start_time = time.time()
        try:
            text = transcribe_segment(audio_path, tx_path)
            elapsed = time.time() - start_time
            word_count = len(text.split()) if text else 0

            # Mark complete
            with db.cursor() as cur:
                cur.execute(
                    "UPDATE CG_TranscriptionSegments SET "
                    "transcription_status = 'complete', transcription_progress = 100, "
                    "filename_transcript = %s WHERE segment_id = %s",
                    (tx_filename, seg_id)
                )

            log_event(db, sess_id, 'info', 'pc_transcription_complete',
                      f"Segment {seg_num} done: {word_count} words in {elapsed:.1f}s")
            print(f"  -> {word_count} words, {elapsed:.1f}s")
            completed += 1

        except Exception as e:
            elapsed = time.time() - start_time
            log_event(db, sess_id, 'error', 'pc_transcription_error',
                      f"Segment {seg_num} failed after {elapsed:.1f}s: {str(e)}")
            with db.cursor() as cur:
                cur.execute(
                    "UPDATE CG_TranscriptionSegments SET transcription_status = 'error', "
                    "error_message = %s WHERE segment_id = %s",
                    (str(e)[:500], seg_id)
                )
            print(f"  -> ERROR: {e}")
            errors += 1

    print(f"\n{'=' * 60}")
    print(f"Done! Completed: {completed}, Errors: {errors}")
    print(f"{'=' * 60}")
    db.close()


if __name__ == '__main__':
    main()
