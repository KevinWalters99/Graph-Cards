#!/bin/bash
# Build the browser recorder Docker image on the Synology NAS.
# Run from the tools/docker/ directory or pass the path.
#
# Usage: bash build.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

echo "Building cg-browser-recorder Docker image..."
docker build -t cg-browser-recorder:latest .

echo ""
echo "Build complete. Image: cg-browser-recorder:latest"
echo ""
docker images cg-browser-recorder
