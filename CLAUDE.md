# Consultant Tracking — CLAUDE.md

This repository is the **Consultant Tracking** application of the MaluDB Business OS (`github.com/maludb/maludb-os-consultant-tracking`,
private; local clone and install path `/srv/apps/consultant_tracking`; catalog key `consultant_tracking`; DNS label `consulting`).
The kernel is `/var/www` (`maludb-os-core`); read its CLAUDE.md first — the kernel owns identity, the directory, the agents and
approvals; this application owns the commercial record of consulting work (clients, engagements, time, expenses with receipts,
AI usage and hosting pass-through, invoices) and **is not the books**: the accounting system reads it through the kernel (K7).

## Read first
1. `docs/consultant-tracking-design.md` — the plan (Phase 0): what it is and is not (§1), actors (§2), roles and rights (§3),
   clients without accounts and the secure invoice (§4), what pauses for agents and the two shipped agents (§5), the memory
   model (§6 — the tables), the question inventory with every tool named (§7), the accounting interface — shares, reads,
   exports (§8), the screens (§9), the build order (§10), what the kernel and siblings owe (§12), **the owner's decisions
   (§13, all sixteen answered 2026-10-04 and recorded as D1–D16 in §15 — rules, not questions)**, the state (§16).
2. The plugin `maludb-os-integration` (`~/maludb-os-integration`, skill `os-integration` and its references:
   `memory.md`, `mcp-and-api.md`, `sign-on-and-directory.md`, `agents.md`, `roles-and-rights.md`, `sms-and-reads.md`,
   `registration.md`, `php-sign-on-kit.md`, `testing-without-a-kernel.md`) — how it fits.
3. `/srv/apps/helpdesk` — the nearest **built** exemplar: its kit (`app/*.php`), `maludb-os.json`, `docs/build-specs/tickets-core.md`
   (the shape of a slice spec), `tests/phase0|phase2|phase3|phase4` (the shape of a proof suite), `deploy/` (templates and
   `ROOT_STEPS.sh`). `/srv/apps/gl/docs/gl-design.md` is the sibling plan written the same day in the same shape.
4. The kernel's retired specs — prior art: `docs/build-specs/time.md`, `expenses.md`, `sales-invoicing.md`, `documents.md`,
   `projects-tasks.md` in `/var/www` (the design says by name what it keeps and what it overturns).

## Rules that bind here
- `htmx-php-builder` skills govern the PHP (`php-patterns` before any PHP, `design-system` before any markup, `php-session-auth`
  for session hardening and CSRF, `mcp-servers` for the two read servers, `chat-actions` for the command bar, `new-screen` for
  every screen); `os-integration` governs the fit. Where the two conflict, the integration contract wins: **no password, no
  login form, no account, no model key, no Twilio key, no shared tables, no actions server of our own, no writes to the directory,
  no writes to another application** (K7 is reads only — the ledger reads our shares; we never post to it).
- **Vocabulary: "agent" means an OS AI agent**, always. The person doing the work is a *consultant*; the owner of an engagement
  its *manager*; the issuer of invoices *billing*; the external accountant *finance*; a *client* is a party, never a member.
- **The checkpoint gate**: no feature PHP until the owner approves the schema + tool surface + action manifest + slice specs
  together (Phase 1). Schema changes after that are numbered additive migrations, each with sign-off. **Never modify a
  migration; add one.**
- **The database is the referee**; PHP presents its refusals as field errors and never re-implements them: a billed item is
  billed once (`invoice_line_items` unique per item); approved and invoiced entries are locked; no self-approval; a receipt
  is required to submit at or above the threshold; one currency per document, fixed once a line exists; `INV-`/`CN-` numbers
  are gapless on issue (`document_sequences`, a table); a void releases its items; a kernel AI month allocates to the cent.
- **Agents draft; a person approves, issues, sends and forgives** (design §5): issuing, voiding, writing off, crediting and
  recording payment pause under `money_out`; sending and nudging under `external_send`; approvals of time, expenses, usage and
  milestones are refused to agents by the default policy. A person doing the same is never paused.
- **Cost rates reveal pay**: `member_cost_rates` is read only under `cost.view`; a manager's `cost.view` reaches the margin of
  their own engagements, never the rate table; a cost rate never appears in a log payload or on the secure link.
