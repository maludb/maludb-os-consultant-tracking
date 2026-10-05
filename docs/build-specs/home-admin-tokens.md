# Build spec: home, the admin pages and tokens (slice 9)

What exists at the end: the three homes — a consultant's, a manager's, billing's — read from the slices; the Agents page shows the agents that work
here and their last runs; the Connections page shows what sibling applications read of ours and what we read; the settings are polished; the
`/settings/tokens/` page is real for every member. Nothing new in the schema.
Schema: everything built; `mcp_agent_dispatches`, `mcp_notifications`, `mcp_my_tokens`, `mcp_activity_log`, `mcp_settings` (db/015). Never modify them.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `dashboard` | `/` | **everyone**: My week (hours vs capacity, Submit when open, the week's status), the timer bar, My reimbursements due, the bell's last five. **Manager** (`time.approve|expenses.approve|usage.approve`): Approvals waiting (weeks, expenses, usage charges — counts as cards linking to the pages), Budget alerts (the unacknowledged), Engagements ending within 30 days. **Billing** (`invoices.draft|billing.write`): WIP total, drafts to issue, invoices due to draft, the aging's five counts, the OS statement's last month and its remainder, the claims waiting, the Clerk's last morning note |
| `agents` | `/settings/agents` | the agents the mirror knows (`member_kind = agent` with a capability): name, roles here, last activity (`mcp_activity_log`), last dispatch; a note that hiring, grants and duties are the kernel's (links to `OS_LAUNCHER_URL`'s OS pages) |
| `connections` | `/settings/connections` | `share_reads` (what siblings read of ours, when) and the reads we make (`gl.invoice_receipts`, `hr.reimbursements_paid`) with their last outcome from the worker's rows; a note that approving a connection is the super-admin's (`bin/app_connection.php`) |
| `settings-general`, `numbering`, `settings`, `tokens` | slice 1's and Phase 2's made whole | the morning-note recipient picker; the nudge day; the reminder days as a list editor |

## Query functions (signatures fixed)
- `home_summary(PDO, int $memberId): array` (Phase 2's shape, every key filled from the slices' query functions — one call per region, each honouring its right)
- `agents_here(PDO): array` · `connections_summary(PDO): array`

## Files (exactly these)
- `html/index.php` (real) · `app/features/home/{queries,present}.php` · `app/views/home/{dashboard,partials/my-week,partials/approvals,partials/billing}.php`
- `html/settings/agents.php` · `html/settings/connections.php` · `app/features/settings/agents.php` · `app/views/settings/{agents,connections}.php`

## Action manifest entries
Screens: `dashboard` (real), `agents`, `connections`. Actions: none new.

## Activity log events
`screen.view` only.

## Out of scope
Hiring or granting agents (the kernel's), approving connections (the kernel's).

## Proof (`tests/phase3/slice9/run.sh`)
- [x] Priya's home (home 53): her week and timer, her reimbursement owed, no manager or billing region; Marco's: approvals waiting with the right counts, the alerts, the ending engagements; Bea's: the WIP total, drafts, due, the aging counts, the statement's remainder, the claims, the morning note.
- [x] Agents (agents 19, connections 22, tokens 41, json 18, visibility 32): the two shipped agents with their roles and last activity; Connections: the fake kernel's reads shown with outcomes.
- [x] 375 × 740 (browser 73) and 1280 × 800: the home's regions stack on the phone; the registry reads every screen and action of the manifest as built (78 / 108).

**Built and proven 2026-10-05: 258 checks green** (`tests/phase3/slice9/run.sh`: agents 19, connections 22, home 53, tokens 41, json 18, visibility 32, browser 73); slices 1–8, Phase 2 and Phase 0 stay green (slice 8's registry expectation now reads 78 of 78 built); the registry reads **78 screens (78 built), 108 actions (108 built)** — nothing is a stub.

**Decisions taken while building**
- The kernel exposes no endpoint that lists an application's agents, duties or connections (the fake kernel serves the ledger, K6, K7 reads, the directory feed, run facts and the chat endpoint only), so both screens are built from what the application holds: `maludb-os.json` (the declared agents and shares/reads), the mirror (`members`, `member_kind = agent` — the directory feed's facts: hired = a member holding the declared role here, active), `agent_dispatches`, the activity log (`share.read`, the agents' own rows) and the Clerk's morning notes. Nothing asks the kernel.
- A declared agent is matched to a mirror agent by the roles it holds here (the expert: `manager`, the Billing Clerk: `billing`); a declared agent with no such member reads "Not hired here yet"; a mirror agent no declaration matches is listed after the declared ones. A deactivated agent is no longer in the mirror view, so reads as not hired.
- "Approving a connection is the super-admin's" is shown as the kernel's last answer to our own reads: 200 = approved, 403 = not approved (`no_connection`), no answer = unreachable, anything else = failed, no read yet = unknown. The last read of each of OUR six shares is the kernel's token's `share.read` row (`direction = in`): a time and a row count, never a row. `hr.reimbursements_paid` is listed beside the declared `gl.invoice_receipts` because the worker reads it (H2), though maludb-os.json declares only the ledger's read until HR ships the share.
- The OS pages (hiring, grants, duties, connection approval) are linked as the launcher's host with `app.` turned into `os.`; no form, button or POST on either page. The expert's chat is the command bar every page carries (through the kernel); the Agents page says so rather than embedding a second chat.
- The home's final audit changed little because slices 2–8 had filled their regions: added the Approvals waiting COUNT cards (weeks, expenses, usage charges — each links to its approval page; the list below stays capped at eight), Billing's OS statement row (the last month held, its status and the remainder to allocate — Billing's alone: `billing.write` and `usage.write`), and everyone's bell (the last five notifications) as `bell` in the JSON. `usage` in the dashboard JSON now goes through `present_usage_charge`. The Billing region's gate stays slice 6's (`invoices.draft|billing.write|reports.view`: a Manager sees the figures of his own engagements through the views, Finance reads) and the aging buckets stay amounts (a client's open total per bucket), not invoice counts.
- Consequently the Billing role holds the Manager's approving rights, so Bea's home carries the approvals region too; a Finance reader has no budget-alerts region (`engagements.manage`).
- The spec's file list names `home/partials/my-week` and `billing`; the My week region is slice 3's `time/partials/home-week.php` and the Billing region's partial is `home/partials/billing.php` — not moved. Settings polish (the morning-note recipient picker, the nudge day, the reminder days) was already whole in slice 1/8's `/settings/general`; tokens were already real in Phase 2 — this slice proves them for every role (`tokens.php`).

## Open questions
- **The kernel has no agents / connections endpoint.** Should the Agents screen show the kernel's own facts (hired date, the duty's last run and next run, the agent's run count) and Connections the approved connection list, a kernel call would be needed (a read of `/api/v1/agents/...` and `/api/v1/apps/connections.php`); until then both screens say what this application holds and where the rest lives (`os.<domain>`). Confirm whether the kernel will offer one.
