# Consultant Tracking Action Manifest

2026-10-05 · **Phase 1, for the checkpoint**

> The registry the command bar (through the kernel's chat endpoint), the kernel's Actions MCP and the screens resolve against.
> A screen or action missing here cannot be reached by voice or by an agent — unfinished design, like a question with no tool.
> Pairs with `db/` (tables), `docs/consultant-tracking-mcp-tool-surface.md` (read tools) and the slice specs in `docs/build-specs/`.
> Generated into `mcp/action_registry.json` by `bin/build_action_registry.php`; an action whose file does not exist yet is
> registered but not exposed.

## Conventions

### Screens
- **Ids** are kebab-case `{entity}-list`, `{entity}-add`, `{entity}-view`, `{entity}-edit`, plus named screens.
- **Canonical URLs** need the rewrites in `deploy/apache-consultant_tracking.conf`: `/{x}/new` → `form.php`, `/{x}/{id}/edit` →
  `form.php?id=`, `/{x}/{id}` → `view.php?id=`, `/{x}/{id}/{page}` → `{page}.php?id=`, `/reports/{report}` → `report.php?report=`.
  Every consultant screen is designed at 375 px first; every screen is a page, never a modal — the one exception is the record picker
  (below), a chooser that shows no record and submits no form.
- **The record picker** (2026-10-09, `docs/build-specs/record-picker.md`): `GET /pick/?source=&q=&page=&<declared params>` → `html/pick/index.php`,
  a fragment (the picker's rows), not a screen and not an action: nothing is logged and no tool is generated for it. `app/pickers.php`
  names the sources (`client`, `client_live`, `engagement`, `engagement_draftable`, `engagement_loggable`, `engagement_managed`,
  `member`), the rights that admit a caller and their filter params. A picker field keeps the select's `name` and `id` on a hidden input,
  so every action row below is unchanged.
- **Prefill params** become query-string values the GET controller reads.
- **Every screen partial stamps** `data-screen`, `data-entity`, `data-record-id` on `#page-content`, so "that", "this engagement"
  and "approve it" resolve against the page the user is on.
- **The public door** (`/i/{token}`, its `/claim`, `/print` and `/attachment/{id}`) is a screen with no session; its POST is **not an
  action** (no agent reaches it) and is listed apart at the end.

### Actions
- **Name** `{entity}_{verb}`; the **log event** `{entity}.{verb}` is what the handler writes with `log_activity()` (with `engagement_id`,
  `client_id`, `invoice_id`, `timesheet_id` as the record says) and what the kernel's approval policies match — so an action an agent's
  call must pause on has **its own log event**. `.delete` destroys a record; `.remove` detaches something; `.archive` keeps it out of the way.
- **Endpoints** are POST to `/{base}/{file}` with `require_post()`, `verify_csrf()` (or the relayed action token), the right, the record's
  visibility (`require_can_see_engagement()`) or management (`require_can_manage_engagement()`), and `log_activity()`. **A status move
  calls the database's verb** (`ct_timesheet_approve()`, `ct_invoice_issue()`, …) inside `ct_guard()` and shows its sentence; PHP never
  re-implements a rule. Handlers report through `emit_action_status()`; a create's `location` ends in the new record's id.
- **Params:** **bold** = required; `[]` = repeated; parentheses = a hint (no comma or semicolon inside; no note after the last
  parameter). A param named for a record (`client`, `engagement`, `milestone`, `timesheet`, `entry`, `expense`, `charge`, `invoice`,
  `credit_note`, `claim`, `rate_card`, `role`, `activity`, `category`, `provider`, `rule`, `schedule`, `statement`, `member`, `department`)
  accepts an id or a name, resolved through the application's own tool (`docs/consultant-tracking-mcp-tool-surface.md`, resolution
  table); an `engagement` or an `invoice` also accepts its number. Dates are `YYYY-MM-DD`; hours are decimal in the settings' increment;
  money is decimal with the record's currency. `any field of X` = X's fields, all optional, a partial update.
- **Who** is enforced in the endpoint: `own` = the record's own person; `staffed` = may see the engagement; `manage` = manages the
  engagement (`ct_can_manage_engagement()`); `approve` = `time.approve` / `expenses.approve` / `usage.approve` AND manage; `billing` =
  `billing.write`; `draft` = `invoices.draft` AND manage; `usage` = `usage.write` AND manage; `clients` = `clients.manage`; `cards` =
  `rate_cards.manage`; `admin` = `settings.manage`. A super-admin holds every right. An agent is granted like a person.
- **Undo** is the inverse `undo_last` applies; — means it cannot be undone and the reply says so.
- **Confirm (✔)** = the command bar asks first (destructive, or it reaches other people or money).
- **Agent approval** names the kernel's category that pauses the action when an **agent** performs it: `money_out` (a claim on money made
  true, forgiven or changed — issuing, voiding, writing off, crediting, recording payment, writing off time), `external_send` (words leave —
  an invoice, a reminder, a statement, a nudge), `deletion`, `other` (the firm's controls — approvals of time, expenses, usage, milestones —
  and configuration). People are never paused. The categories are exactly `maludb-os.json` `approvals[]`.
