#!/bin/bash
set -e

# 1. Start D-Bus (PulseAudio may need it)
mkdir -p /run/dbus
dbus-daemon --system --fork 2>/dev/null || true

# 2. Start PulseAudio in background (not daemon mode — more reliable in Docker)
# Use --daemonize=false and run in background so we control the process
pulseaudio \
    --daemonize=false \
    --exit-idle-time=-1 \
    --log-level=notice \
    --disallow-exit \
    --no-cpu-limit \
    --system=false \
    --fail \
    2>&1 | sed 's/^/[pulseaudio] /' &
PA_PID=$!
sleep 2

# Wait for PulseAudio to be ready (up to 10 seconds)
PA_READY=0
for i in $(seq 1 20); do
    if pactl info > /dev/null 2>&1; then
        PA_READY=1
        echo "[entrypoint] PulseAudio ready (attempt $i)"
        break
    fi
    sleep 0.5
done

if [ "$PA_READY" -eq 0 ]; then
    echo "[entrypoint] WARNING: PulseAudio not responding via pactl, checking process..."
    if ! kill -0 $PA_PID 2>/dev/null; then
        echo "[entrypoint] ERROR: PulseAudio process died"
        exit 1
    fi
    echo "[entrypoint] PulseAudio process alive, attempting to load module manually..."
fi

# Ensure virtual sink exists (it should be loaded from default.pa)
if ! pactl list short sinks 2>/dev/null | grep -q virtual_sink; then
    echo "[entrypoint] Loading virtual_sink module..."
    pactl load-module module-null-sink sink_name=virtual_sink sink_properties=device.description="VirtualSink" 2>&1 || true
    pactl set-default-sink virtual_sink 2>&1 || true
    sleep 1
fi

# Final check
if pactl list short sinks 2>/dev/null | grep -q virtual_sink; then
    echo "[entrypoint] virtual_sink confirmed"
else
    echo "[entrypoint] WARNING: virtual_sink not confirmed, proceeding anyway (ffmpeg may fail)"
fi

# 3. Start Xvfb virtual display
export DISPLAY=:99
Xvfb :99 -screen 0 1280x720x24 -ac &
XVFB_PID=$!
sleep 1

# Verify Xvfb is running
if ! kill -0 $XVFB_PID 2>/dev/null; then
    echo "[entrypoint] ERROR: Xvfb failed to start"
    exit 1
fi
echo "[entrypoint] Xvfb running on :99"

# 4. Launch the Python browser recorder with all passed arguments
exec python3 /app/transcription_browser_recorder.py "$@"
