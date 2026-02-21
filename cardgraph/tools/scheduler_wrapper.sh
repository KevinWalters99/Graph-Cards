#!/bin/bash
# Card Graph — Scheduler Wrapper
# Called by DSM Task Scheduler every 1 minute (as root).
# 1) Triggers the transcription scheduler API endpoint
# 2) Picks up session start requests (browser_automation needs root for Docker)
# 3) Checks for Docker build requests

TOOLS_DIR="/volume1/web/cardgraph/tools"
LOG="$TOOLS_DIR/scheduler.log"

echo "=== $(date '+%Y-%m-%d %H:%M:%S') ===" >> "$LOG"

# --- Scheduler tick (finds due sessions, queues browser_automation starts) ---
curl -s -d 'key=cg_sched_2026' http://192.168.0.215:8880/api/transcription/scheduler-tick >> "$LOG" 2>&1
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

# --- Retention cleanup (self-throttled to once/hour inside the endpoint) ---
curl -s -d 'key=cg_sched_2026' http://192.168.0.215:8880/api/transcription/cleanup >> "$LOG" 2>&1
echo "" >> "$LOG"

# --- Docker build (if requested) ---
BUILD_REQ="$TOOLS_DIR/docker_build_request"
BUILD_LOCK="$TOOLS_DIR/docker_build.lock"
BUILD_LOG="$TOOLS_DIR/docker_build.log"
DOCKER_DIR="$TOOLS_DIR/docker"

if [ -f "$BUILD_REQ" ] && [ ! -f "$BUILD_LOCK" ]; then
    rm -f "$BUILD_REQ"
    echo "Docker build starting at $(date '+%Y-%m-%d %H:%M:%S')" >> "$LOG"
    touch "$BUILD_LOCK"
    docker build -t cg-browser-recorder:latest "$DOCKER_DIR" > "$BUILD_LOG" 2>&1
    BUILD_EXIT=$?
    echo "EXIT_CODE=$BUILD_EXIT" >> "$BUILD_LOG"
    rm -f "$BUILD_LOCK"
    if [ $BUILD_EXIT -eq 0 ]; then
        echo "Docker build SUCCESS" >> "$LOG"
    else
        echo "Docker build FAILED (exit $BUILD_EXIT)" >> "$LOG"
    fi
fi
