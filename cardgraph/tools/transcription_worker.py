"""
Card Graph - Transcription Worker (Whisper)

Polls for completed audio segments and transcribes them using OpenAI Whisper.
Exits when no more pending segments remain and the session is no longer recording.

Usage:
    python3 transcription_worker.py --session-id 123 --session-dir /path --model base
"""
import argparse
import os
import signal
import sys
import time
from datetime import datetime

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import pymysql

DB_CONFIG = {
    'host': '192.168.0.215',
    'port': 3307,
    'user': 'cg_app',
    'password': 'ACe!sysD#0kVnBWF',
    'database': 'card_graph',
    'charset': 'utf8mb4',
}

running = True
whisper_model = None


def get_db():
    return pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor, autocommit=True)


def log_event(db, session_id, level, event_type, message):
    with db.cursor() as cur:
        cur.execute(
            "INSERT INTO CG_TranscriptionLogs (session_id, log_level, event_type, message) "
            "VALUES (%s, %s, %s, %s)",
            (session_id, level, event_type, message)
        )
    print(f"[{level.upper()}] {message}")


def handle_sigterm(signum, frame):
    global running
    running = False


WHISPER_CACHE = '/volume1/web/cardgraph/tools/whisper_models'


def load_whisper_model(model_name):
    """Load the Whisper model once at startup."""
    global whisper_model
    os.makedirs(WHISPER_CACHE, exist_ok=True)
    try:
        import whisper
        print(f"Loading Whisper model: {model_name} (cache: {WHISPER_CACHE})")
        whisper_model = whisper.load_model(model_name, download_root=WHISPER_CACHE)
        print(f"Whisper model loaded successfully")
        return True
    except ImportError:
        print("ERROR: whisper module not installed")
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

    with open(output_path, 'w', encoding='utf-8') as f:
        f.write(text)
        f.write('\n')

    return text


def main():
    global running

    parser = argparse.ArgumentParser(description='Transcription Worker')
    parser.add_argument('--session-id', type=int, required=True)
    parser.add_argument('--session-dir', type=str, required=True)
    parser.add_argument('--model', type=str, default='base', choices=['tiny', 'base', 'small', 'medium', 'large'])
    args = parser.parse_args()

    signal.signal(signal.SIGTERM, handle_sigterm)

    session_id = args.session_id
    session_dir = args.session_dir
    audio_dir = os.path.join(session_dir, 'audio')
    tx_dir = os.path.join(session_dir, 'transcripts')

    # Ensure transcripts directory exists and is writable
    os.makedirs(tx_dir, exist_ok=True)
    if not os.access(tx_dir, os.W_OK):
        # Docker may have created the dir as root; try to fix via chmod
        try:
            os.chmod(tx_dir, 0o777)
        except OSError:
            pass
    if not os.access(tx_dir, os.W_OK):
        # Fallback: use a writable location under tools/
        tx_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'transcripts_fallback', f'session_{session_id}')
        os.makedirs(tx_dir, exist_ok=True)
        print(f"[WARNING] Original transcripts dir not writable, using fallback: {tx_dir}")

    db = get_db()

    # Load Whisper model (first run downloads ~140MB for 'base')
    if not load_whisper_model(args.model):
        log_event(db, session_id, 'warning', 'whisper_load_error',
                  f'Whisper model "{args.model}" failed to load — transcription skipped')
        db.close()
        return

    log_event(db, session_id, 'info', 'worker_started', f"Transcription worker started (model: {args.model})")

    idle_count = 0
    MAX_IDLE = 60  # Exit after 60 consecutive idle polls (5 min at 5s interval)

    try:
        while running:
            # Find pending segments with completed recordings
            with db.cursor() as cur:
                cur.execute(
                    "SELECT * FROM CG_TranscriptionSegments "
                    "WHERE session_id = %s AND recording_status = 'complete' "
                    "AND transcription_status = 'pending' "
                    "ORDER BY segment_number ASC LIMIT 1",
                    (session_id,)
                )
                segment = cur.fetchone()

            if not segment:
                # Check if session is still recording
                with db.cursor() as cur:
                    cur.execute(
                        "SELECT status FROM CG_TranscriptionSessions WHERE session_id = %s",
                        (session_id,)
                    )
                    sess = cur.fetchone()

                if sess and sess['status'] not in ('recording', 'processing'):
                    # Session is done and no more pending segments
                    log_event(db, session_id, 'info', 'worker_done',
                              'No more pending segments, session not active')
                    break

                idle_count += 1
                if idle_count >= MAX_IDLE and sess and sess['status'] == 'processing':
                    log_event(db, session_id, 'info', 'worker_timeout',
                              'Worker idle timeout — no new segments')
                    break

                time.sleep(5)
                continue

            idle_count = 0
            seg_id = segment['segment_id']
            seg_num = segment['segment_number']
            audio_file = segment['filename_audio']

            if not audio_file:
                # Skip segments without audio files
                with db.cursor() as cur:
                    cur.execute(
                        "UPDATE CG_TranscriptionSegments SET transcription_status = 'skipped' "
                        "WHERE segment_id = %s", (seg_id,)
                    )
                continue

            audio_path = os.path.join(audio_dir, audio_file)
            if not os.path.exists(audio_path):
                log_event(db, session_id, 'warning', 'audio_missing',
                          f"Audio file not found: {audio_file}")
                with db.cursor() as cur:
                    cur.execute(
                        "UPDATE CG_TranscriptionSegments SET transcription_status = 'skipped', "
                        "error_message = 'Audio file not found' WHERE segment_id = %s",
                        (seg_id,)
                    )
                continue

            # Mark as transcribing
            with db.cursor() as cur:
                cur.execute(
                    "UPDATE CG_TranscriptionSegments SET transcription_status = 'transcribing' "
                    "WHERE segment_id = %s",
                    (seg_id,)
                )

            log_event(db, session_id, 'info', 'transcribing',
                      f"Transcribing segment {seg_num}: {audio_file}")

            # Build transcript filename
            tx_filename = os.path.splitext(audio_file)[0] + '.txt'
            tx_path = os.path.join(tx_dir, tx_filename)

            try:
                text = transcribe_segment(audio_path, tx_path)

                # Mark complete
                with db.cursor() as cur:
                    cur.execute(
                        "UPDATE CG_TranscriptionSegments SET "
                        "transcription_status = 'complete', transcription_progress = 100, "
                        "filename_transcript = %s WHERE segment_id = %s",
                        (tx_filename, seg_id)
                    )

                word_count = len(text.split()) if text else 0
                log_event(db, session_id, 'info', 'transcription_complete',
                          f"Segment {seg_num} transcribed: {word_count} words")

            except Exception as e:
                log_event(db, session_id, 'error', 'transcription_error',
                          f"Segment {seg_num} failed: {str(e)}")
                with db.cursor() as cur:
                    cur.execute(
                        "UPDATE CG_TranscriptionSegments SET transcription_status = 'error', "
                        "error_message = %s WHERE segment_id = %s",
                        (str(e)[:500], seg_id)
                    )

            if not running:
                break

        log_event(db, session_id, 'info', 'worker_stopped', 'Transcription worker finished')

    except Exception as e:
        log_event(db, session_id, 'error', 'worker_fatal', str(e))
    finally:
        try:
            db.close()
        except Exception:
            pass


if __name__ == '__main__':
    main()
