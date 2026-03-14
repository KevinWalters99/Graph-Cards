#!/bin/bash
# Card Graph — Scheduler Wrapper
# Called by DSM Task Scheduler every 1 minute (as root).
# 1) Triggers the transcription scheduler API endpoint
# 2) Picks up session start requests (browser_automation needs root for Docker)
# 3) Checks for Docker build requests

TOOLS_DIR="/volume1/web/cardgraph/tools"
LOG="$TOOLS_DIR/scheduler.log"
DOCKER_BIN="$(command -v docker 2>/dev/null || true)"

# --- Load credentials from .env ---
ENV_FILE=""
for CANDIDATE in "/volume1/web/cardgraph/.env" "/volume1/web/.env" "$TOOLS_DIR/../.env"; do
    if [ -f "$CANDIDATE" ]; then
        ENV_FILE="$CANDIDATE"
        break
    fi
done
if [ -n "$ENV_FILE" ]; then
    CG_SCHEDULER_KEY=$(grep '^CG_SCHEDULER_KEY=' "$ENV_FILE" | head -1 | cut -d'=' -f2-)
    CG_NAS_IP=$(grep '^CG_NAS_IP=' "$ENV_FILE" | head -1 | cut -d'=' -f2-)
    CG_NAS_PORT=$(grep '^CG_NAS_PORT=' "$ENV_FILE" | head -1 | cut -d'=' -f2-)
fi
# Fallbacks if .env missing or incomplete
CG_SCHEDULER_KEY="${CG_SCHEDULER_KEY:-cg_sched_2026}"
CG_NAS_IP="${CG_NAS_IP:-192.168.0.215}"
CG_NAS_PORT="${CG_NAS_PORT:-8880}"

if [ -z "$DOCKER_BIN" ]; then
    for CANDIDATE in /usr/local/bin/docker /usr/bin/docker; do
        if [ -x "$CANDIDATE" ]; then
            DOCKER_BIN="$CANDIDATE"
            break
        fi
    done
fi

echo "=== $(date '+%Y-%m-%d %H:%M:%S') ===" >> "$LOG"

# --- Stale lock cleanup (detect orphaned processes after NAS restart) ---
for LOCK_FILE in "$TOOLS_DIR"/transcription_session_*.lock; do
    [ -f "$LOCK_FILE" ] || continue

    LOCK_BASENAME=$(basename "$LOCK_FILE")
    LOCK_SID=$(echo "$LOCK_BASENAME" | sed 's/transcription_session_//;s/\.lock//')

    # Read PID from lock file (line 1); if empty/missing, treat as stale
    LOCK_PID=$(head -1 "$LOCK_FILE" 2>/dev/null | tr -d '[:space:]')

    if [ -z "$LOCK_PID" ]; then
        # Old-style lock file (just touched, no PID) — check file age instead
        # If older than 10 minutes with no PID, assume stale
        LOCK_AGE=$(( $(date +%s) - $(stat -c %Y "$LOCK_FILE" 2>/dev/null || echo 0) ))
        if [ "$LOCK_AGE" -gt 600 ]; then
            echo "Session $LOCK_SID: removing stale lock (no PID, age=${LOCK_AGE}s)" >> "$LOG"
            rm -f "$LOCK_FILE"
        fi
    elif ! kill -0 "$LOCK_PID" 2>/dev/null; then
        # PID is not running — process died (NAS restart, crash, etc.)
        echo "Session $LOCK_SID: removing stale lock (PID $LOCK_PID not running)" >> "$LOG"
        rm -f "$LOCK_FILE"
    fi
done

# --- Clean up orphaned Docker containers from previous runs ---
if [ -n "$DOCKER_BIN" ]; then
    for CONTAINER in $("$DOCKER_BIN" ps -a --filter "name=cg_tx_recorder_" --format '{{.Names}}' 2>/dev/null); do
        # Check if the container is actually running or just stopped
        STATE=$("$DOCKER_BIN" inspect -f '{{.State.Status}}' "$CONTAINER" 2>/dev/null)
        if [ "$STATE" = "exited" ] || [ "$STATE" = "dead" ]; then
            echo "Removing orphaned Docker container: $CONTAINER (state=$STATE)" >> "$LOG"
            "$DOCKER_BIN" rm -f "$CONTAINER" >> "$LOG" 2>&1
        fi
    done
fi

# --- Scheduler tick (finds due sessions, queues browser_automation starts) ---
curl -s -d "key=$CG_SCHEDULER_KEY" "http://${CG_NAS_IP}:${CG_NAS_PORT}/api/transcription/scheduler-tick" >> "$LOG" 2>&1
echo "" >> "$LOG"

