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
- [ ] Priya's home: her week and timer, her reimbursement owed, no manager or billing region; Marco's: approvals waiting with the right counts, the alerts, the ending engagements; Bea's: the WIP total, drafts, due, the aging counts, the statement's remainder, the claims, the morning note.
- [ ] Agents: the two shipped agents with their roles and last activity; Connections: the fake kernel's reads shown with outcomes.
- [ ] 375 × 740 and 1280 × 800: the home's regions stack on the phone; the registry reads every screen and action of the manifest as built (78 / 108).

## Open questions
