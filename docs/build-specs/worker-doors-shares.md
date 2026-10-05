# Build spec: the worker, the doors and the shares (slice 8)

What exists at the end: one timer worker does everything automated — the outbox (email through MaluMail, texts through the kernel's K6), Monday's
nudges, the invoices falling due on each engagement's cadence (drafts), the kernel's AI statement poll (hourly), the hosting schedules, the budget
checks, the reminders falling due, the ledger's receipts read (G1) and HR's reimbursements read (H2) through K7, claims ageing, link expiry,
dispatch retries; the exports page downloads the six documents; the six `shares[]` answer their documents (the tools are Phase 4's — this slice
writes their query functions). Nothing here issues, sends or approves on its own.
Schema: `notifications`, `notification_prefs`, `notification_outbox`, `agent_dispatches` (db/013); `engagements.next_billing_date`,
`recurring_charges`, `ai_statements`, `invoice_links_secure`, `payment_claims`, `invoice_payments`, `expenses.reimbursed_at` (db/008–012);
`ct_expense_mark_reimbursed()`, `ct_recurring_charge_run()`, `ct_ai_statement_load()`, `ct_ai_statement_allocate()`, `ct_budget_alerts_check()`,
`ct_invoice_add_unbilled()`. Never modify them.

## The worker (`bin/worker.php`, every minute from `consultant_tracking-worker.timer`; advisory-locked; each step its own try; one JSON line and one `worker.pass` row)
| Step | Every | What |
|---|---|---|
| `send` | minute | `outbox_send_batch()`: queued rows by `send_after`; email through MaluMail (`malumail_send`, `MAIL_FROM`), the provider's message id kept; a text through `POST {OS_INTERNAL_URL}/api/v1/notify/sms.php` (K6) — a refusal (`no_sender`, `not_held`, `no_verified_phone`, `opted_out`, `rate_limited`) is a **skip** with the code and the email row still goes; five attempts then `failed` |
| `nudge` | on the settings' nudge day, once | `ct_missing_time(last week)` → one `missing_time` notice per person (dedupe `nudge:<member>:<week>`), text when they chose it; the managers' summary in the morning note |
| `cadence` | daily 06:00 | engagements active with `next_billing_date ≤ today` and no open draft: `create_invoice()` with `add_unbilled` through the billing date; `next_billing_date` advanced by the cadence; `on_milestone`: an accepted, uninvoiced milestone → a draft; the `invoice_drafted` notice to the manager and billing |
| `statement` | hourly | when `ai_statement_poll`: `pull_ai_statements()` (every closed month not yet allocated) then `allocate_ai_statement()` for each newly closed month; `ai_statement_allocated` to billing with the remainder |
| `schedules` | daily | `run_schedule()` for every active schedule due (drafts); `usage_drafted` to the manager |
| `budgets` | daily | `ct_budget_alerts_check()` for every active engagement; `budget_alert` to the manager per new alert |
| `reminders` | daily | invoices open past due by a day in `reminder_days` and not reminded in 7 days → `remind_invoice()` (the outbox) — **as the system**: logged `source = 'cron'`; the owner may turn it off (`reminder_days = {}`) |
| `receipts` | hourly | the K7 read `gl.invoice_receipts` (`POST {OS_INTERNAL_URL}/api/v1/apps/read.php` with `provider: gl`) for the last 60 days → `record_payment(source = 'ledger')` once per (`reference`, `received_on`) not yet recorded, by `external_ref` = our number; `external_ref` set when the ledger names one; 403 `no_connection` → the step says so once a day and does nothing |
| `reimbursements` | hourly | the K7 read `hr.reimbursements_paid` → `ct_expense_mark_reimbursed()` per `expense_number` with the pay run's reference (H2); `no_connection` likewise |
| `claims` | daily | `payment_claims` submitted over 14 days ago → `claim_submitted` again to billing (a reminder to decide) |
| `links` | daily | `invoice_links_secure.expires_at` set to `paid_at + link_lifetime_days` when the invoice is paid and none is set; expired links answer the one page |
| `dispatch` | minute | `agent_dispatches` in `failed` with attempts < 3 retried through the chat endpoint (K8 later) |
| `housekeeping` | daily | `sso_nonces` older than a day, `member_sessions` ended over 90 days ago, outbox rows sent over 180 days ago (bodies blanked, rows kept) |

`CT_WORKER_NOW` (ISO 8601) replaces now() in the steps' selections outside production (the proofs); the writes stamp the real time.

## The exports (`/exports`, `exports.download`)
| Export | Document | Rows |
|---|---|---|
| Invoices issued | `os.ct-invoices/1` | as the tool surface says, one row per invoice, lines by kind |
| Credit notes | `os.ct-credit-notes/1` | |
| Expenses by category | `os.ct-expenses/1` | company-card, approved, by category and month |
| Reimbursements | `os.ct-reimbursements/1` | approved personal-card, unreimbursed, per person (`billing.write`) |
| WIP | `os.ct-wip/1` | `ct_unbilled()` as of a date |
| Time detail | `os.ct-time/1` | approved entries in a period: date, person, engagement, hours, billable, bill value (`rates`), note — no cost |
| Usage detail | `os.ct-usage/1` | charges in a period: engagement, provider, kind, quantity, unit, cost (`cost`), billable, billed |
CSV or JSON; `export.download` logged (export, from, to, rows); the CSV's cells through one presenter.

## The shares (query functions here; the tools in Phase 4)
`share_invoices_issued(PDO, from, to, ?client)`, `share_credit_notes_issued(...)`, `share_expenses_by_category(...)`, `share_unbilled_summary(as_of, ?engagement, ?client)`,
`share_reimbursements_approved(?as_of, ?member)`, `share_engagement_list(?status, ?client)` — each returns the versioned document of the tool surface; each logs `share.read`
when called by the kernel's token (the server does; the function takes `caller`). The exports page calls the same functions.

## Files (exactly these)
- `bin/worker.php` · `app/features/worker/{steps,outbox,cadence,statement,reads}.php` · `app/features/notify/{kinds,queue,body,send}.php` (the ONE queueing function `notify()` and `outbox_send_batch()`; the bodies from `writing-to-clients` for client mail)
- `html/exports/index.php` · `download.php` · `app/features/exports/{queries,present,documents}.php` (the six share functions + time and usage detail) · `app/views/exports/index.php`
- `html/billing/morning-note.php` (`morning_note_send`: the Clerk's note → `notifications` + outbox to the settings' recipient; `morning_note.send`)
- `html/notifications.php` made real (the bell's list, read marks) · `app/views/shared/bell.php`
- `tests/fake_malumail.php` (Phase 2's), the fake kernel's `/api/v1/apps/read.php` and `/api/v1/notify/sms.php` states

## Query functions (signatures fixed)
- `notify(PDO, int $memberId, string $kind, ?string $recordType, ?int $recordId, string $title, ?string $body, array $opts = []): int` (the `notifications` row + an outbox row per channel the prefs allow; `dedupe`) · `notify_client(PDO, int $clientId, array $toEmails, string $kind, string $recordType, int $recordId, string $subject, string $body, string $bodyHtml): int` · `outbox_send_batch(PDO, int $limit): array`
- `nudge_pass(PDO, string $now): int` · `cadence_pass(PDO, string $now): array` · `statement_pass(PDO, string $now): array` · `schedules_pass(...)` · `budgets_pass(...)` · `reminders_pass(...)` · `receipts_pass(...)` · `reimbursements_pass(...)` · `claims_pass(...)` · `links_pass(...)` · `dispatch_retry_pass(...)` · `housekeeping_pass(...)`
- `kernel_read(PDO, string $provider, string $tool, array $arguments): array` (K7; `['status', 'result' | 'error']`; logs `share.read` with `provider`, `tool`, keys)
- `export_document(PDO, string $export, string $from, string $to, array $opts): array` · `document_csv(array $doc): string`
- `send_morning_note(PDO, int $by, string $body, ?int $toMember): int`

## Action manifest entries
Screens: `exports`, `notifications` (real). Actions: `export_download`, `morning_note_send`. The worker's writes are logged `source = 'cron'`, no action.

## Activity log events
`worker.pass`, `notification.send|skip|fail` (kind, channel, code — never a body), `timesheet.nudge` (cron), `invoice.draft` (cron, `after.via = cadence`), `ai_statement.pull|allocate` (cron), `recurring_charge.run` (cron), `budget_alert.write` (cron), `invoice.remind` (cron), `payment.record` (`source = ledger`), `expense.reimburse` (`via = hr`), `share.read`, `export.download`, `morning_note.send`.

## Out of scope for this slice
The MCP servers themselves (Phase 4); K8 wakes (owed); the magic-link page; inbound mail.

## Proof (`tests/phase3/slice8/run.sh`; `CT_WORKER_NOW` drives the clock; the fake kernel's K6, K7 and ledger states; the fake MaluMail)
- [x] The outbox: an email sent (the fake logs it with the link), a text through K6 (`ok`), a text refused `no_sender` skipped with the email still sent, five failures → `failed`.
- [x] Monday: the nudges for everyone but Dana, one each, texts to those who chose; Tuesday: none; the dedupe holds across passes.
- [x] Cadence: Acme's billing date passed → one draft with the unbilled items, the date advanced a month, the manager and billing told; a second pass makes no second draft; Beta's accepted milestone → a draft.
- [x] The statement: the fake's closed month pulled and allocated on the hour, billing told with the remainder; the open month ignored.
- [x] Schedules and budgets: the due schedule run once; the AI alert written once.
- [x] Reminders: an invoice 7 days past due reminded (`source = cron`), not again within a week; `reminder_days = {}` turns it off.
- [x] Receipts (G1): the fake ledger's receipt for INV-00001 → a payment `source = ledger`, the invoice partially paid, `external_ref` set; the same receipt again → nothing; `no_connection` → one note, nothing changed. Reimbursements (H2): HR's paid list marks EXP-000001 with the pay run's reference.
- [x] Links: a paid invoice's link expires `link_lifetime_days` later and then answers the one page.
- [x] Exports: the seven documents as CSV and JSON for a period equal the tool surface's shapes; `export.download` logged; a Consultant → 403.
- [x] The morning note lands in the bell and the outbox of the settings' recipient; the Clerk's run token may send it (no pause).
- [x] Housekeeping leaves the checkpoints and counts alone; `worker.pass` once per pass with the counts.

**Built and proven 2026-10-05: 379 checks green** (`tests/phase3/slice8/run.sh`: outbox 38, nudge 18, cadence 18, statement 14, schedules 16, reminders 15, claims 7, receipts 20, links 13, dispatch 13, exports 44, shares 34, morning 23, json 17, units 28, worker 18, browser 43); slices 1–7 and Phase 2 stay green; the registry reads 78 screens (76 built — `agents` and `connections` are slice 9's) and 108 actions built. The fakes: the kernel's K6 SMS (the 30-a-day limit now counted) and a new K7 `/api/v1/apps/read.php` (state `reads`, request log `.reads`), the MaluMail fake's `flakyonce`.

**Decisions taken while building**
- Every pass takes the clock as an ISO string; rhythm (minute / hourly / daily from 06:00 firm time) is kept by the `worker.pass` rows' own `at` stamp; `--steps=` runs the named steps whether due or not; `CT_WORKER_NOW` only outside `APP_ENV=prod`.
- The views are read as the firm's administrator (lowest-id live human super-admin); every row written is `source = cron`, actor none. The kernel's token on a share is read the same way for the length of the call and put back; the function logs `share.read` (source application) itself.
- Dedupe keys of the earlier slices live on OUTBOX rows, so a person with every channel off had no dedupe: the worker's nudges, overdue notices and claim reminders therefore also keep their record in the log (`timesheet.nudge`, `invoice.overdue`, `payment_claim.age_notice`).
- Retry: 2, 4, 8, 16 minutes, the fifth failure final; a skipped text (any code the kernel names, `rate_limited` included) is never retried; a client row without an address, a suppressed address and a person who switched the channel off since are skipped.
- Cadence: a draft through the billing date (period from the day after the last invoice), the date moved on a cadence step until after today; no unbilled work = the date moves, no draft; an open draft = left alone; `on_milestone` = a draft through today. The notices copy the button's dedupe keys.
- Reminders: one reminder per pass while those sent number fewer than the thresholds crossed (`reminder_days`) and none in 7 days; `invoice_overdue` once per threshold, to billing and the manager.
- `no_connection` is logged once a day per (provider, tool) as `share.read`; "says so" = that row, no bell notice (no notification kind fits).
- Receipts match by invoice NUMBER (`external_ref` of the row); a receipt already recorded, even if a person voided the payment, is never recorded again; G1's row may carry `ledger_ref`, which becomes the invoice's `external_ref`.
- `unbilled_summary` for a person needs `invoices.draft|reports.view|billing.write` (tighter than the surface's "staffed": it carries bill value). Money in the documents is always two decimals.
- A dispatch with a run already begun is looked at (GET ?run=), never re-posted.
- Added log events: `invoice.overdue`, `payment_claim.age_notice`, `invoice.link_expiry_set`, `agent.dispatch`, `engagement.update` (cadence dates). The home's Clerk region is Billing's (`billing.write`); the earlier slices' `$by` parameters (create_invoice, send_invoice, record_payment, run_schedule, pull/allocate, mark_reimbursed, set_external_ref) became nullable for the system.

## Open questions
- **Delivery is at-least-once.** A mail whose answer is lost (timeout) or a crash after the relay accepted is retried and could reach a client twice. Should an invoice or reminder to a client be at-most-once instead (a lost answer = skipped, a person resends)?
- **Dispatch retry.** A turn the kernel may have started but never answered, with no run id yet, is re-posted with the same conversation id; the kernel has no idempotency key, so the agent could run it twice (a busy agent is refused 409, which limits it). Wait for K8/K16, or accept?
- **G1 / H2 row shapes** (unbuilt siblings): assumed `external_ref`, `received_on`, `amount`, `currency`, `method`, `reference` (+ optional `ledger_ref`) and `expense_number`, `reference`. Confirm when GL and HR build them.