# --- Launch queued sessions (browser_automation needs root for Docker) ---
PYTHON_BIN="python3"
which python3 > /dev/null 2>&1 || PYTHON_BIN="python"
MANAGER="$TOOLS_DIR/transcription_manager.py"

for REQ_FILE in "$TOOLS_DIR"/start_session_*.request; do
    [ -f "$REQ_FILE" ] || continue

    # Extract session ID from filename: start_session_123.request
    BASENAME=$(basename "$REQ_FILE")
    SID=$(echo "$BASENAME" | sed 's/start_session_//;s/\.request//')

    LOCK_FILE="$TOOLS_DIR/transcription_session_${SID}.lock"
    OUT_FILE="$TOOLS_DIR/transcription_session_${SID}.out"

    # Skip if already running
    if [ -f "$LOCK_FILE" ]; then
        echo "Session $SID: skipped (already running)" >> "$LOG"
        rm -f "$REQ_FILE"
        continue
    fi

    rm -f "$REQ_FILE"

    if [ ! -f "$MANAGER" ]; then
        echo "Session $SID: ERROR - manager script not found" >> "$LOG"
        continue
    fi

    # Launch manager as root (has Docker access)
    echo "Session $SID: launching manager" >> "$LOG"
    nohup sh -c "touch '$LOCK_FILE' && $PYTHON_BIN '$MANAGER' --session-id $SID > '$OUT_FILE' 2>&1; rm -f '$LOCK_FILE'" > /dev/null 2>&1 &

    echo "Session $SID: started (PID $!)" >> "$LOG"
done

# --- Fix archive directory permissions (Docker creates as root, http user needs write) ---
ARCHIVE_DIR="/volume1/web/cardgraph/archive"
if [ -d "$ARCHIVE_DIR" ]; then
    find "$ARCHIVE_DIR" -type d ! -perm -o+w -exec chmod o+w {} + 2>/dev/null
fi

# --- Retention cleanup (self-throttled to once/hour inside the endpoint) ---
curl -s -d "key=$CG_SCHEDULER_KEY" "http://${CG_NAS_IP}:${CG_NAS_PORT}/api/transcription/cleanup" >> "$LOG" 2>&1
echo "" >> "$LOG"

# --- Whisper install (if requested) ---
WHISPER_REQ="$TOOLS_DIR/whisper_install_request"
WHISPER_LOCK="$TOOLS_DIR/whisper_install.lock"
WHISPER_SCRIPT="$TOOLS_DIR/install_whisper.sh"

if [ -f "$WHISPER_REQ" ] && [ ! -f "$WHISPER_LOCK" ]; then
    rm -f "$WHISPER_REQ"
    echo "Whisper install starting at $(date '+%Y-%m-%d %H:%M:%S')" >> "$LOG"
    touch "$WHISPER_LOCK"
    bash "$WHISPER_SCRIPT"
    rm -f "$WHISPER_LOCK"
    echo "Whisper install finished" >> "$LOG"
fi

# --- Docker build (if requested) ---
BUILD_REQ="$TOOLS_DIR/docker_build_request"
BUILD_LOCK="$TOOLS_DIR/docker_build.lock"
BUILD_LOG="$TOOLS_DIR/docker_build.log"
DOCKER_DIR="$TOOLS_DIR/docker"

if [ -f "$BUILD_REQ" ] && [ ! -f "$BUILD_LOCK" ]; then
    rm -f "$BUILD_REQ"
    echo "Docker build starting at $(date '+%Y-%m-%d %H:%M:%S')" >> "$LOG"
    touch "$BUILD_LOCK"
    if [ -z "$DOCKER_BIN" ]; then
        echo "ERROR: docker binary not found in PATH, /usr/local/bin/docker, or /usr/bin/docker" > "$BUILD_LOG"
        BUILD_EXIT=127
    else
        "$DOCKER_BIN" build -t cg-browser-recorder:latest "$DOCKER_DIR" > "$BUILD_LOG" 2>&1
        BUILD_EXIT=$?
    fi
    echo "EXIT_CODE=$BUILD_EXIT" >> "$BUILD_LOG"
    rm -f "$BUILD_LOCK"
    if [ $BUILD_EXIT -eq 0 ]; then
        echo "Docker build SUCCESS" >> "$LOG"
    else
        echo "Docker build FAILED (exit $BUILD_EXIT)" >> "$LOG"
    fi
fi
