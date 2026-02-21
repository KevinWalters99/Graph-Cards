"""
Card Graph - Transcription Manager (Orchestrator)

Launches and monitors the recorder and transcription worker subprocesses.
Polls for stop/cancel signal files and manages session lifecycle.

Usage:
    python3 transcription_manager.py --session-id 123
"""
import argparse
import json
import os
import signal
import subprocess
import sys
import time
from datetime import datetime

# Ensure this script's directory is on the path (for bundled pymysql)
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

TOOLS_DIR = os.path.dirname(os.path.abspath(__file__))


def get_db():
    return pymysql.connect(**DB_CONFIG, cursorclass=pymysql.cursors.DictCursor, autocommit=True)


def log_event(db, session_id, level, event_type, message):
    """Insert a log entry for the session."""
    with db.cursor() as cur:
        cur.execute(
            "INSERT INTO CG_TranscriptionLogs (session_id, log_level, event_type, message) "
            "VALUES (%s, %s, %s, %s)",
            (session_id, level, event_type, message)
        )
    print(f"[{level.upper()}] [{event_type}] {message}")


def update_session(db, session_id, **kwargs):
    """Update session fields."""
    sets = []
    vals = []
    for k, v in kwargs.items():
        sets.append(f"{k} = %s")
        vals.append(v)
    vals.append(session_id)
    with db.cursor() as cur:
        cur.execute(
            f"UPDATE CG_TranscriptionSessions SET {', '.join(sets)} WHERE session_id = %s",
            vals
        )


def load_config(db, session_id):
    """Load session + global settings, merge overrides."""
    with db.cursor() as cur:
        cur.execute("SELECT * FROM CG_TranscriptionSessions WHERE session_id = %s", (session_id,))
        session = cur.fetchone()
        if not session:
            raise RuntimeError(f"Session {session_id} not found")

        cur.execute("SELECT * FROM CG_TranscriptionSettings WHERE setting_id = 1")
        settings = cur.fetchone()
        if not settings:
            raise RuntimeError("Global settings not found")

    # Merge overrides
    config = {
        'segment_length_minutes':  session['override_segment_length'] or settings['segment_length_minutes'],
        'silence_timeout_minutes': session['override_silence_timeout'] or settings['silence_timeout_minutes'],
        'max_session_hours':       session['override_max_duration'] or settings['max_session_hours'],
        'max_cpu_cores':           session['override_cpu_limit'] or settings['max_cpu_cores'],
        'acquisition_mode':        session['override_acquisition_mode'] or settings['acquisition_mode'],
        'sample_rate':             settings['sample_rate'],
        'audio_channels':          settings['audio_channels'],
        'audio_format':            settings['audio_format'],
        'silence_threshold_dbfs':  settings['silence_threshold_dbfs'],
        'whisper_model':           settings['whisper_model'],
        'priority_mode':           settings['priority_mode'],
        'base_archive_dir':        settings['base_archive_dir'],
        'folder_structure':        settings['folder_structure'],
        'min_free_disk_gb':        settings['min_free_disk_gb'],
    }
    return session, config


def create_session_dir(session, config):
    """Create the archive directory structure for this session."""
    base = config['base_archive_dir'].rstrip('/')
    safe_name = ''.join(c if c.isalnum() or c in '-_ ' else '' for c in session['auction_name']).strip().replace(' ', '_')
    date_str = datetime.now().strftime('%Y%m%d')

    sid = session['session_id']
    if config['folder_structure'] == 'year-based':
        year = datetime.now().strftime('%Y')
        session_dir = f"{base}/{year}/{date_str}_S{sid}_{safe_name}"
    else:
        session_dir = f"{base}/{date_str}_S{sid}_{safe_name}"

    os.makedirs(f"{session_dir}/audio", exist_ok=True)
    os.makedirs(f"{session_dir}/transcripts", exist_ok=True)

    # Write session.json metadata
    metadata = {
        'session_id': session['session_id'],
        'auction_name': session['auction_name'],
        'auction_url': session['auction_url'],
        'scheduled_start': str(session['scheduled_start']),
        'config': {k: str(v) for k, v in config.items()},
        'created_at': datetime.now().isoformat(),
    }
    with open(f"{session_dir}/session.json", 'w') as f:
        json.dump(metadata, f, indent=2)

    return session_dir


