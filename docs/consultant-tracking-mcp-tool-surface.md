# Consultant Tracking — MCP tool surface

2026-10-05 · **Phase 1, for the checkpoint.** The read tools every question of `docs/consultant-tracking-design.md` §7 is answered
by. Writes are never here: they are the action tools the kernel's Actions MCP builds from `docs/consultant-tracking-action-manifest.md`.

Conventions (mcp-and-api.md): Python 3 + FastMCP, streamable HTTP at `/mcp`, loopback, one systemd unit each; `db.py` and
`server_common.py` are in `mcp/` (General Ledger's lineage); every query runs with `app.member_id` set transaction-locally
from the verified bearer, so the `mcp_*` views (db/015) and the report functions (db/014) decide the rows. Tool names are
snake_case; every tool takes one `params` object; every list tool takes `limit` (1–100, default 25); every tool carries
`readOnlyHint: true`, `openWorldHint: false`; results are JSON strings. Times are UTC and the caller's zone; money carries its
currency; hours are decimal hours.

Gates are what the view or function already enforces — the tool only trims. Words used below (design §3, db/004, db/008):

| Word | Means |
|---|---|
| `own` | the caller's own: their weeks and entries, their expenses, their timer, their notifications and tokens |
| `staffed` | an engagement the caller is or was staffed on, manages, or whose practice they administer (`ct_visible_engagement_ids()`) |
| `manage` | `engagements.manage` AND the engagement is theirs (manager, practice admin) or they hold `billing.write` (`ct_managed_engagement_ids()`) |
| `approve` | `time.approve` / `expenses.approve` / `usage.approve` on a staffed engagement |
| `rates` | `rates.view` — bill rates and rate cards |
| `cost` | `cost.view` — cost rates and margin; a Manager only on the engagements they manage (`ct_cost_visible()`) |
| `billing` | `billing.write` or `invoices.draft` on a staffed engagement (invoices) |
| `all` | Billing, Finance, admin, the super-admin (`ct_sees_all()`): every engagement, client, invoice |
| `admin` | `settings.manage` |
| `member` | any admitted member: the vocabulary, the settings, names |

**A Consultant never gets a bill rate, a cost, an invoice, a client's contact details or another person's time** — the views
blank or omit them by right; an agent is a member like a person, so an agent granted only `user` reads as a Consultant
would, `records_search` included. **A client by the secure link is not a caller here**: PHP serves them from base tables
through `ct_secure_link_invoice()`.

Two token shapes, one key: a person's own `mcp_` token (`mcp_resolve_token`), or the tenant's signed run token
(`ACTION_TOKEN_KEY`), for which the server asks the kernel's run-facts call once per run and offers exactly the tools granted —
fail closed; no tool acts when `is_eval`. **The kernel's own token reaches `app_roles` and the six `shares[]` tools** (K7),
nothing else.

## Records server — `consultant_tracking_records_mcp` (role `consultant_tracking_records_ro`, `MCP_RECORDS_PORT`)

### Roles (kernel db/145)
`app_roles` — no arguments — answers `os.app-roles/1`: the five roles (key, name, description, capability, is_admin, rights[])
and the twenty-one rights, from `mcp_app_roles` (db/004).

### People, the vocabulary, rates
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `find_members` | Looking up a person or an agent by name — to staff, to approve for, to name a manager. **Call before `engagement_member_save`, `engagement_save` (manager)**. `q` alone answers a plain list (`member_id`, `display_name`) | CE3, T2 | `mcp_members` | `q?`, `kind?` (human, agent), `department_id?`, `limit` | member (names, kind, roles, primary practice); no email but one's own |
| `find_departments` | The practices (the kernel's departments) by name, with their manager | CE2, T5 | `mcp_departments` | `q?`, `limit` | member |
| `ct_settings` | Every legal value at once: the settings (week start, increment, receipt threshold, markup default, terms, grouping, thresholds, capacity default), consulting roles, activity types (with billable default), expense categories (with receipt and markup overrides), usage providers, tax rates, the numbering prefixes. **Call before setting any field**; `q` alone resolves a role, an activity, a category or a provider by name (`part` says which) | CE8, E3, U1 | `mcp_settings`, `mcp_consulting_roles`, `mcp_activity_types`, `mcp_expense_categories`, `mcp_usage_providers`, `mcp_tax_rates` | `q?`, `part?` (settings, roles, activities, categories, providers, tax) | member |
| `rate_cards` | The rate cards and the bill rate per consulting role on each; one card in full. **Call before `engagement_save` (rate_card)** | CE8 | `mcp_rate_cards`, `mcp_rate_card_rates` | `q?`, `rate_card_id?`, `limit` | `rates` |
| `resolve_rate` | What rate applies to a person on an engagement on a date, and why: `bill_rate`, `rate_source` (member_override, engagement_role, rate_card, none), the consulting role | CE4 | `ct_resolve_rate()`, `mcp_engagement_members` | `engagement_id`, `member_id`, `on_date?` (today) | `rates` and `staffed` |

### Clients and engagements
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `find_clients` | The clients by name or code, with terms, currency, PO rule, active engagements; contact details to those who bill. **Call before `engagement_save`, `invoice_draft`**. `q` alone answers a plain list (`client_id`, `name`) | CE1 | `mcp_clients` | `q?`, `archived?`, `limit` | `staffed` (their clients, no details); `clients.manage` or `billing`: all with details |
| `get_client` | One client in full: contacts (who receives invoices), engagements by status, open invoices and balance, the last statement lines | CE1, B12 | `mcp_clients`, `mcp_client_contacts`, `mcp_engagements`, `mcp_invoices` | `client_id` | as `find_clients`; invoices: `billing` |
| `client_statement` | A client's statement: invoices, credit notes, payments and write-offs in date order with the running balance per currency | B12 | `ct_client_statement()` | `client_id`, `from?`, `to?` | `billing` or `reports.view` |
| `find_engagements` | Engagements by words, number, client, kind, status, manager, practice, ending within N days; each row: number, name, client, kind, status, dates, currency, manager, `i_manage`, `i_am_staffed`, staffed count. **`q` alone answers a plain list** (`engagement_id`, `label` = "ENG-0042 — Acme pilot") for the resolver | CE2 | `mcp_engagements` | `q?`, `client_id?`, `kind?`, `status?[]`, `manager?` (me or an id), `department_id?`, `ending_within_days?`, `mine?`, `limit` | `staffed`; `all` |
| `get_engagement` | One engagement in full: every field, the client, the manager, the staffing (roles; overrides with `rates`), the role rates (`rates`), the milestones, the budget status, the open alerts, the next billing date, documents (names), the last ten events | CE2, CE3, CE5, CE6 | `mcp_engagements`, `mcp_engagement_members`, `mcp_engagement_role_rates`, `mcp_milestones`, `ct_budget_status()`, `mcp_budget_alerts`, `mcp_attachments` | `engagement_id` or `number` | `staffed`; rates: `rates`; cost: never here (`margin`) |
| `engagement_staffing` | Who is staffed on an engagement, in what role, from when, with the override (`rates`); or everything one person is staffed on | CE3 | `mcp_engagement_members` | `engagement_id?` or `member_id?`, `on_date?`, `limit` | `staffed` |
| `milestones` | The milestones of an engagement, or every milestone due / delivered / accepted-unbilled across engagements. `q` alone resolves one by name | CE6, B2 | `mcp_milestones` | `q?`, `engagement_id?`, `status?[]`, `due_before?`, `limit` | `staffed` |
| `budget_status` | Where an engagement stands: hours used of budget, bill value of the fee, invoiced of the not-to-exceed, AI cost of the AI budget, the pace and the weeks left — one engagement or every active one over a threshold | CE5, U6 | `ct_budget_status()`, `mcp_engagements` | `engagement_id?`, `over_pct?` (every active engagement over this on any budget), `limit` | `staffed` (no cost — the AI figure is at cost: `cost` or `usage.write`, else omitted) |
| `budget_alerts` | The alerts written and not yet acknowledged — which engagement crossed which threshold when — or an engagement's history of them | CE7 | `mcp_budget_alerts` | `engagement_id?`, `unacknowledged?` (default true), `limit` | `manage` or `reports.view` |

### Time
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `my_timesheet` | The caller's week: the entries by day and engagement with notes, the totals, the status, the rejection reason; any week by `week_start`; another person's with `approve`. **An agent's "log my time" starts here**. `q` alone resolves an entry of the caller's by words | T1 | `mcp_timesheets`, `mcp_time_entries` | `week_start?` (this week), `member_id?` (`approve`), `q?` | `own`; `approve` for a staffed person's |
| `my_timer` | Whether a timer runs for the caller, since when, on what, the elapsed hours | T7 | `mcp_time_timers` | — | `own` |
| `missing_time` | Who has not submitted a week and which weekdays are empty, with their hours logged and capacity — last week by default | T2 | `ct_missing_time()` | `week_start?` | `time.approve` or `reports.view` |
| `timesheets_pending` | The submitted weeks awaiting the caller's approval (entries on engagements they manage), oldest first, with the hours and the people; `q` alone resolves a week by person and date | T3 | `mcp_timesheets`, `mcp_time_entries` | `q?`, `member_id?`, `limit` | `approve` |
| `time_summary` | Hours on an engagement (or a client, or a person) over a period, grouped by person, activity, day, week or billable — with bill value under `rates` | T4 | `mcp_time_entries` | `engagement_id?` or `client_id?` or `member_id?`, `from`, `to`, `group_by` (person, activity, day, week, billable, status), `status?[]` | `own`; `approve` on staffed; `all` |
| `utilization` | Billable hours against capacity per person for a period — the caller's own, a practice's, everyone's | T5 | `ct_utilization()` | `from`, `to`, `member_id?`, `department_id?` | `reports.view_own` (self); `reports.view` |
| `time_written_off` | Hours written off in a period, by engagement and person, with the reasons | T6 | `mcp_time_entries` | `from?`, `to?`, `engagement_id?`, `limit` | `approve`; `all` |

### Expenses
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `my_expenses` | The caller's expenses by status with the receipt flag, billable amount, reimbursement state; another person's with `approve`. `q` alone resolves one by number or words | E1 | `mcp_expenses` | `q?`, `status?[]`, `member_id?`, `from?`, `to?`, `limit` | `own`; `approve` |
| `my_reimbursements_due` | What the caller is owed: approved personal-card expenses not yet reimbursed, with the total per currency | E1 | `mcp_expenses` | — | `own` |
| `expenses_pending` | Submitted expenses awaiting the caller's approval, oldest first, with the receipt flag and the threshold that applies | E2 | `mcp_expenses` | `engagement_id?`, `missing_receipt?`, `limit` | `approve` |
| `expense_summary` | Spend on an engagement (or a client, a person) over a period by category, billable or not, with markup — approved only, or every status | E3 | `mcp_expenses` | `engagement_id?` or `client_id?` or `member_id?`, `from`, `to`, `group_by` (category, person, month, billable), `status?[]` | `own`; `approve`; `all` |
| `reimbursements_approved` **(share, people)** | Approved, unreimbursed personal-card expenses per member id — what HR pays in a pay run (`os.ct-reimbursements/1`) | E4 | `mcp_expenses` | `as_of?`, `member_id?` | `billing`; the kernel's token (HR, K7) |
| `expenses_by_category` **(share)** | Approved company-card expenses by category and month, with tax and the billable flag, no person named — what the ledger imports (`os.ct-expenses/1`) | E5 | `mcp_expenses` | `from`, `to` | `billing` or `reports.view`; the kernel's token |

### AI usage and hosting
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `usage_summary` | Usage charges on an engagement over a period by provider, kind and source — quantity, cost (`cost`), billable, billed — or the drafts awaiting approval; `q` alone resolves a charge by number or words | U1, U5 | `mcp_usage_charges` | `q?`, `engagement_id?`, `from?`, `to?`, `status?[]`, `source?`, `group_by?` (provider, kind, source, month), `limit` | `usage.write` or `manage` on staffed; `all` |
| `usage_pending` | Draft usage charges awaiting approval, by engagement, with their source (a schedule, an import, the OS statement) | U5 | `mcp_usage_charges` | `engagement_id?`, `limit` | `approve` |
| `ai_passthrough` | What a client's AI systems cost and what was billed for it: per engagement and provider, cost, billable, billed, unbilled, markup | U1 | `ct_ai_passthrough()` | `from`, `to`, `engagement_id?` | `usage.write` or `reports.view` on staffed |
| `ai_statement_status` | The kernel's months as this application holds them: pulled or allocated, the statement's total, what is allocated, the remainder by provider — and the lines of one month with their remainders. `q` alone resolves a month | U2 | `mcp_ai_statements`, `mcp_ai_statement_lines`, `ct_ai_statement_summary()` | `q?`, `period?` ('2026-09'), `with_lines?` | `usage.write` |
| `allocation_rules` | The rules — agent, department, application, engagement, share, pricing, dates, active — and what each produced on a month | U3 | `mcp_allocation_rules`, `mcp_ai_statement_allocations` | `q?`, `engagement_id?`, `active?`, `period?`, `limit` | `usage.write` |
| `recurring_due` | The hosting schedules: which fell due and are not yet run, which run next when; or one engagement's | U4 | `mcp_recurring_charges` | `engagement_id?`, `due_only?` (default true), `limit` | `usage.write` |

### Billing
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `unbilled_summary` **(share)** | What is approved and unbilled (WIP) per engagement as of a date: hours and amount, no-rate hours, expenses, usage, milestones, total, the oldest item — or one engagement, or one client (`os.ct-wip/1`) | B1 | `ct_unbilled()` | `as_of?`, `engagement_id?`, `client_id?` | `staffed`; the kernel's token |
| `unbilled_items` | The items behind one engagement's WIP: each time entry, expense, usage charge, milestone with date, person, description, quantity, amount, `no_rate` | B1 | `ct_unbilled_items()` | `engagement_id`, `as_of?` | `staffed` |
| `invoices_due_to_draft` | Which engagements' billing date has passed with something unbilled, which accepted milestones await an invoice, and which engagements already have an open draft — the Clerk's list | B2 | `mcp_engagements`, `ct_unbilled()`, `mcp_milestones`, `mcp_invoices` | `as_of?`, `limit` | `invoices.draft`; `all` |
| `find_invoices` | Invoices by words, number, client, engagement, status, overdue, period; each row: number, client, engagement, dates, total, paid, balance, status, `overdue`, open claims. **`q` alone answers a plain list** (`invoice_id`, `label` = "INV-00042 — Acme, Mar 2026") | B3 | `mcp_invoices` | `q?`, `client_id?`, `engagement_id?`, `status?[]`, `overdue?`, `issued_from?`, `issued_to?`, `limit` | `billing` on staffed; `all` |
| `get_invoice` | One invoice in full: the head, the lines, the backing items per line (entries with person and date; expenses with the receipt flag; usage; milestones), payments, credits applied, claims, the link's state (live/rotated/expiry — never the token), reminders sent, the last ten events | B3, B10 | `mcp_invoices`, `mcp_invoice_lines`, `mcp_invoice_line_items`, `mcp_invoice_payments`, `mcp_credit_applications`, `mcp_payment_claims`, `mcp_time_entries`, `mcp_expenses`, `mcp_usage_charges`, `mcp_milestones` | `invoice_id` or `number` | `billing` on staffed; `all` |
| `invoices_issued` **(share)** | Invoices issued (not void) in a period, lines summarised by kind, with client, engagement number, dates, terms, currency, totals, tax, `external_ref` — the ledger's source import (`os.ct-invoices/1`) | B4 | `mcp_invoices`, `mcp_invoice_lines` | `from`, `to`, `client_id?` | `billing` or `reports.view`; the kernel's token |
| `credit_notes_issued` **(share)** | Credit notes issued in a period, the same shape (`os.ct-credit-notes/1`); `q` alone resolves one by number | B7 | `mcp_credit_notes`, `mcp_credit_note_lines` | `q?`, `from?`, `to?`, `limit` | `billing`; the kernel's token |
| `revenue` | What was invoiced in a period by client, engagement, line kind, practice or month — invoices, subtotal, tax, total per currency | B4 | `ct_revenue()` | `from`, `to`, `by` (client, engagement, kind, practice, month) | `reports.view`; `manage` for their engagements |
| `ar_aging` | Who owes us today, bucketed by the date asked: current, 1–30, 31–60, 61–90, 90+, per client and currency, with the open-invoice count | B5 | `ct_ar_aging()` | `as_of?` | `billing` or `reports.view` |
| `payment_claims` | What clients said on the secure link and billing has not yet decided — invoice, date, method, reference, amount; or one invoice's claims. `q` alone resolves a claim | B6 | `mcp_payment_claims` | `q?`, `invoice_id?`, `status?` (submitted by default), `limit` | `billing` |
| `realization` | Of the hours approved in a period, how many were billed, written off, still unbilled, had no rate; standard value vs billed — per engagement | B8 | `ct_realization()` | `from`, `to`, `engagement_id?` | `reports.view`; `manage` |
| `margin` | Bill value less cost (time at cost rate, expenses, usage at cost) per engagement for a period — only to those who may see cost, and for a Manager only on their own engagements; the same nothing otherwise | B9 | `ct_margin()` | `from`, `to`, `engagement_id?` | `cost` |
| `engagement_list` **(share)** | Active engagements: number, name, client short name, kind, dates, manager's name; no money (`os.ct-engagements/1`) | CE2 | `mcp_engagements` | `status?` (active), `client?` | `staffed`; the kernel's token (Projects, Help Desk) |

### The long tail
`records_search` — one read-only SELECT over the `mcp_*` views as the asking member (db/015 — 44 views, listed in the tool's description); already scoped; one statement, 5 s timeout, 200 rows; `set_config`, the secure-link and numbering functions are refused by name.

## Activity server — `consultant_tracking_activity_mcp` (role `consultant_tracking_activity_ro`, `MCP_ACTIVITY_PORT`)
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `record_history` | Who did what to one record, in order: an engagement, a week, an entry, an expense, a usage charge, an invoice, a client — each row worded with the actor (a person or an **agent**), `before`/`after` | T8, B10, ACT1 | `mcp_activity_log`, `mcp_members` | `entity_type`, `entity_id` (or `engagement_id` / `timesheet_id` / `invoice_id` for everything that touched it), `since?`, `limit` (default 100) | the record's own rule (the view) |
| `actor_timeline` | What a person or an agent did here in a period — every row, newest first | ACT2, B11 | `mcp_activity_log` | `member_id` (or `me`), `from?`, `to?`, `action?` (prefix: `invoice.`), `limit` | `own`; `reports.view` on staffed engagements; `all` |
| `who_touched` | Who acted on an engagement or an invoice and how many times each, in a period | ACT4 | `mcp_activity_log`, `mcp_members` | `engagement_id` or `invoice_id`, `from?`, `to?` | the record's rule |
| `recent_activity` | What changed since a time across what the caller may see — grouped by engagement, newest first | ACT3 | `mcp_activity_log` | `since`, `engagement_id?`, `limit` | the view |
| `share_reads` | Which sibling application read what of ours through the kernel, when (the `share.read` rows) | ACT4 | `mcp_activity_log` | `from?`, `to?`, `app?`, `limit` | `billing` or `admin` |
| `activity_search` | The guarded SELECT over `mcp_activity_log` (and the names) for the long tail | ACT5 | the views | `sql` | the view |

## Coverage of the question inventory (design §7 → tools)
| Question | Tools |
|---|---|
| CE1 | `find_clients`, `get_client` |
| CE2 | `find_engagements`, `get_engagement`, `engagement_list` |
| CE3 | `engagement_staffing`, `get_engagement` |
| CE4 | `resolve_rate` |
| CE5 | `budget_status`, `get_engagement` |
| CE6 | `milestones` |
| CE7 | `budget_alerts` |
| CE8 | `rate_cards`, `ct_settings` |
| T1 | `my_timesheet` |
| T2 | `missing_time` |
| T3 | `timesheets_pending` |
| T4 | `time_summary` |
| T5 | `utilization` |
| T6 | `time_written_off` |
| T7 | `my_timer` |
| T8 | `record_history` |
| E1 | `my_expenses`, `my_reimbursements_due` |
| E2 | `expenses_pending` |
| E3 | `expense_summary` |
| E4 | `reimbursements_approved` |
| E5 | `expenses_by_category` |
| U1 | `usage_summary`, `ai_passthrough` |
| U2 | `ai_statement_status` |
| U3 | `allocation_rules` |
| U4 | `recurring_due` |
| U5 | `usage_pending`, `usage_summary` |
| U6 | `budget_status` |
| B1 | `unbilled_summary`, `unbilled_items` |
| B2 | `invoices_due_to_draft`, `milestones` |
| B3 | `find_invoices`, `get_invoice` |
| B4 | `invoices_issued`, `revenue` |
| B5 | `ar_aging` |
| B6 | `payment_claims` |
| B7 | `credit_notes_issued` |
| B8 | `realization` |
| B9 | `margin` |
| B10 | `record_history`, `get_invoice` |
| B11 | `actor_timeline` |
| B12 | `client_statement`, `get_client` |
| ACT1–ACT5 | `record_history`, `actor_timeline`, `recent_activity`, `who_touched`, `share_reads`, `activity_search` |

## What the log must carry for the activity tools (the manifest's log-payload rules)
Every row: `action` = `entity.verb`; `entity_type`/`entity_id`; the keys `engagement_id`, `client_id`, `invoice_id`, `timesheet_id`
when the event concerns one (the funnel fills the obvious one from the entity); `source` web/agent/cron/portal; `agent_run_id` and
the run's request id under a run token. **`after` carries `amount` and `currency` on every money event** (an invoice issued,
voided, written off; a payment; a credit note; a usage charge approved; an expense approved). **Never in a payload**: a cost rate,
a secure token, a client's email or phone, an internal note's text beyond 200 characters, a person's phone.

| Event | `before` / `after` |
|---|---|
| `client.create` / `update` / `archive` | changed fields (name, code, terms, currency, po_required, rate card); never email or phone |
| `engagement.create` / `update` | number, client_id, kind, status, dates, budgets, cadence, manager_member_id, department_id (changed only) |
| `engagement.activate` / `hold` / `close` / `reopen` / `cancel` | `before.status`, `after.status`, reason |
| `engagement.staff` / `unstaff` / `rate_override` | member_id, consulting_role_id, valid_from/to; `after.has_override` (never the rate to a log — the rate is on the row) |
| `milestone.save` / `deliver` / `accept` / `cancel` | name, amount, currency, `before.status`, `after.status`, accepted_by |
| `budget_alert.write` / `acknowledge` | kind, threshold_pct, value_at, budget_at |
| `timesheet.submit` / `reopen` / `approve` / `reject` | week_start, member_id, hours, `approved`/`left_submitted`, reason |
| `time_entry.save` / `delete` / `write_off` | entry_date, engagement_id, hours, billable, activity_type_id, reason; never the internal note |
| `timer.start` / `stop` | engagement_id, hours (on stop) |
| `expense.save` / `submit` / `approve` / `reject` / `reimburse` / `attach` | number, amount, currency, category_id, paid_with, billable, reason, reimbursed_ref, filename |
| `usage_charge.draft` / `approve` / `void`; `usage_import.run`; `recurring_charge.run` | number, kind, provider, quantity, unit, cost_amount, billable_amount, currency, source |
| `ai_statement.pull` / `allocate` | period, charges made, allocated_amount, remainder |
| `invoice.draft` / `add_unbilled` / `issue` / `send` / `view` / `remind` / `void` / `write_off` | number, client_id, engagement_id, total, currency, the add-unbilled counts, reason, nte_override_reason |
| `payment.record` / `void`; `payment_claim.submit` / `confirm` / `dismiss` | invoice_id, amount, currency, method, reference (never the client's note beyond 200 chars) |
| `credit_note.draft` / `issue` / `apply` / `void` | number, invoice_id, total, currency, reason |
| `share.read` | the sibling `app`, the `tool`, the arguments' keys, rows answered |
| `export.download` | which export, from, to, rows |
| `screen.view` | screen; on a record's screen, the record's key |

## Entity resolution for the action tools (the registry's `resolve` block — `deploy/kernel-registry-consultant_tracking.json`)
| Param(s) | Tool (with `q`) | id field | label |
|---|---|---|---|
| `client` | `find_clients` | `client_id` | `name` |
| `engagement` | `find_engagements` | `engagement_id` | `label` |
| `milestone` | `milestones` | `milestone_id` | `name` |
| `timesheet`, `week` | `timesheets_pending` | `timesheet_id` | `label` ("Priya — week of Mar 2") |
| `entry`, `time_entry` | `my_timesheet` | `entry_id` | `label` |
| `expense` | `my_expenses` | `expense_id` | `label` ("EXP-000123 — Dinner, Mar 3") |
| `charge`, `usage_charge` | `usage_pending` | `usage_charge_id` | `label` |
| `invoice` | `find_invoices` | `invoice_id` | `label` |
| `credit_note` | `credit_notes_issued` | `credit_note_id` | `label` |
| `claim` | `payment_claims` | `claim_id` | `label` |
| `rate_card` | `rate_cards` | `rate_card_id` | `name` |
| `consulting_role`, `role`; `activity`, `activity_type`; `category`; `provider` | `ct_settings` (with `part`) | the id | the title / label / name |
| `rule`, `allocation_rule` | `allocation_rules` | `rule_id` | `name` |
| `schedule` | `recurring_due` | `schedule_id` | `description` |
| `statement`, `ai_statement` | `ai_statement_status` | `ai_statement_id` | `period` |
| `member`, `to_member`, `person`, `manager` | `find_members` | `member_id` | `display_name` |
| `department`, `practice` | `find_departments` | `department_id` | `name` |

## The share documents (what a sibling reads through the kernel — K7, design §8)
Every share answers a versioned document: `{"schema": "os.ct-<name>/1", "generated_at", "application": "consultant_tracking", "from", "to", "currency_note": "amounts carry their own currency; nothing is converted", "rows": [...]}`.
- `os.ct-invoices/1` rows: `number`, `client` {`client_id`, `name`, `legal_name`, `code`}, `engagement_number`, `issue_date`, `due_date`, `terms_days`, `currency`, `subtotal`, `tax_total`, `total`, `status`, `external_ref`, `lines_by_kind` [{`kind`, `subtotal`, `tax`, `total`}].
- `os.ct-credit-notes/1`: the same, `invoice_number` instead of dates past `issue_date`, amounts positive (the reader negates).
- `os.ct-expenses/1` rows: `month`, `category` {`key`, `label`}, `engagement_number`, `currency`, `amount`, `tax_amount`, `billable`, `count` — company-card only.
- `os.ct-wip/1` rows: `as_of`, `engagement_number`, `client_name`, `currency`, `time_amount`, `expense_amount`, `usage_amount`, `milestone_amount`, `total_amount`.
- `os.ct-reimbursements/1` rows (`people: true`): `member_id`, `expense_number`, `expense_date`, `currency`, `amount`, `description` — approved, personal card, unreimbursed. HR answers back through its own share (`reimbursements_paid`, H2) with `expense_number` and the pay run's reference; the worker calls `ct_expense_mark_reimbursed()`.
- `os.ct-engagements/1` rows: `number`, `name`, `client_name`, `kind`, `status`, `start_date`, `end_date`, `manager_name`.
- The read this application makes: `gl.invoice_receipts` (G1) — rows `{external_ref (our number), received_on, amount, currency, method, reference}`; the worker records each as an `invoice_payments` row with `source = 'ledger'` once (by `reference` + `received_on`), never twice.

## Size
Records: 46 named tools + `records_search` + `app_roles` = 48 (the table rows count 46; Phase 1's "43" was a miscount), six of them shares; activity: 6. Every question of §7 has a tool; every
`find_*`/list tool with `q` answers the resolver's plain list.
