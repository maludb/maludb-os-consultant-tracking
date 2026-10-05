#!/usr/bin/env bash
# Consultant Tracking — what root or the owner does to install it beside the kernel, in order. Written at the end of Phase 0
# (2026-10-05); later phases ADD steps and mark them. NOTHING HERE HAS BEEN RUN: the proofs use a scratch database
# (tests/setup_dev.sh), php -S and fixtures, and never touch an installed application, Apache or systemd.
#   bash deploy/ROOT_STEPS.sh          prints this plan (the default)
#   sudo bash deploy/ROOT_STEPS.sh apply   runs the steps with no decision in them (0)
set -euo pipefail
APP=/srv/apps/consultant_tracking
KERNEL=/var/www
MODE=${1:-plan}
run() { echo "+ $*"; if [ "$MODE" = apply ]; then "$@"; fi; }

# ------------------------------------------------------------------------------------------------------------------
# 0. PIN THE PORTS before the installer's first apply (the owner's D15; Help Desk's lesson of 2026-10-02). A config/.env
#    holding ONLY the three ports: the installer reads them, fills every other required key itself, and sets fresh role
#    passwords because DB_PASSWORD is absent. Never put anything else in this file by hand.
echo "== 0. Pin the ports (ROOT) — only if $APP/config/.env does not exist yet"
if [ ! -f "$APP/config/.env" ]; then
  run bash -c "printf 'APP_INTERNAL_PORT=8185\nMCP_RECORDS_PORT=8831\nMCP_ACTIVITY_PORT=8832\n' > '$APP/config/.env' && chgrp www-data '$APP/config/.env' && chmod 640 '$APP/config/.env'"
else
  echo "   (config/.env exists — leaving it alone)"
fi
#    See that it worked:  php $KERNEL/bin/app_install.php plan $APP --domain subello.com | grep -E '^(todo|done) +(ports|vhost)'   -> ports done, no {{…}} unfilled

# ------------------------------------------------------------------------------------------------------------------
# 1. Look (read-only, any user): what the installer would do.
echo "== 1. The installer's plan (read-only)"
echo "   php $KERNEL/bin/app_install.php plan $APP --by <super-admin email> --domain subello.com"

# ------------------------------------------------------------------------------------------------------------------
# 2. The installer's apply (ROOT) — PHASE 2 onward, not before the shell exists: database and roles, config/.env, the vhost,
#    the units, the registration (catalog kind ours — the row K17 seeded, db/168 —, the application with /sso and /sso/logout,
#    scope_kind none), the application token into config/.env, skills imported, approval policies, the installing
#    super-admin's admin grant. --grant-standing-departments gives every standing department the Consultant role (key user) at
#    write (D3: everyone logs time). --hire-agents hires the expert and the Billing Clerk (D11). Run it twice: the roles are read
#    from app_roles only once the records MCP answers (Phase 4, built: `mcp/records_server.py` answers `app_roles` to the kernel's token). The venv step (python3 -m venv
#    mcp/venv, pip install -r mcp/requirements.txt) is the installer's; the two servers need nothing else of root. The shared tools' internal door
#    (POST /api/v1/shares/read.php on APP_INTERNAL_PORT, signed with ACTIONS_RELAY_KEY — both written by apply) is loopback only: the vhost answers 404 for /api.
#    The registry step composes mcp/registries/consultant_tracking.json in the kernel from deploy/kernel-registry-consultant_tracking.json (the resolve block,
#    kept by hand: tests/phase4/registry.php resolves every entity through the live server) and mcp/action_registry.json (bin/build_action_registry.php).
echo "== 2. apply (ROOT) — from Phase 2"
echo "   sudo php $KERNEL/bin/app_install.php apply $APP --by <super-admin email> --domain subello.com --grant-standing-departments --hire-agents"

# ------------------------------------------------------------------------------------------------------------------
# 3. The owner's keys and decisions (any time after 2):
echo "== 3. The owner's"
echo "   - MaluMail: MALUMAIL_API_KEY, MAIL_FROM, MAIL_FROM_NAME into $APP/config/.env (slice 6 sends invoices and reminders)"
echo "   - the K6 text sender, if texts are wanted: php $KERNEL/bin/notify_endpoint_set.php (already set when another application uses it)"
echo "   - DNS and TLS for consulting.subello.com (until then /etc/hosts maps it to this host)"
echo "   - the first rate card, the cost rates, the first client and engagement (Phase 5); the billing person who receives the morning note (settings)"
echo "   - the Billing Clerk's duty (0 7 * * *) in Agent HR until the installer reads agents[].duty"
echo "   - the connections a super-admin approves (bin/app_connection.php): the ledger reading invoices_issued …, HR reading reimbursements_approved;"
echo "     G1 (the ledger's invoice_receipts share), H2 (HR pays reimbursements), P1 (Projects' list) in their own repositories"

# ------------------------------------------------------------------------------------------------------------------
# 4. The worker (slice 8, 2026-10-05) — ROOT. consultant_tracking-worker.service/.timer (deploy/) run `php bin/worker.php` every minute
#    as www-data (the outbox: email through MaluMail, texts through the kernel's K6; Monday's nudges; cadence drafts; the AI statement poll;
#    schedules; budgets; reminders; the K7 reads of gl.invoice_receipts and hr.reimbursements_paid; claims; link expiry; dispatch retries).
#    The installer's apply renders and enables the units it finds in deploy/; if it does not, by hand:
echo "== 4. The worker timer (ROOT)"
echo "   sudo systemctl enable --now consultant_tracking-worker.timer      # after apply rendered {{APP_DIR}} in the units"
echo "   php $APP/bin/worker.php --steps=send     # one pass of one step, as www-data, to see it work (needs MALUMAIL_API_KEY for mail)"
echo "   The K7 reads answer 403 no_connection (logged once a day, nothing changed) until a super-admin approves the connections: bin/app_connection.php"
