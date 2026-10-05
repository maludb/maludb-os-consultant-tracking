#!/usr/bin/env bash
# tests/phase4/mcp_servers.sh start|stop — the two MCP servers on SCRATCH loopback ports from the scratch environment ($CT_DEV_ENV):
# records :8304, activity :8305 (never the installed application's ports 8831/8832 or its services). A third records instance with a dead kernel URL
# (:8310) proves the run-facts gate fails closed when the kernel cannot be reached. Pid files live in $CT_DEV_STATE.
# The Python is the application's own mcp/venv when root has made it (deploy/ROOT_STEPS.sh); otherwise $CT_MCP_VENV, else a sibling application's venv
# that already holds FastMCP, asyncpg, httpx and uvicorn (Help Desk's, then Projects') — nothing is installed here.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
ENVF=${CT_DEV_ENV:-/tmp/ct-dev.env}
STATE=${CT_DEV_STATE:-/tmp/ct-dev-state}
mkdir -p "$STATE"
VENV=
for c in "${CT_MCP_VENV:-}" "$ROOT/mcp/venv" /srv/apps/helpdesk/mcp/venv /srv/apps/projects/mcp/venv; do
  [ -n "$c" ] && [ -x "$c/bin/python" ] && "$c/bin/python" -c 'import mcp, asyncpg, httpx, uvicorn' 2>/dev/null && { VENV=$c; break; }
done
[ -n "$VENV" ] || { echo "no venv with FastMCP, asyncpg, httpx and uvicorn found" >&2; exit 1; }
PY="$VENV/bin/python"
stop() {
  for f in "$STATE"/mcp-*.pid; do [ -e "$f" ] && kill "$(cat "$f")" 2>/dev/null || true; rm -f "$f"; done
  pkill -f "$ROOT/mcp/(records|activity)_server.py" 2>/dev/null || true       # only ours: the pattern carries this repository's path
  for i in $(seq 1 30); do pgrep -f "$ROOT/mcp/(records|activity)_server.py" >/dev/null || break; sleep 0.2; done
}
case "${1:-}" in
  stop) stop ;;
  venv) echo "$VENV" ;;
  start)
    stop
    set -a; . "$ENVF"; set +a
    launch() {  # name, then VAR=value pairs, then the interpreter and script — exec so $! is the python process itself
      local name=$1; shift
      ( cd "$ROOT/mcp" && exec env "$@" >"$STATE/mcp-$name.log" 2>&1 ) &
      echo $! > "$STATE/mcp-$name.pid"
    }
    launch records MCP_RECORDS_PORT=8304 MCP_ACTIVITY_PORT=8305 "$PY" "$ROOT/mcp/records_server.py"
    launch activity MCP_RECORDS_PORT=8304 MCP_ACTIVITY_PORT=8305 "$PY" "$ROOT/mcp/activity_server.py"
    launch nokernel MCP_RECORDS_PORT=8310 OS_INTERNAL_URL=http://127.0.0.1:1 "$PY" "$ROOT/mcp/records_server.py"
    for p in 8304 8305 8310; do
      for i in $(seq 1 60); do curl -s -o /dev/null -m 1 "http://127.0.0.1:$p/mcp" && break; sleep 0.2; done
    done
    echo "mcp servers up (records :8304, activity :8305, records-with-no-kernel :8310; python $VENV)" ;;
  *) echo "usage: $0 start|stop|venv" >&2; exit 2 ;;
esac
