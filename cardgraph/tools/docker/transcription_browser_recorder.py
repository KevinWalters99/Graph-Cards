"""
Card Graph - Browser Automation Recorder

Runs INSIDE the Docker container. Assumes:
- PulseAudio virtual sink is running (entrypoint.sh started it)
- Xvfb is running on DISPLAY=:99 (entrypoint.sh started it)
- /output is mounted to the session audio directory
- /signals is mounted (read-only) to the tools directory

Launches Chromium via Selenium, navigates to the Whatnot URL,
captures audio from PulseAudio virtual sink using ffmpeg in
segmented chunks.

Usage (called by entrypoint.sh):
    python3 transcription_browser_recorder.py --session-id 123 --config '{...}'
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

import pymysql

DB_CONFIG = {
    'host': '192.168.0.215',
    'port': 3307,
    'user': 'cg_app',
    'password': 'ACe!sysD#0kVnBWF',
    'database': 'card_graph',
    'charset': 'utf8mb4',
}

SIGNAL_DIR = '/signals'
OUTPUT_DIR = '/output'

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
    print(f"[{level.upper()}] {message}", flush=True)


def handle_sigterm(signum, frame):
    global running
    running = False


def check_signal(session_id, signal_type):
    """Check host signal files via the mounted /signals directory."""
    path = os.path.join(SIGNAL_DIR, f"transcription_{signal_type}_{session_id}.signal")
    return os.path.exists(path)


def launch_browser(url):
    """Launch Chromium via Selenium, navigate to URL, return driver."""
    from selenium import webdriver
    from selenium.webdriver.chrome.options import Options
    from selenium.webdriver.chrome.service import Service

    options = Options()
    options.binary_location = '/usr/bin/chromium'
    # NOT headless — we need audio output through PulseAudio
    options.add_argument('--no-sandbox')
    options.add_argument('--disable-dev-shm-usage')
    options.add_argument('--disable-gpu')
    options.add_argument('--window-size=1280,720')
    options.add_argument('--autoplay-policy=no-user-gesture-required')
    # Audio goes to PulseAudio virtual sink
    options.add_argument('--use-pulseaudio')
    # Reduce resource usage
    options.add_argument('--disable-extensions')
    options.add_argument('--disable-background-networking')
    options.add_argument('--disable-sync')
    options.add_argument('--disable-translate')
    options.add_argument('--disable-default-apps')

    service = Service('/usr/bin/chromedriver')
    driver = webdriver.Chrome(service=service, options=options)
    driver.set_page_load_timeout(60)
    driver.get(url)

    return driver


def capture_segment(segment_seconds, sample_rate, channels, audio_format, seg_path):
    """Capture one segment of audio from PulseAudio virtual sink."""
    ac = '1' if channels == 'mono' else '2'

    ffmpeg_cmd = [
        'ffmpeg', '-y',
        '-f', 'pulse',
        '-i', 'virtual_sink.monitor',
        '-t', str(segment_seconds),
        '-ar', str(sample_rate),
        '-ac', ac,
        '-vn',
    ]

    if audio_format == 'flac':
        ffmpeg_cmd.extend(['-codec:a', 'flac'])
    else:
        ffmpeg_cmd.extend(['-codec:a', 'pcm_s16le'])

    ffmpeg_cmd.append(seg_path)

    proc = subprocess.Popen(ffmpeg_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return proc


def main():
    global running

    parser = argparse.ArgumentParser(description='Browser Automation Recorder')
    parser.add_argument('--session-id', type=int, required=True)
    parser.add_argument('--config', type=str, required=True)
    args = parser.parse_args()

    signal.signal(signal.SIGTERM, handle_sigterm)

    config = json.loads(args.config)
    session_id = args.session_id

    segment_seconds = int(config['segment_length_minutes']) * 60
    sample_rate = config['sample_rate']
    channels = config['audio_channels']
    audio_format = config['audio_format']
    min_free_gb = int(config['min_free_disk_gb'])

    db = get_db()
    driver = None
    ffmpeg_proc = None

    try:
        # Load session URL
        with db.cursor() as cur:
            cur.execute(
                "SELECT auction_url FROM CG_TranscriptionSessions WHERE session_id = %s",
                (session_id,)
            )
            session = cur.fetchone()

        if not session:
            log_event(db, session_id, 'error', 'browser_recorder_error', 'Session not found')
            return

        stream_url = session['auction_url']
        log_event(db, session_id, 'info', 'browser_started',
                  f"Launching browser for: {stream_url}")

        # Launch browser and navigate to auction URL
        driver = launch_browser(stream_url)
        log_event(db, session_id, 'info', 'browser_navigated', 'Browser navigated to URL')

        # Give the page time to load and start streaming audio
        time.sleep(8)

        date_str = datetime.now().strftime('%Y%m%d')
        segment_number = 0

        while running:
            # Check signals
            if check_signal(session_id, 'stop') or check_signal(session_id, 'cancel'):
                log_event(db, session_id, 'info', 'signal_detected',
                          'Stop/cancel signal detected')
                break

            # Check disk space
            try:
                usage = shutil.disk_usage(OUTPUT_DIR)
                free_gb = usage.free / (1024 ** 3)
                if free_gb < min_free_gb:
                    log_event(db, session_id, 'warning', 'low_disk',
                              f"Low disk space: {free_gb:.1f} GB free")
                    break
            except Exception:
                pass

            # Build segment filename
            segment_number += 1
            seg_filename = f"{date_str}_Session{session_id}_SEG{segment_number:03d}.{audio_format}"
            seg_path = os.path.join(OUTPUT_DIR, seg_filename)

            # Create segment DB record
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

            # Capture audio segment from PulseAudio
            seg_start = time.time()
            try:
                ffmpeg_proc = capture_segment(
                    segment_seconds, sample_rate, channels, audio_format, seg_path
                )

                # Wait for ffmpeg to finish, checking signals every second
                while ffmpeg_proc.poll() is None:
                    if not running or check_signal(session_id, 'stop') or check_signal(session_id, 'cancel'):
                        ffmpeg_proc.terminate()
                        try:
                            ffmpeg_proc.wait(timeout=5)
                        except subprocess.TimeoutExpired:
                            ffmpeg_proc.kill()
                        running = False
                        break
                    time.sleep(1)

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
                        "UPDATE CG_TranscriptionSessions SET total_segments = %s "
                        "WHERE session_id = %s",
                        (segment_number, session_id)
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

        log_event(db, session_id, 'info', 'browser_recorder_stopped',
                  f"Browser recorder finished after {segment_number} segments")

    except Exception as e:
        log_event(db, session_id, 'error', 'browser_recorder_fatal', str(e))
    finally:
        # Clean up browser
        if driver:
            try:
                driver.quit()
            except Exception:
                pass
        # Clean up any lingering ffmpeg
        if ffmpeg_proc and ffmpeg_proc.poll() is None:
            ffmpeg_proc.terminate()
        try:
            db.close()
        except Exception:
            pass


if __name__ == '__main__':
    main()
