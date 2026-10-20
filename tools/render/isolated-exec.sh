#!/usr/bin/env bash
# Last step: relay in the background, then replace this shell with Chromium (the relay exits when it does).
node "$RENDER_DIR/relay.mjs" "$RENDER_RELAY_PORT" "$RENDER_PROXY_SOCK" $$ &
exec "$RENDER_CHROME" "$@"
