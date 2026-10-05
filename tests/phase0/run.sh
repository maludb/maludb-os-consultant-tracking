#!/usr/bin/env bash
# Phase 0 proof, repeatable: the schema (db/proof/phase0_proof.sql, the schema's checks) and the kit without a kernel
# (testing-without-a-kernel.md §4) — the directory fixture applied, a hand-off minted the kernel's way and presented,
# its replay / another audience / an unknown member refused, health answering. Scratch database only; the installed
# application (if any) is never touched. Prints ok/FAIL per check and a count; exits non-zero on any failure.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export CT_DEV_DB=${CT_DEV_DB:-ct_dev0}
export CT_DEV_ENV=${CT_DEV_ENV:-/tmp/ct-dev0.env}
PORT=8307
pass=0; fail=0
ok()   { echo "ok   $1"; pass=$((pass+1)); }
bad()  { echo "FAIL $1"; fail=$((fail+1)); }
check(){ if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (got: $1, wanted: $2)"; fi; }

bash "$ROOT/tests/setup_dev.sh" >/dev/null || { echo "scratch database failed"; exit 1; }
set -a; . "$CT_DEV_ENV"; set +a
export APP_URL="http://127.0.0.1:$PORT"

echo "== the schema (db/proof/phase0_proof.sql)"
out=$(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$CT_DEV_DB" -f "$ROOT/db/proof/phase0_proof.sql" 2>&1)
echo "$out" | grep -E '^ FAIL' || true
summary=$(echo "$out" | grep -E '[0-9]+ ok, [0-9]+ failed' | head -1 | sed 's/^ *//')
failed=$(echo "$summary" | sed -E 's/.* ([0-9]+) failed.*/\1/')
check "$failed" "0" "schema proof: $summary"

echo "== the kit without a kernel"
cd "$ROOT"
php bin/directory_sync.php --from-file bin/dev_directory.json >/dev/null 2>&1; check "$?" "0" "the directory fixture applies"
members=$(sudo -n -u postgres psql -d "$CT_DEV_DB" -Atc "select count(*) from members where capability is not null")
check "$members" "7" "seven members admitted from the fixture (six people and the Clerk)"
roles=$(sudo -n -u postgres psql -d "$CT_DEV_DB" -Atc "select roles::text from members where id = 27")
check "$roles" "{manager,user}" "Marco holds manager and user from access[]"
php -S 127.0.0.1:$PORT -t html tests/dev_router.php >/tmp/ct-phase0-server.log 2>&1 &
SRV=$!; sleep 1
health=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/api/v1/health"); check "$health" "200" "health answers 200"
url=$(php bin/dev_handoff.php 27)
first=$(curl -s -o /dev/null -w '%{http_code}' -c /tmp/ct-phase0-jar "$url"); check "$first" "302" "a hand-off opens a session (302 to /)"
again=$(curl -s -o /dev/null -w '%{http_code}' "$url"); check "$again" "403" "the same token a second time is refused (replay)"
bad_url=$(APP_KEY=other php bin/dev_handoff.php 27 | sed "s#http://127.0.0.1:$PORT##")
aud=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT$bad_url"); check "$aud" "403" "a token for another application is refused (audience)"
unk=$(curl -s -o /dev/null -w '%{http_code}' "$(php bin/dev_handoff.php 999)"); check "$unk" "403" "an unknown member is refused"
pyld=$(echo "$url" | sed -E 's/.*token=([^.]+\.[^.]+\.[^.]+\.[^.]+)\.[a-f0-9]+.*/\1/')
tamper=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/sso?token=$pyld.$(printf '0%.0s' $(seq 1 64))&claims=x.y"); check "$tamper" "403" "a tampered signature is refused"
reasons=$(sudo -n -u postgres psql -d "$CT_DEV_DB" -Atc "select string_agg(after->>'reason', ',' order by id) from activity_log where action = 'member.sign_on.refused'")
check "$reasons" "replay,token,capability,token" "every refusal is logged with its reason, none shown to the visitor"
sess=$(sudo -n -u postgres psql -d "$CT_DEV_DB" -Atc "select count(*) from member_sessions where member_id = 27 and ended_at is null"); check "$sess" "1" "the session is listed for the kernel's sign-out notice"
# the kernel's sign-out notice ends it
notice="27.$(date +%s).consultant_tracking"; sig=$(printf 'sso-logout:%s' "$notice" | openssl dgst -sha256 -hmac "$ACTION_TOKEN_KEY" | sed 's/.* //')
lo=$(curl -s -o /dev/null -w '%{http_code}' -X POST --data-urlencode "notice=$notice.$sig" "http://127.0.0.1:$PORT/sso/logout"); check "$lo" "204" "the sign-out notice answers 204"
ended=$(sudo -n -u postgres psql -d "$CT_DEV_DB" -Atc "select ended_by from member_sessions where member_id = 27"); check "$ended" "kernel" "…and ends the member's session (ended_by kernel)"
badlo=$(curl -s -o /dev/null -w '%{http_code}' -X POST --data-urlencode "notice=27.1.consultant_tracking.deadbeef" "http://127.0.0.1:$PORT/sso/logout"); check "$badlo" "204" "a bad notice also answers 204 and changes nothing"
kill $SRV 2>/dev/null; wait $SRV 2>/dev/null
echo "== $pass ok, $fail failed"
[ "$fail" -eq 0 ]
