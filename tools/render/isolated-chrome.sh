#!/usr/bin/env bash
# Starts Chromium in a fresh network namespace that has only a loopback interface, plus a relay from loopback to the
# proxy's unix socket, so the browser has no route to anything but the filtering proxy. The inner user namespace gives
# Chromium a normal non-root uid, so its own sandbox keeps working.
# Usage: isolated-chrome.sh [chrome args...]; needs RENDER_CHROME, RENDER_PROXY_SOCK and RENDER_RELAY_PORT in the environment.
set -euo pipefail
export RENDER_DIR="$(dirname "$(readlink -f "$0")")" RENDER_UID="$(id -u)" RENDER_GID="$(id -g)"
exec unshare -rn bash "$RENDER_DIR/isolated-inner.sh" "$@"
