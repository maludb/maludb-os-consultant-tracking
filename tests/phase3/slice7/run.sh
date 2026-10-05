#!/usr/bin/env bash
# Phase 3, slice 7 — reports and exports: tests/phase3/slice7/run.sh
# A fresh SCRATCH database (ct_dev3 — never the installed one), the application on :8301 (php -S, or CT_APP=apache for a real Apache with
# the rendered deploy vhost), a fake kernel (:8302) and a fake MaluDB (:8303). Needs `sudo -n -u postgres`, php, the kernel's web/node_modules
# (Playwright + Chromium) for the browser proof. Screenshots go to $SHOTS (default /tmp/ct-shots-p3s7). One line per check; exit 0 only if all passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export CT_DEV_DB=${CT_DEV_DB:-ct_dev3} CT_DEV_ENV=${CT_DEV_ENV:-/tmp/ct-dev3.env} CT_DEV_STATE=${CT_DEV_STATE:-/tmp/ct-dev3-state} SHOTS=${SHOTS:-/tmp/ct-shots-p3s7}
mkdir -p "$SHOTS" "$CT_DEV_STATE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$CT_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$CT_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$CT_DEV_STATE/maludb.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-utilization realization wip revenue margin budget ai expenses missing exports visibility json units}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"; php "tests/phase3/slice7/$p.php" || status=1
done
if [ -z "${PROOFS:-}" ] || [[ " $PROOFS " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice7/browser.mjs || status=1; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 7 PROOFS PASSED" || echo "SOME SLICE 7 PROOFS FAILED"
exit $status
