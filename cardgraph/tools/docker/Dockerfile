FROM python:3.11-slim-bookworm

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    pulseaudio \
    xvfb \
    chromium \
    chromium-driver \
    ffmpeg \
    dbus \
    && rm -rf /var/lib/apt/lists/*

# Python dependencies
RUN pip install --no-cache-dir \
    selenium==4.18.1 \
    PyMySQL==1.1.0

# PulseAudio config for virtual sink (no real hardware)
RUN mkdir -p /root/.config/pulse
COPY pulse-default.pa /root/.config/pulse/default.pa
COPY pulse-client.conf /etc/pulse/client.conf

# Copy scripts
COPY transcription_browser_recorder.py /app/transcription_browser_recorder.py
COPY entrypoint.sh /app/entrypoint.sh
RUN chmod +x /app/entrypoint.sh

WORKDIR /app

ENTRYPOINT ["/app/entrypoint.sh"]