- **Refresh:** a data action returns `HX-Trigger: {entity}Changed` (camelCase entity).
- **Log payloads** (what `before`/`after` must carry) are in the tool surface; **a cost rate, a secure token, a client's address, a
  person's phone or an internal note beyond 200 characters is never in a payload.**

### Result contract
```json
{"status": "success", "did": "Approved Priya's week of Mar 2 (3 entries, 9.5 h)", "record_id": 17, "undo_id": "act_31", "refresh": "timesheetChanged"}
{"status": "pending_approval", "did": "Issuing INV-00042 waits for a person's approval", "approval_request_id": 17}
{"status": "error", "message": "A receipt is required at or above 75.00 — attach one to submit EXP-000123."}
```

## Home, settings and tokens (Phase 2 and slice 9)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `dashboard` | `/` | home: my week and timer, my reimbursements due; for a manager the approvals waiting and the budget alerts; for billing WIP, drafts, invoices due to draft, the aging, the OS statement's state, the Clerk's last note |
| `notifications` | `/notifications` | what the bell holds, and to mark it read |
| `activity` | `/activity` | their own trail, or a record's (params: `engagement`, `invoice`, `timesheet`) |
| `settings` | `/settings/` | how they are told (email, text, which kinds) |
| `tokens` | `/settings/tokens/` | their own MCP tokens: mint one, revoke one |
| `settings-general` | `/settings/general` | the firm's settings: currency, week start, increment, receipt threshold, markup default, terms, grouping, link lifetime, reminders, thresholds, capacity default, the morning-note recipient |
| `numbering` | `/settings/numbering` | the prefixes and next numbers (ENG, INV, CN, EXP, USG) |
| `agents` | `/settings/agents` | which agents work here (the expert, the Billing Clerk), their last runs — read-only; hiring and grants link to the kernel |
| `connections` | `/settings/connections` | which sibling applications read what of ours and what we read — the kernel's facts, read-only |

