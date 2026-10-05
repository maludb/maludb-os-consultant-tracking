#!/usr/bin/env bash
# Builds the SCRATCH database the proofs run against — never the installed application's database.
#   tests/setup_dev.sh            (needs `sudo -n -u postgres`; recreates $CT_DEV_DB, default consultant_tracking_dev)
# 1. drops and creates the scratch database; 2. applies db/*.sql in order as postgres (the same files the installer applies);
# 3. leaves the cluster roles' passwords ALONE when the app is installed (reads them from config/.env); else gives them a scratch
#    password (the kernel's installer sets fresh ones at `apply`); 4. writes the proofs' environment to $CT_DEV_ENV (default
#    /tmp/ct-dev.env, mode 600). Nothing here is committed or installed.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DB=${CT_DEV_DB:-ct_dev}
ENVF=${CT_DEV_ENV:-/tmp/ct-dev.env}
PW=$(openssl rand -hex 16)
PSQL="sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q"
$PSQL -c "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB"
for f in "$ROOT"/db/0*.sql; do $PSQL -d "$DB" -f "$f" >/dev/null; done
LIVEENV="$ROOT/config/.env"
lv() { grep -m1 "^$1=" "$LIVEENV" | cut -d= -f2-; }
if [ -f "$LIVEENV" ] && [ -n "$(lv DB_PASSWORD)" ] && [ -n "$(lv MCP_RECORDS_DB_PASSWORD)" ] && [ -n "$(lv MCP_ACTIVITY_DB_PASSWORD)" ]; then
  PW_RW=$(lv DB_PASSWORD); PW_REC=$(lv MCP_RECORDS_DB_PASSWORD); PW_ACT=$(lv MCP_ACTIVITY_DB_PASSWORD)
else
  PW_RW=$PW; PW_REC=$PW; PW_ACT=$PW
  for r in consultant_tracking_rw consultant_tracking_records_ro consultant_tracking_activity_ro; do $PSQL -c "ALTER ROLE $r WITH LOGIN PASSWORD '$PW'"; done
fi
umask 077
cat > "$ENVF" <<ENV
APP_ENV=dev
APP_DEBUG=1
APP_NAME="Consultant Tracking"
APP_KEY=consultant_tracking
APP_URL=http://127.0.0.1:8301
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=$DB
DB_USER=consultant_tracking_rw
DB_PASSWORD=$PW_RW
MCP_RECORDS_DB_USER=consultant_tracking_records_ro
MCP_RECORDS_DB_PASSWORD=$PW_REC
MCP_ACTIVITY_DB_USER=consultant_tracking_activity_ro
MCP_ACTIVITY_DB_PASSWORD=$PW_ACT
APP_INTERNAL_PORT=8301
MCP_RECORDS_PORT=8304
MCP_ACTIVITY_PORT=8305
ACTION_TOKEN_KEY=$(openssl rand -hex 32)
ACTIONS_RELAY_KEY=$(openssl rand -hex 32)
OS_LAUNCHER_URL=https://app.example.invalid/
OS_INTERNAL_URL=http://127.0.0.1:8302
OS_APPLICATION_TOKEN=osapp_dev_$(openssl rand -hex 12)
MALUDB_API_URL=http://127.0.0.1:8303
MALUDB_API_TOKEN=dev-maludb-$(openssl rand -hex 8)
MALUMAIL_API_URL=http://127.0.0.1:8306
MALUMAIL_API_KEY=dev-malumail-$(openssl rand -hex 8)
MAIL_FROM=consulting@example.invalid
MAIL_FROM_NAME="Consultant Tracking"
ATTACHMENT_MAX_BYTES=5000000
CT_LINK_LIFETIME_DAYS=180
ENV
echo "scratch database $DB ready; environment in $ENVF"