- **Clients have no account** (design §4): the invoice's secure link `/i/<token>` (hashed, rotatable, expiring) is their door;
  it shows the invoice, its backing detail (receipts only when the engagement shares them), payments, the balance and the
  "I paid this" claim — never a note, a cost, a rate card name or another document. Public paths are named in the vhost's
  allow-list, CSRF- or signature-checked, rate-limited, logged with `source = 'portal'`.
- Every state-changing handler: `require_post()` + `verify_csrf()` (an action token stands in) + the right (`require_right()`,
  SQL `ct_has_right()`) + `ct_can_see_engagement()` for the record + `log_activity()` + `emit_action_status()`; a create's
  `location` ends in the record id. Money events carry `amount` and `currency` in `after`.
- The kernel's rules copied here: activity `source` is `web` for the UI, `agent` under a run token, `cron` for the worker,
  `portal` for a public door; `action` is `entity.verb`; `mcp_*` views are the only thing the read roles see, with caller
  checks as uncorrelated sets tested once per statement (the kernel's db/160 lesson); `app.member_id` is set before any
  query; an unknown member id is refused, never created. **A record's history is the activity log** — no audit table.
- Reports are **SQL functions** (`ct_unbilled`, `ct_utilization`, `ct_realization`, `ct_budget_status`, `ct_margin`,
  `ct_ar_aging`, `ct_revenue`, `ct_ai_passthrough`, `ct_missing_time`) so a screen, a tool and a CSV never disagree.
- Email is MaluMail (the application's own optional `MALUMAIL_API_KEY`); texts only through the kernel (K6), every refusal
  falling back to email. No model is ever called from PHP: "draft", "summarise", "read this receipt" are the expert's, through
  the kernel's chat endpoint.
- **Every port env (`APP_INTERNAL_PORT`, `MCP_RECORDS_PORT`, `MCP_ACTIVITY_PORT`) is in `maludb-os.json` `env.required`** and
  pinned in `config/.env` before the installer's `apply` (8185 / 8831 / 8832 — design §13.15). **Never put a `config/.env`
  here before `apply`** except that three-line port pin (`deploy/ROOT_STEPS.sh` step 0); proofs read `$CT_DEV_ENV`.
- Phone first: a consultant logs time and snaps receipts at 375 px; the week grid is a day list on a phone; `capture="environment"`
  on the expense form; no modals; full-page create/edit; cards for named things, tables for timesheets, lines and ledgers;
  every name a link, every page a way back.
- Commit after every finished step on `main`, in the sibling repos' message style (`Consultant Tracking: …`); never push
  unless the owner asks. Smokes and fixtures are named `SMOKE <run>`; proofs run on a scratch database and never touch the
  installed application; the installer's `plan` (`php /var/www/bin/app_install.php plan /srv/apps/consultant_tracking`) runs
  at the end of every phase.

## Build order and the handoff (decided 2026-10-04, division revised 2026-10-05 — design D16)

The same division of labour General Ledger uses: a **planning-class model** builds what the database enforces, the specs and
the exemplar; a **worker model (Sonnet 5.5)** replicates every other slice from a spec, stopping and escalating on any ambiguity
rather than improvising. The handoff is a clean checkpoint with everything a worker needs in this repository — never mid-slice.

**Before the handoff (planning-class model):**
1. **K17** in the kernel (`/var/www`): a migration seeding the `consultant_tracking` catalog row (Sales & Service / `other` /
   `feather-briefcase` / `high`), on the pattern of db/167 (`gl`).
2. **Phase 0, second half**: `db/001`–`0NN` — the mirror and roles (copied from Help Desk, prefix `ct_`), settings and the
   seeds (design §6), clients, rates, engagements, time, expenses, usage, invoicing, documents, talking, agents, tokens — with
   the referee rules as triggers; the nine report functions; `ct_has_right()`, `ct_can_see_engagement()`; the `mcp_*` views;
   `db/proof/phase0_proof.sql` on a scratch database (the cases of design §10, phase 0); the kit copied from Help Desk and
   proven without a kernel (`tests/phase0/run.sh`); `maludb-os.json` (§14 — the six `shares[]`, the one `reads[]`, every port
   env, the approvals of §5); `os/{expert,biller}.md`; the seven skills; `deploy/` with the vhost allow-list and `ROOT_STEPS.sh`
   pinning 8185/8831/8832; the installer's `plan` clean.
3. **Phase 1**: `docs/consultant-tracking-mcp-tool-surface.md` (every tool of design §7 with arguments, the resolve table,
   the log-payload rules, the six share documents' shapes `os.ct-*/1`), `docs/consultant-tracking-action-manifest.md` (every
   screen of §9, every action of §5 with its category), `mcp/action_registry.json` (built clean by
   `bin/build_action_registry.php --check`), and the ten specs in `docs/build-specs/` — `sso-shell`, then slices 1–9 of
   design §10, each in the shape of `/srv/apps/helpdesk/docs/build-specs/tickets-core.md` (screens, files, query-function
   signatures, handlers, manifest entries, log events, notifications, status vocabulary, out of scope, proof, "Open questions"
   EMPTY). **The owner approves Phase 1 as a whole before any PHP.**
4. **Phase 2** (`sso-shell`), **slice 1** (clients, rates and settings — the CRUD pattern) and **slice 2** (engagements).
5. **Slice 3, time — THE EXEMPLAR**: the phone-first week grid, entries, the timer, submit/approve/reject/reopen, locking, the
   rate and cost snapshots, write-off, team time, missing time, utilization, the timeline; its proof suite
   `tests/phase3/slice3/run.sh` green at 375 and 1280. This is what every later slice copies.

**The handoff point** = the commit after slice 3 with design §16 saying "exemplar built; slices 4–9 open to workers". **REACHED 2026-10-05**: steps 1–5 are
all built and proven (K17 db/168; Phase 0 171 + 15; Phase 1 approved; Phase 2 247; slice 1 259; slice 2 173; slice 3 195 checks) — the next commit on this repository is a worker's, slice 4 (`docs/build-specs/expenses-receipts.md`).

**After the handoff (worker model — Sonnet 5.5):** slices 4 → 9 in order, one at a time, each on the exemplar's pattern
from its spec: screens + handlers + `log_activity()` + manifest entries + the tools' query functions, proven on a scratch
database, the spec's "Built and proven" and design §16 updated, one commit per slice. Then **Phase 4** (the two MCP servers
over the same query functions — `mcp/records_server.py`, `mcp/activity_server.py`, tool modules `mcp/ct_*.py`, own `mcp/venv`;
`app_roles` and the six `shares[]` admitted to the kernel's token; the registry wrapper
`deploy/kernel-registry-consultant_tracking.json`; the agents' grants and the Clerk's duty — Help Desk's `tests/phase4/run.sh`
is the pattern), and **Phase 5** with the owner (ports pinned, `apply` — root, the owner runs it —, DNS and TLS for
`consulting.<domain>`, the MaluMail key, the hires, the first rate card and client, the end-to-end proof of design §10).

**A worker's rules:** read this file, design §3 (rights), §5 (what pauses), §6 (the tables — never modify a migration, add
one), §15 (D1–D16) and the slice's spec before touching a file; reads through `mcp_*` views, writes to base tables after the
gate; the database's refusal is shown as a field error, never re-implemented in PHP; money events carry `amount` and
`currency` in `after`; a cost rate never appears in a payload; a question the spec does not answer stops the slice and is
written into the spec's "Open questions" for the owner — never guessed.

