#!/usr/bin/env bash
# Phase 4 — the two MCP servers, the six shares, app_roles, the gate, the registry: tests/phase4/run.sh
# A fresh SCRATCH database (ct_dev3 — never the installed one), the application on :8301 (php -S), a fake kernel (:8302 — run-facts, K6, K7), a fake MaluDB (:8303) and MaluMail (:8306), and the servers under test on :8304 (records),
# :8305 (activity) and :8310 (records with a DEAD kernel url, for the fail-closed proofs). NOTHING REAL IS TOUCHED: no live service, Apache, unit, database or role password.
# PROOFS="gate tools_people" runs some; CT_KEEP_DB=1 reuses the scratch database and its world (the proofs' own writes are SMOKE rows). Needs `sudo -n -u postgres`, php, and a Python venv with FastMCP (see mcp_servers.sh).
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export CT_DEV_DB=${CT_DEV_DB:-ct_dev3} CT_DEV_ENV=${CT_DEV_ENV:-/tmp/ct-dev3.env} CT_DEV_STATE=${CT_DEV_STATE:-/tmp/ct-dev3-state}
mkdir -p "$CT_DEV_STATE"
if [ -z "${CT_KEEP_DB:-}" ] || [ ! -f "$CT_DEV_ENV" ]; then "$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }; fi
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
"$ROOT/tests/phase4/mcp_servers.sh" start >/dev/null || { echo "mcp servers failed"; exit 1; }
trap '"$ROOT/tests/phase4/mcp_servers.sh" stop; "$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$CT_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$CT_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$CT_DEV_STATE/maludb.log" FAKE_MALUMAIL_LOG="$CT_DEV_STATE/malumail.log" FAKE_MALUMAIL_STATE="$CT_DEV_STATE/malumail.json"
cd "$ROOT"
: > "$FAKE_MALUMAIL_LOG"; : > "$FAKE_KERNEL_STATE.sms"; : > "$FAKE_KERNEL_STATE.reads"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-gate tools_people tools_work tools_money shares tools_activity registry}
for p in $proofs; do
  echo "== $p"
  php "tests/phase4/$p.php" || status=1
done
[ $status -eq 0 ] && echo "ALL PHASE 4 PROOFS PASSED" || echo "SOME PHASE 4 PROOFS FAILED"
exit $status
