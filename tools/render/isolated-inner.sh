#!/usr/bin/env bash
# Second half of isolated-chrome.sh, running as root inside the new namespaces.
set -euo pipefail
ip link set lo up
exec unshare -U --map-user="$RENDER_UID" --map-group="$RENDER_GID" bash "$RENDER_DIR/isolated-exec.sh" "$@"