## Environment facts
- Install path `/srv/apps/consultant_tracking`; database `subello_consultant_tracking` on the office's PostgreSQL 17 (roles
  `consultant_tracking_rw`, `consultant_tracking_records_ro`, `consultant_tracking_activity_ro`) — not yet created.
- Vhost `consulting.subello.com`; ports to pin: `APP_INTERNAL_PORT=8185`, `MCP_RECORDS_PORT=8831`, `MCP_ACTIVITY_PORT=8832`
  (the kernel owns 8080 and 8811–8816; HR 8180/8821/8822; Projects 8181/8823/8824; Help Desk 8182/8825/8826; Cidery
  8183/8827/8828; General Ledger 8184/8829/8830; Reservations 8100; txtSchedules 8101–8103; Help Desk's proofs use 8291–8297 —
  pick 8301–8307 for ours). The kernel's internal API: `OS_INTERNAL_URL=http://127.0.0.1:8080`.
- Kernel items this application waits on (design §12): K17 catalog seed (first), K18 the people rule, K19 a cost tag on runs,
  K16/K8 wake-an-agent; siblings: G1 (General Ledger's `invoice_receipts` share and source import), H2 (HR pays
  reimbursements), P1 (Projects' `projects_list`).
- **State (2026-10-05): the plan APPROVED (§15 D1–D16, D16 revised to the GL division); K17 applied in the kernel (db/168); Phase 0's
  second half BUILT and PROVEN** — `db/001`–`015` (171 checks, `db/proof/phase0_proof.sql`), the kit from General Ledger un-ledgered
  (`tests/phase0/run.sh`, 15 checks; scratch ports 8301–8307), `maludb-os.json`, `os/{expert,biller}.md`, seven skills, `deploy/`,
  the installer's `plan` clean. The decisions taken while building are design §16 (a–h) — rules, not questions. **Phase 1 WRITTEN 2026-10-05** —
  `docs/consultant-tracking-mcp-tool-surface.md`, `docs/consultant-tracking-action-manifest.md` (78 screens, 108 actions, 57 approvals = `maludb-os.json`),
  `mcp/action_registry.json` (`--check` OK), the ten specs in `docs/build-specs/` — **APPROVED by the owner 2026-10-05. Phase 2 BUILT and PROVEN 2026-10-05** (`tests/phase2/run.sh`, 247 checks; the fakes on 8302/8303/8306,
  the app on 8301, browser proofs with Playwright from `/var/www/web/node_modules`). **Slice 1 BUILT and PROVEN 2026-10-05** (`tests/phase3/slice1/run.sh`, 259 checks;
  the scratch database `ct_dev3`). **Slice 2 BUILT and PROVEN 2026-10-05** (`tests/phase3/slice2/run.sh`, 173 checks). **Slice 3 — time, THE EXEMPLAR — BUILT and PROVEN 2026-10-05**
  (`tests/phase3/slice3/run.sh`, 195 checks). **THE HANDOFF POINT IS REACHED**: slices 4–9, Phase 4 and Phase 5 are the worker model's, from their specs, replicating slice 3. Then slice 3 (`sso-shell`), slices 1–2, slice 3 (time, the exemplar), the handoff.
- The proofs: `tests/setup_dev.sh` builds the scratch database `ct_dev` and writes `$CT_DEV_ENV` (`/tmp/ct-dev.env`); `tests/phase0/run.sh`
  is the regression (the schema proof, then the kit on `php -S :8307` with `tests/dev_router.php`); `bin/directory_sync.php --from-file
  bin/dev_directory.json` applies the fixture (Owner 1, Priya 26, Marco 27, Bea 28, Ann 29, Dana 30). **Never put a `config/.env` here
  before the installer's `apply`** except the three-line port pin of `deploy/ROOT_STEPS.sh` step 0.
- Finance holds the `finance` role ALONE (never `user`, which logs time). `cost.table` is a derived right (`has_right()`): cost.view AND every engagement — the admin, Billing, Finance read the cost-rate table; a Manager never.
- The exemplar for every later slice is slice 3 (`app/features/time/*`, `html/time/*`, `app/views/time/*`, `tests/phase3/slice3/*`): the verb inside `ct_guard()`, the view's row as the only source of a rate or a cost, the week's keys on every log row, `queue_time_notice()` for the notices, the proof style (a `time_world()` fixture, one file per rule, the browser last).
- An engagement the caller may not see answers 404 on every handler (never 403); a Manager opens an engagement for any live client (the view guards the details, not the name).
- Documents and notes on any record go through the generic doors `/engagements/attachments/*` and `/engagements/notes/*` (`record_type` + `record`; `record_writable()` is the rule) and the shared partials `app/views/shared/{attachments,notes,timeline}.php`; a file is served at `/attachments/{id}` through the gate. The shell's menu table is `app/features/shell/nav.php` (five groups: Work, Manage, Billing, Reports, Settings; the phone's tabs Home,
  Time, Expenses, Engagements). `app/activity.php` takes `engagement_id`, `client_id`, `invoice_id`, `timesheet_id` (and `actor_client_id`
  for the public door) on every row. `app/auth.php` has `require_can_see_engagement()` / `require_can_manage_engagement()` over the SQL
  rules. `PARTIAL_UPDATE_TARGETS` is empty until the manifest names the `*_update` handlers. The writer's verbs are SQL functions
  (`ct_timesheet_submit/approve/reject/reopen`, `ct_time_entry_write_off`, `ct_timer_start/stop`, `ct_expense_submit/approve/reject/
  mark_reimbursed`, `ct_usage_charge_approve/void`, `ct_recurring_charge_run`, `ct_ai_statement_load/allocate`, `ct_invoice_add_unbilled/
  issue/mark_sent/void/write_off`, `ct_credit_note_issue`, `ct_payment_claim_confirm`, `ct_secure_link_mint/invoice`, `ct_budget_alerts_check`);
  a handler calls the verb inside `ct_guard()` and shows the database's sentence.
