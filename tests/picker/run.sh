#!/usr/bin/env bash
# The record picker's proof: tests/picker/run.sh
# A fresh SCRATCH database (ct_dev_picker — never the installed one), the application (php -S with tests/dev_router.php) and a fake kernel
# for the first directory sync. Ports default to the proofs' (:8301 / :8302); CT_APP_PORT and CT_KERNEL_PORT move them (another session's proofs
# may be running). Only the servers this script started are stopped. Needs `sudo -n -u postgres`, php, and for the browser pass the kernel's
# web/node_modules (Playwright + Chromium). Screenshots go to $SHOTS (default /tmp/ct-shots-picker). Exit 0 only if every check passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export CT_DEV_DB=${CT_DEV_DB:-ct_dev_picker} CT_DEV_ENV=${CT_DEV_ENV:-/tmp/ct-dev-picker.env} CT_DEV_STATE=${CT_DEV_STATE:-/tmp/ct-dev-picker-state} SHOTS=${SHOTS:-/tmp/ct-shots-picker}
APP_PORT=${CT_APP_PORT:-8301}; KERNEL_PORT=${CT_KERNEL_PORT:-8302}
mkdir -p "$SHOTS" "$CT_DEV_STATE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
sed -i -e "s#^APP_URL=.*#APP_URL=http://127.0.0.1:$APP_PORT#" -e "s#^APP_INTERNAL_PORT=.*#APP_INTERNAL_PORT=$APP_PORT#" -e "s#^OS_INTERNAL_URL=.*#OS_INTERNAL_URL=http://127.0.0.1:$KERNEL_PORT#" "$CT_DEV_ENV"
set -a; . "$CT_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$CT_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$CT_DEV_STATE/maludb.log" CT_BASE="http://127.0.0.1:$APP_PORT" BASE="http://127.0.0.1:$APP_PORT"
: > "$FAKE_MALUDB_LOG"
php -r '$f=json_decode(file_get_contents($argv[1]),true); file_put_contents($argv[2], json_encode(["feed"=>$f["feed"]]));' "$ROOT/bin/dev_directory.json" "$FAKE_KERNEL_STATE"
cd "$ROOT"
php -S "127.0.0.1:$KERNEL_PORT" tests/fake_kernel.php >"$CT_DEV_STATE/kernel.log" 2>&1 & KPID=$!
PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$APP_PORT" -t html tests/dev_router.php >"$CT_DEV_STATE/app.log" 2>&1 & APID=$!
trap 'pkill -P $APID 2>/dev/null; kill $KPID $APID 2>/dev/null; wait 2>/dev/null' EXIT
for i in $(seq 1 30); do curl -s -o /dev/null "$CT_BASE/api/v1/health" && break; sleep 0.2; done
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
echo "== picker"; php tests/picker/picker.php || status=1
echo "== browser"; node tests/picker/browser.mjs || status=1
[ $status -eq 0 ] && echo "ALL PICKER PROOFS PASSED" || echo "SOME PICKER PROOFS FAILED"
exit $status