def check_signal(session_id, signal_type):
    """Check if a signal file exists."""
    path = os.path.join(TOOLS_DIR, f"transcription_{signal_type}_{session_id}.signal")
    return os.path.exists(path)


def clean_signals(session_id):
    """Remove signal files."""
    for sig in ('stop', 'cancel'):
        path = os.path.join(TOOLS_DIR, f"transcription_{sig}_{session_id}.signal")
        if os.path.exists(path):
            os.remove(path)


def clean_lock(session_id):
    """Remove lock file."""
    path = os.path.join(TOOLS_DIR, f"transcription_session_{session_id}.lock")
    if os.path.exists(path):
        os.remove(path)


def find_python():
    """Find the python3/python binary."""
    for cmd in ['python3', 'python']:
        try:
            result = subprocess.run([cmd, '--version'], capture_output=True, text=True, timeout=5)
            if result.returncode == 0:
                return cmd
        except Exception:
            continue
    return 'python3'


def launch_docker_recorder(session_id, session_dir, config):
    """Launch the browser automation recorder in a Docker container."""
    container_name = f"cg_tx_recorder_{session_id}"
    audio_dir = os.path.join(session_dir, 'audio')
    config_json = json.dumps(config)

    docker_cmd = [
        'docker', 'run',
        '--rm',
        '--name', container_name,
        '--network', 'host',
        '--shm-size=512m',
        '-v', f'{audio_dir}:/output',
        '-v', f'{TOOLS_DIR}:/signals:ro',
        'cg-browser-recorder:latest',
        '--session-id', str(session_id),
        '--config', config_json,
    ]

    proc = subprocess.Popen(docker_cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    return proc


def stop_docker_container(session_id):
    """Stop the Docker container for this session (if running)."""
    container_name = f"cg_tx_recorder_{session_id}"
    try:
        subprocess.run(['docker', 'stop', '-t', '10', container_name],
                       capture_output=True, timeout=20)
    except Exception:
        try:
            subprocess.run(['docker', 'kill', container_name],
                           capture_output=True, timeout=10)
        except Exception:
            pass


def main():
    parser = argparse.ArgumentParser(description='Transcription Manager')
    parser.add_argument('--session-id', type=int, required=True)
    args = parser.parse_args()
    session_id = args.session_id

    db = get_db()
    recorder_proc = None
    worker_proc = None

    try:
        session, config = load_config(db, session_id)
        log_event(db, session_id, 'info', 'manager_started', f"Manager started for session {session_id}")

        # Create session directory
        session_dir = create_session_dir(session, config)
        update_session(db, session_id, session_dir=session_dir)
        log_event(db, session_id, 'info', 'dir_created', f"Session directory: {session_dir}")

        python_bin = find_python()
        config_json = json.dumps(config)

        # Determine CPU affinity
        total_cores = config['max_cpu_cores']
        rec_cores = '0' if total_cores == 1 else '0,1'
        tx_cores = '0' if total_cores == 1 else str(total_cores - 1)

        acquisition_mode = config['acquisition_mode']

        # Launch recorder (Docker for browser_automation, direct subprocess otherwise)
        if acquisition_mode == 'browser_automation':
            log_event(db, session_id, 'info', 'recorder_launching',
                      'Launching browser automation recorder (Docker)')
            recorder_proc = launch_docker_recorder(session_id, session_dir, config)
        else:
            rec_script = os.path.join(TOOLS_DIR, 'transcription_recorder.py')
            rec_cmd = [python_bin, rec_script,
                       '--session-id', str(session_id),
                       '--session-dir', session_dir,
                       '--config', config_json]

            # Try taskset for CPU isolation, fall back to direct execution
            try:
                subprocess.run(['which', 'taskset'], capture_output=True, check=True)
                rec_cmd = ['taskset', '-c', rec_cores] + rec_cmd
            except Exception:
                pass

            log_event(db, session_id, 'info', 'recorder_launching', 'Launching audio recorder')
            recorder_proc = subprocess.Popen(rec_cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)

        # Launch transcription worker
        tx_script = os.path.join(TOOLS_DIR, 'transcription_worker.py')
        tx_cmd = [python_bin, tx_script,
                  '--session-id', str(session_id),
                  '--session-dir', session_dir,
                  '--model', str(config['whisper_model'])]

        # Nice + taskset for transcription worker
        try:
            subprocess.run(['which', 'nice'], capture_output=True, check=True)
            tx_cmd = ['nice', '-n', '10'] + tx_cmd
        except Exception:
            pass

        try:
            subprocess.run(['which', 'taskset'], capture_output=True, check=True)
            tx_cmd = ['taskset', '-c', tx_cores] + tx_cmd
        except Exception:
            pass

        log_event(db, session_id, 'info', 'worker_launching', 'Launching transcription worker')
        worker_proc = subprocess.Popen(tx_cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)

        # ── Monitor loop ──
        max_seconds = int(config['max_session_hours']) * 3600
        start_time = time.time()

        while True:
            time.sleep(1)
            elapsed = time.time() - start_time

            # Check cancel signal
            if check_signal(session_id, 'cancel'):
                log_event(db, session_id, 'warning', 'cancel_received', 'Cancel signal received')
                if acquisition_mode == 'browser_automation':
                    stop_docker_container(session_id)
                if recorder_proc and recorder_proc.poll() is None:
                    recorder_proc.terminate()
                if worker_proc and worker_proc.poll() is None:
                    worker_proc.terminate()
                update_session(db, session_id, status='stopped', stop_reason='user_cancel',
                               end_time=datetime.now().strftime('%Y-%m-%d %H:%M:%S'))
                log_event(db, session_id, 'info', 'session_cancelled', 'Session cancelled by user')
                break

            # Check stop signal
            if check_signal(session_id, 'stop'):
                log_event(db, session_id, 'info', 'stop_received', 'Stop signal received — stopping recorder')
                if acquisition_mode == 'browser_automation':
                    stop_docker_container(session_id)
                if recorder_proc and recorder_proc.poll() is None:
                    recorder_proc.terminate()
                # Let transcription worker continue — it exits when no more pending segments
                update_session(db, session_id, status='processing')
                log_event(db, session_id, 'info', 'processing', 'Recording stopped, transcription continues')
                # Wait for worker to finish
                if worker_proc:
                    worker_proc.wait()
                update_session(db, session_id, status='complete',
                               end_time=datetime.now().strftime('%Y-%m-%d %H:%M:%S'))
                log_event(db, session_id, 'info', 'session_complete', 'Session completed after stop')
                break

            # Check max duration
            if elapsed >= max_seconds:
                log_event(db, session_id, 'warning', 'max_duration', f"Max duration reached ({config['max_session_hours']}h)")
                if acquisition_mode == 'browser_automation':
                    stop_docker_container(session_id)
                if recorder_proc and recorder_proc.poll() is None:
                    recorder_proc.terminate()
                update_session(db, session_id, status='processing')
                if worker_proc:
                    worker_proc.wait()
                update_session(db, session_id, status='complete', stop_reason='max_duration',
                               end_time=datetime.now().strftime('%Y-%m-%d %H:%M:%S'))
                log_event(db, session_id, 'info', 'session_complete', 'Session completed (max duration)')
                break

            # Check if recorder exited naturally
            if recorder_proc and recorder_proc.poll() is not None:
                exit_code = recorder_proc.returncode
                # Capture recorder output for diagnostics
                rec_output = ''
                if recorder_proc.stdout:
                    try:
                        rec_output = recorder_proc.stdout.read().decode('utf-8', errors='replace')[-2000:]
                    except Exception:
                        pass
                if exit_code == 0:
                    log_event(db, session_id, 'info', 'recorder_exited', 'Recorder finished normally')
                else:
                    msg = f"Recorder exited with code {exit_code}"
                    if rec_output:
                        msg += f"\n{rec_output}"
                    log_event(db, session_id, 'warning', 'recorder_exited', msg)
                update_session(db, session_id, status='processing')
                # Wait for worker to finish
                if worker_proc:
                    worker_proc.wait()
                update_session(db, session_id, status='complete',
                               end_time=datetime.now().strftime('%Y-%m-%d %H:%M:%S'))
                log_event(db, session_id, 'info', 'session_complete', 'Session completed')
                break

            # Periodic segment count update (every 30s)
            if int(elapsed) % 30 == 0 and int(elapsed) > 0:
                try:
                    with db.cursor() as cur:
                        cur.execute(
                            "SELECT COUNT(*) AS cnt, COALESCE(SUM(duration_seconds), 0) AS dur "
                            "FROM CG_TranscriptionSegments WHERE session_id = %s",
                            (session_id,)
                        )
                        row = cur.fetchone()
                        update_session(db, session_id,
                                       total_segments=row['cnt'],
                                       total_duration_sec=row['dur'])
                except Exception:
                    pass

        # Generate master transcript
        try:
            generate_master_transcript(session_dir, session)
            log_event(db, session_id, 'info', 'master_transcript', 'Master transcript generated')
        except Exception as e:
            log_event(db, session_id, 'warning', 'master_transcript_error', str(e))

        # Final segment count update
        try:
            with db.cursor() as cur:
                cur.execute(
                    "SELECT COUNT(*) AS cnt, COALESCE(SUM(duration_seconds), 0) AS dur "
                    "FROM CG_TranscriptionSegments WHERE session_id = %s",
                    (session_id,)
                )
                row = cur.fetchone()
                update_session(db, session_id,
                               total_segments=row['cnt'],
                               total_duration_sec=row['dur'])
        except Exception:
            pass

    except Exception as e:
        try:
            log_event(db, session_id, 'error', 'manager_error', str(e))
            update_session(db, session_id, status='error', stop_reason=str(e)[:100],
                           end_time=datetime.now().strftime('%Y-%m-%d %H:%M:%S'))
        except Exception:
            print(f"FATAL: {e}")

        # Kill subprocesses on error
        if acquisition_mode == 'browser_automation':
            stop_docker_container(session_id)
        for proc in [recorder_proc, worker_proc]:
            if proc and proc.poll() is None:
                proc.terminate()

    finally:
        clean_signals(session_id)
        clean_lock(session_id)
        try:
            db.close()
        except Exception:
            pass

    print(f"Manager finished for session {session_id}")


def generate_master_transcript(session_dir, session):
    """Concatenate all segment transcripts into one master file."""
    tx_dir = os.path.join(session_dir, 'transcripts')
    if not os.path.exists(tx_dir):
        return

    safe_name = ''.join(c if c.isalnum() or c in '-_ ' else '' for c in session['auction_name']).strip().replace(' ', '_')
    date_str = datetime.now().strftime('%Y%m%d')
    master_file = os.path.join(tx_dir, f"{date_str}_{safe_name}_FULL.txt")

    segments = sorted([f for f in os.listdir(tx_dir) if f.endswith('.txt') and '_FULL' not in f])

    with open(master_file, 'w', encoding='utf-8') as out:
        out.write(f"# Transcription: {session['auction_name']}\n")
        out.write(f"# Date: {date_str}\n")
        out.write(f"# Session ID: {session['session_id']}\n\n")

        for seg_file in segments:
            filepath = os.path.join(tx_dir, seg_file)
            out.write(f"\n--- {seg_file} ---\n\n")
            with open(filepath, 'r', encoding='utf-8') as inp:
                out.write(inp.read())
            out.write('\n')


if __name__ == '__main__':
    main()