Actions (base `/settings/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `prefs_save` | `prefs.php` | email_enabled (yes or no), text_enabled (yes or no), kinds[], text_kinds[] | restore prior | | | `prefs.save` | own |
| `notification_read` | `notifications/read.php` | notification (empty marks all) | — | | | `notification.read` | own |
| `token_mint` | `tokens/mint.php` | **label**, scope (mcp or api) | token_revoke | | | `token.mint` | own |
| `token_revoke` | `tokens/revoke.php` | **token** | — | ✔ | | `token.revoke` | own |
| `settings_save` | `general.php` | firm_name, firm_address, firm_tax_id, base_currency, timezone, week_start_dow, hours_increment, max_hours_per_day, receipt_threshold, default_markup_pct, default_terms_days, default_time_grouping, share_receipts_default, link_lifetime_days, reminder_days[], budget_thresholds_pct[], ai_budget_threshold_pct, capacity_hours_per_week, ai_statement_poll, morning_note_member, missing_time_nudge_dow, invoice_footer | restore prior | | other | `settings.save` | admin |
| `numbering_save` | `numbering.php` | **kind** (engagement or invoice or credit_note or expense or usage_charge), prefix, next_value, padding | restore prior | ✔ | other | `numbering.save` | admin |

## Clients, rates and the vocabulary (slice 1)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `client-list` | `/clients/` | the clients as cards with active engagements and open balance (params: `q`, `archived`) |
| `client-add` | `/clients/new` | to add a client |
| `client-view` | `/clients/{id}` | one client: contacts, engagements, open invoices, the statement, the claims, documents, the timeline |
| `client-edit` | `/clients/{id}/edit` | to change a client's details, terms, currency, PO rule, default rate card |
| `client-statement` | `/clients/{id}/statement` | the client's statement for a period, with Send (params: `from`, `to`) |
| `role-list` | `/settings/roles/` | the consulting roles (titles a rate is priced by) |
| `rate-card-list` | `/settings/rate-cards/` | the rate cards as cards |
| `rate-card-add` | `/settings/rate-cards/new` | to add a rate card (optionally copied from another) (params: `copy_of`) |
| `rate-card-view` | `/settings/rate-cards/{id}` | one card: the rate per consulting role, the engagements using it |
| `rate-card-edit` | `/settings/rate-cards/{id}/edit` | to rename a card or change its effective date |
| `cost-rate-list` | `/settings/cost-rates/` | every person's cost rate by date range (admin, Finance) |
| `capacity-list` | `/settings/capacity/` | every person's capacity by date range |
| `activity-type-list` | `/settings/activity-types/` | the activity types and their billable default |
| `category-list` | `/settings/categories/` | the expense categories with receipt and markup overrides |
| `provider-list` | `/settings/providers/` | the usage providers and their saved CSV mappings |
| `tax-rate-list` | `/settings/tax-rates/` | the tax rates |

Actions (base `/clients/`, the vocabulary under `/settings/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `client_save` | `/clients/save.php` | **name**, legal_name, code (2 to 8 capitals), email, phone, billing_address, tax_id, terms_days, currency (3 letters), po_required (yes or no), rate_card (the default card), notes | client_archive | | | `client.create` | clients |
| `client_update` | `/clients/save.php` | **client**, any field of client_save | restore prior | | | `client.update` | clients |
| `client_archive` | `/clients/archive.php` | **client** | client_restore | ✔ | | `client.archive` | clients |
| `client_restore` | `/clients/restore.php` | **client** | client_archive | | | `client.restore` | clients |
| `client_delete` | `/clients/delete.php` | **client** (only one with no engagement) | — | ✔ | deletion | `client.delete` | admin |
| `client_contact_save` | `/clients/contacts/save.php` | **client**, **name**, email, phone, role, receives_invoices (yes or no), is_primary (yes or no), contact (to change an existing one) | client_contact_remove | | | `client_contact.save` | clients |
| `client_contact_remove` | `/clients/contacts/remove.php` | **contact** | — | ✔ | | `client_contact.remove` | clients |
| `statement_send` | `/clients/statement-send.php` | **client**, from, to, to_email (else the contacts who receive invoices) | — | ✔ | external_send | `client.statement_send` | billing |
| `consulting_role_save` | `/settings/roles/save.php` | **title**, key (lowercase), description, sort_order, role (to change an existing one) | restore prior | | other | `consulting_role.save` | admin |
| `consulting_role_archive` | `/settings/roles/archive.php` | **role** | restore prior | ✔ | other | `consulting_role.archive` | admin |
| `rate_card_save` | `/settings/rate-cards/save.php` | **name**, **currency** (3 letters), effective_from, description, copy_of (a card whose rates are copied), rate_card (to change an existing one) | rate_card_delete | | other | `rate_card.save` | cards |
| `rate_card_rate_save` | `/settings/rate-cards/rate.php` | **rate_card**, **role**, **bill_rate** (per hour) | restore prior | | other | `rate_card.rate` | cards |
| `rate_card_archive` | `/settings/rate-cards/archive.php` | **rate_card** | restore prior | ✔ | other | `rate_card.archive` | cards |
| `rate_card_delete` | `/settings/rate-cards/delete.php` | **rate_card** (only one no engagement or client uses) | — | ✔ | deletion | `rate_card.delete` | admin |
| `cost_rate_save` | `/settings/cost-rates/save.php` | **member**, **hourly_cost** (in the base currency), **valid_from**, valid_to, note, cost_rate (to change an existing range) | restore prior | ✔ | other | `cost_rate.save` | admin |
| `cost_rate_end` | `/settings/cost-rates/end.php` | **cost_rate**, **valid_to** | restore prior | | other | `cost_rate.end` | admin |
| `capacity_save` | `/settings/capacity/save.php` | **member**, **hours_per_week**, **valid_from**, valid_to, capacity (to change an existing range) | restore prior | | other | `capacity.save` | admin |
| `activity_type_save` | `/settings/activity-types/save.php` | **label**, key, billable_default (yes or no), sort_order, activity (to change an existing one) | restore prior | | other | `activity_type.save` | admin |
| `activity_type_archive` | `/settings/activity-types/archive.php` | **activity** | restore prior | ✔ | other | `activity_type.archive` | admin |
| `expense_category_save` | `/settings/categories/save.php` | **label**, key, receipt_required_above, default_markup_pct, billable_default (yes or no), sort_order, category (to change an existing one) | restore prior | | other | `expense_category.save` | admin |
| `expense_category_archive` | `/settings/categories/archive.php` | **category** | restore prior | ✔ | other | `expense_category.archive` | admin |
| `usage_provider_save` | `/settings/providers/save.php` | **name**, provider_key (lowercase), csv_mapping (JSON), sort_order, provider (to change an existing one) | restore prior | | other | `usage_provider.save` | admin |
| `tax_rate_save` | `/settings/tax-rates/save.php` | **name**, **rate** (percent), is_default (yes or no), tax_rate (to change an existing one) | restore prior | | other | `tax_rate.save` | admin |
| `tax_rate_archive` | `/settings/tax-rates/archive.php` | **tax_rate** | restore prior | ✔ | other | `tax_rate.archive` | admin |

## Engagements (slice 2)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `engagement-list` | `/engagements/` | the engagements as cards with budget bars, by status (params: `q`, `client`, `kind`, `status`, `department`, `manager`, `mine`) |
| `engagement-add` | `/engagements/new` | to add an engagement for a client (params: `client`) |
| `engagement-view` | `/engagements/{id}` | one engagement: overview with budget status, staffing, rates, milestones, time, expenses, usage, invoices, documents, timeline (params: `tab`) |
| `engagement-edit` | `/engagements/{id}/edit` | to change an engagement's terms, dates, budgets, cadence, policy, grouping, manager, practice |
| `engagement-staffing` | `/engagements/{id}/staffing` | who is staffed, in what role, at what override; add and end a staffing |
| `engagement-rates` | `/engagements/{id}/rates` | the role rates on this engagement overriding the card; what each person resolves to today |
| `engagement-milestones` | `/engagements/{id}/milestones` | the milestones: add, deliver, accept, cancel |
| `budget-alerts` | `/usage/budgets` | every active engagement's budgets and burn, and the alerts to acknowledge |

Actions (base `/engagements/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `engagement_save` | `save.php` | **client**, **name**, **kind** (time_and_materials or fixed_bid), start_date, end_date, currency (3 letters or the client's), po_number, rate_card, nte_amount (T&M), budget_hours, budget_amount, ai_budget_amount, markup_pct (the expense policy), requires_receipt_above, billing_cadence (weekly or biweekly or monthly or on_milestone or manual), time_grouping (by_person or by_role or by_activity or by_day or single_line), share_receipts_with_client (yes or no), department (the practice), manager, projects_ref, description, start_now (yes activates it) | engagement_cancel | | | `engagement.create` | manage (engagements.manage) |
| `engagement_update` | `save.php` | **engagement**, any field of engagement_save | restore prior | | | `engagement.update` | manage |
| `engagement_activate` | `activate.php` | **engagement** | engagement_hold | | | `engagement.activate` | manage |
| `engagement_hold` | `hold.php` | **engagement**, reason | engagement_activate | | | `engagement.hold` | manage |
| `engagement_close` | `close.php` | **engagement**, reason | engagement_reopen | ✔ | | `engagement.close` | manage |
| `engagement_reopen` | `reopen.php` | **engagement**, reason | engagement_close | | | `engagement.reopen` | manage |
| `engagement_cancel` | `cancel.php` | **engagement**, **reason** | — | ✔ | | `engagement.cancel` | manage |
| `engagement_delete` | `delete.php` | **engagement** (a draft only) | — | ✔ | deletion | `engagement.delete` | manage |
| `engagement_member_save` | `staffing/save.php` | **engagement**, **member**, **role** (a consulting role), valid_from (today), valid_to, bill_rate_override (per hour), note, staffing (to change an existing one) | engagement_member_end | | | `engagement.staff` | manage (staffing.write) |
| `engagement_member_end` | `staffing/end.php` | **staffing**, valid_to (today) | — | ✔ | | `engagement.unstaff` | manage |
| `engagement_role_rate_save` | `rates/save.php` | **engagement**, **role**, **bill_rate** (per hour; empty removes the override) | restore prior | | other | `engagement.role_rate` | manage |
| `milestone_save` | `milestones/save.php` | **engagement**, **name**, amount, due_date, description, sort_order, milestone (to change an existing one) | milestone_delete | | | `milestone.save` | manage (milestones.write) |
| `milestone_deliver` | `milestones/deliver.php` | **milestone**, note | restore prior | | | `milestone.deliver` | manage |
| `milestone_accept` | `milestones/accept.php` | **milestone**, **acceptance_note** (who at the client accepted and how), accepted_by (a member; default the caller), file (multipart evidence) | — | ✔ | other | `milestone.accept` | manage |
| `milestone_cancel` | `milestones/cancel.php` | **milestone**, **reason** | — | ✔ | | `milestone.cancel` | manage |
| `milestone_delete` | `milestones/delete.php` | **milestone** (planned and never accepted) | — | ✔ | deletion | `milestone.delete` | manage |
| `budget_alert_write` | `/usage/budgets/check.php` | engagement (empty checks every active one) | — | | | `budget_alert.write` | manage or billing |
| `budget_alert_acknowledge` | `/usage/budgets/acknowledge.php` | **alert** | — | | | `budget_alert.acknowledge` | manage |
| `attachment_add` | `attachments/save.php` | **record_type** (client or engagement or milestone or expense or usage_charge or invoice or credit_note), **record** (its id), **file** (multipart), client_visible (yes or no) | attachment_delete | | | `attachment.add` | the record's writer |
| `attachment_delete` | `attachments/delete.php` | **attachment** | — | ✔ | deletion | `attachment.delete` | the record's manager |
| `note_add` | `notes/save.php` | **record_type**, **record**, **body** | note_delete | | | `note.add` | the record's viewer |
| `note_delete` | `notes/delete.php` | **note** | — | ✔ | deletion | `note.delete` | own or admin |

## Time — THE EXEMPLAR (slice 3)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `timesheet` | `/time/` | my week: the grid of days and engagements, the timer bar, add an entry, Submit; a rejected week's reason; another week (params: `week`, `member`) |
| `time-entry-add` | `/time/entries/new` | to log an entry (params: `engagement`, `date`, `week`) |
| `time-entry-edit` | `/time/entries/{id}/edit` | to change a draft or rejected entry |
| `time-approvals` | `/time/approvals` | the submitted weeks awaiting me, by person; open one, approve or reject (params: `member`) |
| `timesheet-view` | `/time/weeks/{id}` | one week as its manager sees it: the entries with rates, approve or reject, the timeline |
| `team-time` | `/time/team` | everyone's hours per day for a week, missing days marked (params: `week`, `department`) |
| `missing-time` | `/time/missing` | who has not submitted a week and which days are empty (params: `week`) |
| `written-off` | `/time/written-off` | the hours written off, with reasons (params: `from`, `to`, `engagement`) |

Actions (base `/time/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `time_entry_save` | `entries/save.php` | **engagement**, **date**, **hours** (in the increment), activity (an activity type), billable (yes or no; the activity's default), note (client-visible), internal_note, member (a manager logging for someone), entry (to change an existing one) | time_entry_delete | | | `time_entry.save` | own or manage |
| `time_entry_delete` | `entries/delete.php` | **entry** (a draft or rejected one) | — | ✔ | deletion | `time_entry.delete` | own or manage |
| `timer_start` | `timer/start.php` | **engagement**, activity, note | timer_stop | | | `timer.start` | own |
| `timer_stop` | `timer/stop.php` | — | — | | | `timer.stop` | own |
| `timesheet_submit` | `weeks/submit.php` | week (its Monday or a timesheet; this week by default), member (a manager submitting for someone) | timesheet_reopen | ✔ | | `timesheet.submit` | own or manage |
| `timesheet_reopen` | `weeks/reopen.php` | **timesheet** (submitted and nothing approved yet) | timesheet_submit | | | `timesheet.reopen` | own or manage |
| `timesheet_approve` | `weeks/approve.php` | **timesheet** (approves the entries on the engagements the approver manages) | — | ✔ | other | `timesheet.approve` | approve |
| `timesheet_reject` | `weeks/reject.php` | **timesheet**, **reason** | — | ✔ | other | `timesheet.reject` | approve |
| `time_entry_write_off` | `entries/write-off.php` | **entry** (approved and unbilled), **reason** | — | ✔ | money_out | `time_entry.write_off` | manage |
| `timesheet_nudge_send` | `weeks/nudge.php` | **member**, week (last week by default) | — | ✔ | external_send | `timesheet.nudge` | approve or billing |

## Expenses and receipts (slice 4)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `expense-list` | `/expenses/` | my expenses as cards by status with the receipt flag and the reimbursement state (params: `status`, `engagement`, `member`) |
| `expense-add` | `/expenses/new` | to record an expense with the camera first (params: `engagement`, `date`) |
| `expense-view` | `/expenses/{id}` | one expense: the receipt, the money, the status and reasons, approve or reject, the timeline |
| `expense-edit` | `/expenses/{id}/edit` | to change a draft or rejected expense |
| `expense-approvals` | `/expenses/approvals` | submitted expenses awaiting me, the receipt beside the amount (params: `engagement`, `missing_receipt`) |
| `reimbursements` | `/expenses/reimbursements` | what each person is owed; mark reimbursed by hand until HR reads it (params: `member`) |
| `company-card` | `/expenses/company-card` | company-card spend by category and month (params: `from`, `to`) |

Actions (base `/expenses/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `expense_save` | `save.php` | **engagement**, **date**, **category**, **amount** (gross), **description**, tax_amount, vendor_name, paid_with (personal or company_card), billable (yes or no), markup_pct (the policy's unless said), member (a manager recording for someone), file (multipart receipt), expense (to change an existing one) | expense_delete | | | `expense.save` | own or manage |
| `expense_delete` | `delete.php` | **expense** (a draft or rejected one) | — | ✔ | deletion | `expense.delete` | own or manage |
| `expense_submit` | `submit.php` | **expense** (refused without a receipt at or above the threshold) | — | | | `expense.submit` | own or manage |
| `expense_approve` | `approve.php` | **expense** | expense_reject | ✔ | other | `expense.approve` | approve |
| `expense_reject` | `reject.php` | **expense**, **reason** | — | ✔ | other | `expense.reject` | approve |
| `expense_mark_reimbursed` | `reimburse.php` | **expense** (or expenses[] for one person), reference (the pay run or payment) | — | ✔ | other | `expense.reimburse` | billing |

## AI usage and hosting (slice 5)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `usage-list` | `/usage/` | the usage charges by engagement, period, kind, source, status (params: `engagement`, `from`, `to`, `kind`, `source`, `status`) |
| `usage-add` | `/usage/new` | to record a usage charge with its pricing and the provider's invoice (params: `engagement`) |
| `usage-view` | `/usage/{id}` | one charge: quantity, cost, pricing, billable, its source (import, schedule, statement line), approve or void, the timeline |
| `usage-edit` | `/usage/{id}/edit` | to change a draft charge |
| `usage-import` | `/usage/import` | a provider's CSV usage export: the mapping, a five-row preview, duplicates counted, then import (params: `provider`, `engagement`) |
| `os-statement` | `/usage/os` | the kernel's months as it reports them: pull, allocate, the lines with their remainders, the charges made (params: `period`) |
| `allocation-rule-list` | `/settings/allocation-rules/` | the rules that place the OS statement on engagements |
| `allocation-rule-add` | `/settings/allocation-rules/new` | to add a rule (params: `engagement`) |
| `allocation-rule-edit` | `/settings/allocation-rules/{id}/edit` | to change a rule |
| `schedule-list` | `/settings/schedules/` | the hosting schedules: next date, last run, run now (params: `engagement`) |
| `schedule-add` | `/settings/schedules/new` | to add a schedule (params: `engagement`) |
| `schedule-edit` | `/settings/schedules/{id}/edit` | to change a schedule |

Actions (base `/usage/`, the rules and schedules under `/settings/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `usage_charge_draft` | `/usage/save.php` | **engagement**, **kind** (model_usage or api or hosting or storage or other), **description**, **period_start**, **period_end**, provider, quantity, unit (tokens or calls or gb or gb_month or vm_hours or months or units), unit_cost, pricing (cost or markup or resale), markup_pct, resale_unit_price (per million tokens), billable (yes or no), file (multipart; the provider's invoice), charge (to change a draft) | usage_charge_delete | | | `usage_charge.draft` | usage |
| `usage_charge_delete` | `/usage/delete.php` | **charge** (a draft) | — | ✔ | deletion | `usage_charge.delete` | usage |
| `usage_charge_approve` | `/usage/approve.php` | **charge** | usage_charge_void | ✔ | other | `usage_charge.approve` | approve (usage.approve) |
| `usage_charge_void` | `/usage/void.php` | **charge** (unbilled), **reason** | — | ✔ | | `usage_charge.void` | usage |
| `usage_import` | `/usage/import.php` | **provider**, **engagement**, **file** (multipart CSV), mapping (JSON; the provider's saved one by default), save_mapping (yes remembers it) | usage_import_delete | ✔ | | `usage_import.run` | usage |
| `usage_import_delete` | `/usage/import-delete.php` | **import** (removes its draft charges) | — | ✔ | deletion | `usage_import.delete` | usage |
| `ai_statement_pull` | `/usage/os/pull.php` | period (a month; every closed month not yet pulled by default) | — | | | `ai_statement.pull` | usage |
| `ai_statement_allocate` | `/usage/os/allocate.php` | **statement** (a closed month) | — | ✔ | | `ai_statement.allocate` | usage |
| `allocation_rule_save` | `/settings/allocation-rules/save.php` | **name**, **engagement**, agent (a kernel agent id), department (a kernel department id), application (a kernel application id), share_pct (100), pricing (cost or markup or resale), markup_pct, resale_unit_price, valid_from, valid_to, active (yes or no), rule (to change an existing one) | allocation_rule_delete | | other | `allocation_rule.save` | usage |
| `allocation_rule_delete` | `/settings/allocation-rules/delete.php` | **rule** | — | ✔ | deletion | `allocation_rule.delete` | usage |
| `recurring_charge_save` | `/settings/schedules/save.php` | **engagement**, **kind**, **description**, **unit_cost**, **next_date**, provider, quantity, unit, pricing, markup_pct, resale_unit_price, cadence (weekly or monthly or quarterly or yearly), every_n, end_date, active (yes or no), schedule (to change an existing one) | recurring_charge_delete | | other | `recurring_charge.save` | usage |
| `recurring_charge_run` | `/settings/schedules/run.php` | **schedule** (due) | — | | | `recurring_charge.run` | usage |
| `recurring_charge_delete` | `/settings/schedules/delete.php` | **schedule** | — | ✔ | deletion | `recurring_charge.delete` | usage |

## Billing (slice 6)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `unbilled` | `/billing/unbilled` | the WIP by engagement and client; Draft invoice from it (params: `as_of`, `client`) |
| `unbilled-items` | `/billing/unbilled/{id}` | the items behind one engagement's WIP |
| `invoice-list` | `/invoices/` | the invoices with status, overdue, period filters and a totals footer (params: `q`, `client`, `engagement`, `status`, `overdue`) |
| `invoice-add` | `/invoices/new` | to draft an invoice for an engagement and a period (params: `engagement`, `from`, `to`) |
| `invoice-view` | `/invoices/{id}` | one invoice: lines, the backing items, payments, credits, claims, the link, the client's preview, the actions by status, the timeline |
| `invoice-edit` | `/invoices/{id}/edit` | the draft's lines editor: regroup, add a fixed or adjustment line, discount, tax; the items listed and removable |
| `invoice-preview` | `/invoices/{id}/preview` | the invoice as the client will see it on the link |
| `due-to-draft` | `/billing/due` | the engagements whose billing date has passed and the accepted milestones not yet invoiced |
| `credit-note-list` | `/credit-notes/` | the credit notes (params: `client`, `status`) |
| `credit-note-add` | `/credit-notes/new` | to draft a credit note against an invoice (params: `invoice`) |
| `credit-note-view` | `/credit-notes/{id}` | one credit note: lines, applications, issue, apply, void |
| `credit-note-edit` | `/credit-notes/{id}/edit` | the draft's lines |
| `payment-list` | `/payments/` | payments recorded, by invoice and client (params: `client`, `from`, `to`) |
| `claims` | `/billing/claims` | what clients said on the link and billing has not decided |
| `ar-aging` | `/reports/ar-aging` | who owes us in five buckets (params: `as_of`) |

Actions (base `/invoices/`, credit notes under `/credit-notes/`, payments under `/payments/`, the rest under `/billing/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `invoice_draft` | `/invoices/save.php` | **engagement**, period_start, period_end, issue_date (today), due_date (the client's terms), po_number, memo (to the client), terms_text, internal_note, add_unbilled (yes pulls the approved unbilled items through the engagement's grouping) | invoice_delete | | | `invoice.draft` | draft |
| `invoice_update` | `/invoices/save.php` | **invoice** (a draft), any field of invoice_draft | restore prior | | | `invoice.update` | draft |
| `invoice_add_unbilled` | `/invoices/add-unbilled.php` | **invoice** (a draft), through (a date; today) | — | | | `invoice.add_unbilled` | draft |
| `invoice_line_save` | `/invoices/lines/save.php` | **invoice** (a draft), **kind** (fixed or adjustment or discount or time or expense or usage), **description**, **unit_price**, quantity (1), unit, tax_rate, sort_order, line (to change an existing one) | invoice_line_remove | | | `invoice.line_save` | draft |
| `invoice_line_remove` | `/invoices/lines/remove.php` | **line** (its backing items return to unbilled) | — | ✔ | | `invoice.line_remove` | draft |
| `invoice_item_remove` | `/invoices/items/remove.php` | **item** (one backing item leaves its line and is unbilled again) | — | ✔ | | `invoice.item_remove` | draft |
| `invoice_delete` | `/invoices/delete.php` | **invoice** (a draft; its items are released) | — | ✔ | deletion | `invoice.delete` | draft |
| `invoice_issue` | `/invoices/issue.php` | **invoice**, nte_override_reason (billing only; when the not-to-exceed would be crossed) | — | ✔ | money_out | `invoice.issue` | billing |
| `invoice_send` | `/invoices/send.php` | **invoice** (issued), to_email (else the contacts who receive invoices), message | — | ✔ | external_send | `invoice.send` | billing |
| `invoice_remind` | `/invoices/remind.php` | **invoice** (past due), message | — | ✔ | external_send | `invoice.remind` | billing |
| `invoice_void` | `/invoices/void.php` | **invoice** (nothing paid or credited), **reason** | — | ✔ | money_out | `invoice.void` | billing |
| `invoice_write_off` | `/invoices/write-off.php` | **invoice**, **reason** | — | ✔ | money_out | `invoice.write_off` | billing |
| `invoice_external_ref_set` | `/invoices/external-ref.php` | **invoice**, **external_ref** (what the ledger calls it) | restore prior | | | `invoice.external_ref` | billing |
| `secure_link_rotate` | `/invoices/secure-link.php` | **invoice** (a new link and the old one dead) | — | ✔ | | `invoice.secure_link_rotate` | billing |
| `payment_record` | `/payments/save.php` | **invoice**, **amount**, paid_on (today), method (transfer or card or cheque or cash or other), reference | payment_void | ✔ | money_out | `payment.record` | billing |
| `payment_void` | `/payments/void.php` | **payment**, **reason** | — | ✔ | money_out | `payment.void` | billing |
| `payment_claim_confirm` | `/billing/claims/confirm.php` | **claim**, amount (the claim's or the balance) | — | ✔ | money_out | `payment_claim.confirm` | billing |
| `payment_claim_dismiss` | `/billing/claims/dismiss.php` | **claim**, reason | — | | other | `payment_claim.dismiss` | billing |
| `credit_note_draft` | `/credit-notes/save.php` | **client** (or the invoice's), invoice (the one it corrects), reason, issue_date, credit_note (to change a draft) | credit_note_delete | | | `credit_note.draft` | billing |
| `credit_note_line_save` | `/credit-notes/lines/save.php` | **credit_note** (a draft), **description**, **unit_price**, kind, quantity (1), tax_rate, line (to change one) | restore prior | | | `credit_note.line_save` | billing |
| `credit_note_delete` | `/credit-notes/delete.php` | **credit_note** (a draft) | — | ✔ | deletion | `credit_note.delete` | billing |
| `credit_note_issue` | `/credit-notes/issue.php` | **credit_note** | — | ✔ | money_out | `credit_note.issue` | billing |
| `credit_note_apply` | `/credit-notes/apply.php` | **credit_note** (issued), **invoice**, **amount** | credit_note_unapply | ✔ | money_out | `credit_note.apply` | billing |
| `credit_note_unapply` | `/credit-notes/unapply.php` | **credit_note**, **invoice** | credit_note_apply | ✔ | money_out | `credit_note.unapply` | billing |
| `credit_note_void` | `/credit-notes/void.php` | **credit_note** (unapplied), **reason** | — | ✔ | money_out | `credit_note.void` | billing |

## Reports (slice 7)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `reports` | `/reports/` | the reports as cards: utilization, realization, unbilled, revenue, margin, budget burn, AI pass-through, expense report, missing time, who owes us |
| `report` | `/reports/{report}` | one report with its filters and a CSV: utilization, realization, wip, revenue, margin, budget-burn, ai-passthrough, expenses, missing-time (params: `from`, `to`, `engagement`, `client`, `member`, `department`, `by`) |
| `exports` | `/exports` | the downloads: invoices issued, credit notes, expenses by category, reimbursements, WIP, time detail, usage detail — CSV and JSON for a period (params: `from`, `to`) |

Actions (base `/exports/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `export_download` | `download.php` | **export** (invoices or credit_notes or expenses or reimbursements or wip or time or usage), **format** (csv or json), from, to | — | | | `export.download` | billing (exports.download) |

## The worker, the doors and the shares (slice 8)

Screens: none of its own — the worker runs `bin/worker.php` every minute (the outbox, Monday's nudges, cadence drafting, the OS statement
poll hourly, schedules falling due, budget checks, reminders falling due, the ledger's receipts read, HR's reimbursements read, claims
ageing, link expiry, dispatch retries); the shares are tools on the records server (the tool surface); `/settings/connections` is slice 9's.

Actions (base `/billing/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `morning_note_send` | `morning-note.php` | **body** (the Clerk's note), to_member (the settings' recipient by default) | — | | | `morning_note.send` | billing or the Clerk |

## Public door (no session; not an action)
`GET /i/{token}` (screen `invoice-secure`): the invoice, its backing detail (hours by day and person with client-visible notes; expenses with
their receipts when the engagement shares them; usage by provider and period; milestones accepted), payments, the balance, Print, and the
button "I paid this on … by …" → `POST /i/{token}/claim` (`payment_claims`, CSRF-protected like the login form's, rate-limited, logged
`payment_claim.submit` with `actor_client_id` and `source = 'portal'`). `GET /i/{token}/print` (the print-ready page), `GET
/i/{token}/attachment/{id}` (a shared receipt, through `client_visible`). The token is the authority; a dead or unknown one answers one
page: "This link has expired. Ask the firm for a new one."

## Size
9 + 16 + 8 + 8 + 7 + 12 + 15 + 3 = 78 screens (plus the public door); 108 actions, 57 of them
with an approval category (the categories are exactly `maludb-os.json` `approvals[]`).
