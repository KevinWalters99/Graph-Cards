"""
Card Graph - Transcription Recorder (Audio Capture)

Captures audio from a live auction stream using ffmpeg with segmented output.
Monitors for silence and manages segment database entries.

Usage:
    python3 transcription_recorder.py --session-id 123 --session-dir /path --config '{...}'
"""
import argparse
import json
import os
import shutil
import signal
import subprocess
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


def main():
    global running

    parser = argparse.ArgumentParser(description='Transcription Recorder')
    parser.add_argument('--session-id', type=int, required=True)
    parser.add_argument('--session-dir', type=str, required=True)
    parser.add_argument('--config', type=str, required=True)
    args = parser.parse_args()

    signal.signal(signal.SIGTERM, handle_sigterm)

    config = json.loads(args.config)
    session_id = args.session_id
    session_dir = args.session_dir
    audio_dir = os.path.join(session_dir, 'audio')

    segment_seconds = int(config['segment_length_minutes']) * 60
    sample_rate = config['sample_rate']
    channels = '1' if config['audio_channels'] == 'mono' else '2'
    audio_format = config['audio_format']
    silence_timeout = int(config['silence_timeout_minutes']) * 60
    silence_threshold = int(config['silence_threshold_dbfs'])
    min_free_gb = int(config['min_free_disk_gb'])

    db = get_db()

    try:
        # Load session to get auction URL
        with db.cursor() as cur:
            cur.execute("SELECT auction_url FROM CG_TranscriptionSessions WHERE session_id = %s", (session_id,))
            session = cur.fetchone()

        if not session:
            log_event(db, session_id, 'error', 'recorder_error', 'Session not found')
            return

        stream_url = session['auction_url']
        log_event(db, session_id, 'info', 'recorder_started', f"Recording from: {stream_url}")

        # Build safe name prefix
        safe_name = ''.join(c if c.isalnum() or c in '-_' else '' for c in str(session_id)).strip()
        date_str = datetime.now().strftime('%Y%m%d')

        segment_number = 0
        ffmpeg_proc = None
        consecutive_failures = 0
        MAX_CONNECT_RETRIES = 10
        CONNECT_CHECK_SEC = 5  # Wait this long to see if ffmpeg stays alive

        while running:
            # Check disk space
            try:
                usage = shutil.disk_usage(audio_dir)
                free_gb = usage.free / (1024 ** 3)
                if free_gb < min_free_gb:
                    log_event(db, session_id, 'warning', 'low_disk',
                              f"Low disk space: {free_gb:.1f} GB free (min {min_free_gb} GB)")
                    break
            except Exception:
                pass

            # Build filename for this potential segment
            next_seg = segment_number + 1
            seg_filename = f"{date_str}_Session{session_id}_SEG{next_seg:03d}.{audio_format}"
            seg_path = os.path.join(audio_dir, seg_filename)

            # Build ffmpeg command for single segment
            ffmpeg_cmd = [
                'ffmpeg', '-y',
                '-i', stream_url,
                '-t', str(segment_seconds),
                '-ar', str(sample_rate),
                '-ac', channels,
                '-vn',  # No video
            ]

            if audio_format == 'flac':
                ffmpeg_cmd.extend(['-codec:a', 'flac'])
            else:
                ffmpeg_cmd.extend(['-codec:a', 'pcm_s16le'])

            ffmpeg_cmd.append(seg_path)

            # Launch ffmpeg
            seg_start = time.time()
            try:
                ffmpeg_proc = subprocess.Popen(
                    ffmpeg_cmd,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE
                )
            except Exception as e:
                log_event(db, session_id, 'error', 'ffmpeg_launch_error', f"Failed to launch ffmpeg: {e}")
                consecutive_failures += 1
                if consecutive_failures >= MAX_CONNECT_RETRIES:
                    log_event(db, session_id, 'error', 'connect_failed',
                              f"Failed to connect after {MAX_CONNECT_RETRIES} attempts")
                    break
                backoff = min(30, 5 * consecutive_failures)
                time.sleep(backoff)
                continue

            # Wait briefly to see if ffmpeg stays alive (connection check)
            time.sleep(CONNECT_CHECK_SEC)
            if ffmpeg_proc.poll() is not None:
                # ffmpeg exited within seconds — connection failure, NOT a real segment
                consecutive_failures += 1
                # Clean up empty/partial file
                if os.path.exists(seg_path):
                    try:
                        os.remove(seg_path)
                    except Exception:
                        pass

                if consecutive_failures >= MAX_CONNECT_RETRIES:
                    log_event(db, session_id, 'error', 'connect_failed',
                              f"Stream connection failed {MAX_CONNECT_RETRIES} times — giving up")
                    break

                backoff = min(60, 10 * consecutive_failures)
                log_event(db, session_id, 'warning', 'connect_retry',
                          f"Stream connect failed (attempt {consecutive_failures}/{MAX_CONNECT_RETRIES}), "
                          f"retrying in {backoff}s")

                # Wait with backoff, checking for stop signals
                waited = 0
                while waited < backoff and running:
                    time.sleep(1)
                    waited += 1
                continue

            # ffmpeg is still running after CONNECT_CHECK_SEC — stream is connected
            # NOW create the segment record
            consecutive_failures = 0
            segment_number = next_seg

            with db.cursor() as cur:
                cur.execute(
                    "INSERT INTO CG_TranscriptionSegments "
                    "(session_id, segment_number, filename_audio, recording_status, started_at) "
                    "VALUES (%s, %s, %s, 'recording', NOW())",
                    (session_id, segment_number, seg_filename)
                )
                segment_id = cur.lastrowid

            log_event(db, session_id, 'info', 'segment_started',
                      f"Recording segment {segment_number}: {seg_filename}")

            # Wait for ffmpeg to finish this segment (up to segment_seconds)
            try:
                while ffmpeg_proc.poll() is None:
                    if not running:
                        ffmpeg_proc.terminate()
                        ffmpeg_proc.wait(timeout=5)
                        break
                    time.sleep(0.5)

                seg_duration = int(time.time() - seg_start)
                seg_size = os.path.getsize(seg_path) if os.path.exists(seg_path) else 0

                # Update segment record as complete
                with db.cursor() as cur:
                    cur.execute(
                        "UPDATE CG_TranscriptionSegments SET "
                        "recording_status = 'complete', duration_seconds = %s, "
                        "file_size_bytes = %s, completed_at = NOW() "
                        "WHERE segment_id = %s",
                        (seg_duration, seg_size, segment_id)
                    )

                log_event(db, session_id, 'info', 'segment_complete',
                          f"Segment {segment_number} complete: {seg_duration}s, {seg_size} bytes")

                # Update session segment count
                with db.cursor() as cur:
                    cur.execute(
                        "UPDATE CG_TranscriptionSessions SET total_segments = %s WHERE session_id = %s",
                        (segment_number, session_id)
                    )

                # If segment was way shorter than expected, stream may have dropped
                if seg_duration < segment_seconds * 0.5 and ffmpeg_proc.returncode != 0:
                    consecutive_failures += 1
                    if consecutive_failures >= MAX_CONNECT_RETRIES:
                        log_event(db, session_id, 'warning', 'stream_dropped',
                                  'Stream dropped too many times — stopping')
                        break
                    backoff = min(30, 5 * consecutive_failures)
                    log_event(db, session_id, 'warning', 'stream_interrupted',
                              f"Segment ended early ({seg_duration}s vs {segment_seconds}s expected), "
                              f"retrying in {backoff}s")
                    time.sleep(backoff)

            except subprocess.TimeoutExpired:
                if ffmpeg_proc:
                    ffmpeg_proc.kill()
                log_event(db, session_id, 'warning', 'ffmpeg_timeout',
                          f"Segment {segment_number} ffmpeg timeout")
                with db.cursor() as cur:
                    cur.execute(
                        "UPDATE CG_TranscriptionSegments SET recording_status = 'error', "
                        "error_message = 'Timeout' WHERE segment_id = %s",
                        (segment_id,)
                    )

            except Exception as e:
                log_event(db, session_id, 'error', 'segment_error',
                          f"Segment {segment_number} error: {str(e)}")
                with db.cursor() as cur:
                    cur.execute(
                        "UPDATE CG_TranscriptionSegments SET recording_status = 'error', "
                        "error_message = %s WHERE segment_id = %s",
                        (str(e)[:500], segment_id)
                    )

            if not running:
                break

        log_event(db, session_id, 'info', 'recorder_stopped',
                  f"Recorder finished after {segment_number} segments")

    except Exception as e:
        log_event(db, session_id, 'error', 'recorder_fatal', str(e))
    finally:
        try:
            db.close()
        except Exception:
            pass


if __name__ == '__main__':
    main()
