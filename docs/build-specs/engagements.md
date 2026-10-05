# Build spec: engagements (slice 2)

What exists at the end: a Manager opens an engagement for a client — time-and-materials or fixed bid —, staffs people in consulting
roles, sets role rates and a person's override, adds milestones and watches the budgets; every person staffed sees the engagement and
its burn (never a cost); every move is logged. This is the record every later slice hangs from.
Schema: `engagements`, `engagement_members`, `engagement_role_rates`, `milestones`, `budget_alerts`, `ct_engagement_guard()`,
`ct_milestone_guard()`, `ct_resolve_rate()`, `ct_visible_engagement_ids()`, `ct_can_see_engagement()`, `ct_managed_engagement_ids()`,
`ct_can_manage_engagement()`, `ct_can_log_on()`, `ct_primary_department()` (db/008); `ct_budget_status()`, `ct_budget_alerts_check()`
(db/014); the views `mcp_engagements`, `mcp_engagement_members`, `mcp_engagement_role_rates`, `mcp_milestones`, `mcp_budget_alerts`,
`mcp_clients`, `mcp_rate_cards`, `mcp_attachments`, `mcp_notes`, `mcp_activity_log` (db/015). Never modify them. **The database is the
referee**: the number, the legal status moves, the retainer refusal, the staffing's non-overlap, the milestone's moves and the fixed
amount once accepted, the currency fixed once invoiced (db/012) — the handlers translate `P0001`/`23P01` into their sentences.

## Screens (375 px first; cards with budget bars; a full-page form; no modals)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `engagement-list` | `/engagements/` | cards by status (active first): number, name, client, kind chip, manager, dates, the hours bar and the fee or NTE bar (`ct_budget_status()`), `i_manage` / `i_am_staffed` chips; filters `q`, `client`, `kind`, `status`, `department`, `manager`, `mine` |
| `engagement-add` / `engagement-edit` | `/engagements/new`, `/engagements/{id}/edit` | the form: client (`?client=` prefills), name, kind, dates, currency (the client's), PO, rate card (the client's default shown), NTE (T&M only — hidden for fixed bid), budget hours, fee / budget amount, AI budget, expense policy (markup %, receipt threshold), cadence, time grouping, share receipts, practice, manager, Projects ref, description, **Start now** |
| `engagement-view` | `/engagements/{id}` | tabs (`?tab=`): **Overview** (the card, the four budget bars with pace and weeks left, the next billing date, the open alerts, the SOW documents, notes), **Staffing**, **Rates**, **Milestones**, **Time** / **Expenses** / **Usage** / **Invoices** (slices 3–6 fill; empty shapes now), **Timeline** (`mcp_activity_log` for the engagement, in words) |
| `engagement-staffing` | `/engagements/{id}/staffing` | the staffed people: name, role, from–to, override (with `rates`); add (member picker, role, from, override); end (today or a date) |
| `engagement-rates` | `/engagements/{id}/rates` | one row per consulting role: the card's rate, this engagement's override, editable in place; below, each staffed person's resolved rate today with its source (`ct_resolve_rate()`) |
| `engagement-milestones` | `/engagements/{id}/milestones` | the milestones as cards: name, amount, due, status chip, delivered/accepted dates; Deliver, Accept (a form page: who accepted, how, evidence file), Cancel |
| `budget-alerts` | `/usage/budgets` | every active engagement's four budgets as bars with the pace; the unacknowledged alerts with Acknowledge; "Check now" |

## Rules this slice enforces (beside the database's)
- **Who may create**: `engagements.manage`; the manager defaults to the creator; the practice to the creator's primary department. A Manager
  manages what they manage; Billing and admin everything (`ct_can_manage_engagement()`).
- **Kind** fixes the form: fixed bid shows the fee and hides the NTE; T&M shows the NTE and a soft budget. Retainer is not offered (the
  guard refuses it anyway).
- **Staffing**: a person is staffed from a date, in one role at a time; ending a staffing stops new time from that date (the entries stay);
  re-staffing later in another role is a new row. The manager of the engagement is staffed by default on creation (as `principal` unless the
  form says otherwise) — a decision taken here: a manager logs time too.
- **Rates**: the role rates page writes `engagement_role_rates` (an empty rate removes the override); a person's override is on their staffing
  row; the page shows what `ct_resolve_rate()` answers today so nobody guesses.
- **Milestones**: Deliver → delivered; Accept needs an acceptance note and names who accepted (the caller by default) and may carry an evidence
  file (`attachments`, `record_type = 'milestone'`); an accepted milestone's amount is fixed; "Invoice this milestone" is slice 6's button.
- **Budgets**: after every approval (slices 3–5) and in the worker, `ct_budget_alerts_check()` writes the alerts; this slice shows them and
  acknowledges them; the bars show the hours, the fee/soft budget, the NTE (invoiced total) and the AI budget (cost — shown only under
  `cost.view` or `usage.write`, else "set" / "not set").
- **Documents**: the SOW and other files on the Overview (`attachment_add` with `record_type = 'engagement'`), never `client_visible`.

## Files (exactly these)
- `html/engagements/index.php` · `form.php` · `view.php` · `staffing.php` · `rates.php` · `milestones.php`
- `html/engagements/save.php` · `activate.php` · `hold.php` · `close.php` · `reopen.php` · `cancel.php` · `delete.php`
- `html/engagements/staffing/save.php` · `end.php` · `html/engagements/rates/save.php`
- `html/engagements/milestones/save.php` · `deliver.php` · `accept.php` · `cancel.php` · `delete.php`
- `html/engagements/attachments/save.php` · `delete.php` · `get.php` · `html/engagements/notes/save.php` · `delete.php` (the generic handlers of slice 1 live here: `record_type` + `record`)
- `html/usage/budgets/index.php` · `check.php` · `acknowledge.php`
- `app/features/engagements/{queries,present,write,handler}.php` (`handler.php`: load the engagement, the gate, `engagement_log()`)
- `app/views/engagements/{index,form,view,staffing,rates,milestones,partials/engagement-card,partials/budget-bars,partials/timeline}.php` · `app/views/usage/budgets.php`

## Query functions (signatures fixed)
- `find_engagements(PDO, array $filters, int $limit = 100, int $offset = 0): array` (`mcp_engagements`; `q`, `client_id`, `kind`, `status[]`, `department_id`, `manager`, `mine`, `ending_within_days`) · `count_engagements(...)`
- `find_engagement(PDO, int $id): ?array` (+ client, manager, staffing, role rates, milestones, budget status, open alerts, documents) · `find_engagement_by_number(PDO, string $number): ?int`
- `engagement_budget(PDO, int $id): ?array` (`ct_budget_status()`) · `engagement_timeline(PDO, int $id, int $limit = 100): array`
- `save_engagement(PDO, ?int $id, array $fields, int $by): int` (+ the manager's default staffing on create) · `move_engagement(PDO, int $id, string $to, ?string $reason, int $by): array` (activate/hold/close/reopen/cancel; the guard decides) · `delete_engagement(PDO, int $id, int $by): void`
- `find_staffing(PDO, int $engagementId): array` · `save_staffing(PDO, ?int $id, int $engagementId, int $memberId, int $roleId, string $from, ?string $to, ?float $override, ?string $note, int $by): int` · `end_staffing(PDO, int $id, string $to, int $by): void`
- `find_role_rates(PDO, int $engagementId): array` (the card's, the override, each staffed person's resolution) · `save_role_rate(PDO, int $engagementId, int $roleId, ?float $rate, int $by): void` · `resolve_rate(PDO, int $engagementId, int $memberId, string $date): array`
- `find_milestones(PDO, int $engagementId): array` · `save_milestone(...)` · `move_milestone(PDO, int $id, string $to, array $fields, int $by): array` (deliver/accept/cancel) · `delete_milestone(...)`
- `find_budget_alerts(PDO, ?int $engagementId, bool $unacknowledged): array` · `check_budget_alerts(PDO, ?int $engagementId): int` · `acknowledge_alert(PDO, int $id, int $by): void`

## Handlers (every one: `ct_handler_begin()`; the gate; `ct_guard()`; `log_activity` with `engagement_id` and `client_id`; `ct_done()`; `HX-Trigger: engagementChanged`)
- `save.php` (`engagement_save` / `engagement_update`): `require_right('engagements.manage')`; update `require_can_manage_engagement()`; `engagement.create` (`after`: number, client_id, kind, status, dates, budgets, cadence, manager_member_id, department_id) / `engagement.update` (changed only); location `/engagements/{id}`.
- `activate.php` / `hold.php` / `close.php` / `reopen.php` / `cancel.php` (`engagement_*`): manage; `engagement.{verb}` (`before.status`, `after.status`, reason); cancel needs a reason (422 in words).
- `delete.php`: manage; a draft only (the trigger refuses otherwise, shown); `engagement.delete`; **deletion**.
- `staffing/save.php` (`engagement_member_save`): `staffing.write` + manage; the member must be an active human or agent of the mirror; `engagement.staff` (member_id, consulting_role_id, valid_from, valid_to, `has_override`); the EXCLUDE refusal → "Priya is already staffed on ENG-0042 over those dates." `staffing/end.php`: `engagement.unstaff`.
- `rates/save.php` (`engagement_role_rate_save`): manage; `engagement.role_rate` (role_id, before/after rate); **other** for agents.
- `milestones/*`: `milestones.write` + manage; `milestone.save|deliver|accept|cancel|delete` (name, amount, currency, before/after status, accepted_by); accept is **other** for agents and stores the evidence file first when sent.
- `usage/budgets/check.php` (`budget_alert_write`): manage or billing; `ct_budget_alerts_check()` for one or every active engagement; `budget_alert.write` once per alert made. `acknowledge.php`: manage; `budget_alert.acknowledge`.

## Action manifest entries
Screens: `engagement-list`, `engagement-add`, `engagement-view`, `engagement-edit`, `engagement-staffing`, `engagement-rates`, `engagement-milestones`, `budget-alerts`.
Actions (19): `engagement_save`, `engagement_update`, `engagement_activate`, `engagement_hold`, `engagement_close`, `engagement_reopen`, `engagement_cancel`, `engagement_delete`, `engagement_member_save`, `engagement_member_end`, `engagement_role_rate_save`, `milestone_save`, `milestone_deliver`, `milestone_accept`, `milestone_cancel`, `milestone_delete`, `budget_alert_write`, `budget_alert_acknowledge` — and slice 1's `attachment_add`, `attachment_delete`, `note_add`, `note_delete` now serve `engagement` and `milestone`. Agent approvals: `engagement_delete`, `milestone_delete` (`deletion`); `engagement_role_rate_save`, `milestone_accept` (`other`). `PARTIAL_UPDATE_TARGETS` gains `/engagements/save.php => ['engagements', 'engagement', 'mcp_engagements', 'engagement_id']`.

## Activity log events (every row: `engagement_id`, `client_id`)
`engagement.create|update|activate|hold|close|reopen|cancel|delete|staff|unstaff|role_rate`, `milestone.save|deliver|accept|cancel|delete`, `budget_alert.write|acknowledge`, `attachment.add|delete`, `note.add|delete`, `screen.view`.

## Status vocabulary
Engagement status: draft `secondary`, active `success`, on_hold `warning`, closed `dark`, cancelled `danger`. Kind chip: T&M `info` "T&M", fixed bid `primary` "Fixed". Budget bars: under 50 % `success`, 50–80 `info`, 80–100 `warning`, over `danger`. Milestone status: planned `secondary`, delivered `info`, accepted `success`, invoiced `dark`, cancelled `danger`. Ids: `engagement-card-{id}`, `engagement-tabs`, `budget-bars`, `staffing-row-{id}`, `rate-row-{roleId}`, `milestone-card-{id}`, `alert-{id}`.

## Out of scope for this slice
Time, expenses, usage and invoices on the engagement's tabs (3–6); the NTE's effect on issuing (6); the worker's budget check (8); Projects' list (P1 — the `projects_ref` is typed).

## Proof (`tests/phase3/slice2/run.sh`: the scratch database `ct_dev3`, dev hand-offs, curl with signed action tokens, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
**Built and proven 2026-10-05: 173 checks green** (engagements 32, staffing 26, milestones 22, visibility 22, budgets 20, json 15, browser 36; the registry reads 31 screens and 52 actions built).
- [x] **Create and move**: Marco opens the Acme pilot (T&M, NTE, budgets, monthly, Start now) — ENG-0001, active, USD from the client, himself the manager and staffed as principal from the start date, AI Strategy the practice (his primary department), next billing the first of next month; a fixed bid for Beta (a draft, no billing date on milestones); a retainer → 422 in words; a fixed bid with an NTE → 422; four bad fields named; a Consultant → "You may not manage engagements."; an update keeps what it is not sent and logs the one changed field; Billing manages everything; draft → closed refused in the guard's words; active → on hold (reason logged) → active; "already active"; cancel needs a reason; close sets closed_by/closed_at and refuses changes; reopen clears them; an active one is never deleted (the trigger); a draft deleted; a cancelled one stays so; the list leaves cancelled ones out; the search, status and kind filters.
- [x] **Staffing and rates**: Priya staffed as consultant from a date; the same dates again → "SMOKE Priya is already staffed on ENG-0005 over those dates."; an earlier closed range in another role allowed; an agent may be staffed; the tab lists current first; `ct_resolve_rate()` answers 180 from the card, 125 as the January analyst; a role rate of 200 overrides the card; her override 210 wins; the rates tab shows the card's, the engagement's and each person's resolution with its source; an empty rate removes the override; Priya gets 403 on the rates tab and no override or card on the staffing tab; ending on Jun 30 makes `ct_can_log_on()` refuse from Jun 30; re-staffed later in another role; a Manager who is not the engagement's (nor its practice's admin) gets **404** on it — it is not even visible — until Bea staffs him, then i_manage false.
- [x] **Milestones**: three added in order (the third a deliverable with no amount); a change keeps the rest; M1 delivered → accepted with a note and an evidence file (an attachment of the milestone, not client-visible; accepted_by defaults to the caller); accept without a note → 422; "already delivered"; the amount of an accepted one fixed (the guard's words) while its description still changes; a planned one accepted directly naming who recorded it; delete only a planned one (with its notes); cancel needs a reason and stays so; a note on a milestone lands on the engagement's milestones tab; a Consultant refused.
- [x] **Who sees**: Priya lists what she is staffed on, not the draft; her card carries the hours and fee bars, the AI budget only as "set", no AI cost, no card; her page has no Edit and no moves; the draft is 404, the edit form 403; Dana sees Acme alone; an agent cannot sign on; Ann (Finance) lists everything with i_manage false, reads the AI amount and the card, may not move; Bea everything with i_manage true; Marco's Overview shows the AI amount and bar; the feed making Dana an admin of AI Strategy shows her the practice's engagements (the draft included) but manages none until she holds the Manager role; back to a Consultant out of the practice; the client page; the home's Budget alerts region for a Manager, none for a Consultant.
- [x] **Budgets**: seeded draft entries (35 h at 180) give 87.5 % of hours and 63 % of the budget with the pace and weeks left; the hours bar is warning; `budget_alert_write` writes three alerts (hours 50/80, amount 50) once and logs each; a second check writes nothing; acknowledge once; a Consultant and Finance refused; Billing checks every active one; a usage charge of 85 at cost against a 100 AI budget writes the ai alert at 80 % whose log carries no amount; the budgets screen with Check now and Acknowledge; Finance reads it without Check now; the home lists the open alerts.
- [x] **JSON mode**: every handler under a signed action token; `_partial=1` keeps the engagement's, the staffing's and the milestone's fields; deliver and accept answer the status; 422 `{error: {code: invalid, fields}}`; Priya's token refused a move; the expert (an agent with the Manager role, run token + relay) opens an engagement with its row `source agent`, becomes its manager, may not delete an active one and gets 404 on an engagement it cannot see.
- [x] **375 × 740 and 1280 × 800**: the list and the form (the kind toggles the NTE), Start now lands on the active engagement, the Staffing tab pushes its URL, Priya staffed and refused the second time in `#flash`, a role rate in place, a milestone added, delivered and the acceptance form opened, the budgets screen; every control ≥ 44 px, `scrollWidth` = viewport; Billing's search narrows by HTMX and sees the moves; Priya's list with the Staffed chip, no moves and no Rates tab; JavaScript off posts and lands; no console errors.

**Decisions taken while building (2026-10-05):**
- **A Manager opens an engagement for any live client** — the form lists every client by name from the base table and `engagement_save` accepts any live one; the `mcp_clients` view keeps guarding the contact details, never the name (otherwise a Manager with no engagement on a client could never open the first).
- **An engagement the caller may not see answers 404 on every handler** (never 403): the record's existence is not revealed. The kit's `require_can_manage_engagement(int)` keeps its name; the slice's row-based gate is `require_manages(array $e)`.
- The tabs Staffing, Rates and Milestones have their own addresses (`/engagements/{id}/staffing|rates|milestones` → the view with `tab=`); the Rates tab needs `rates.view` (403 otherwise). Time, Expenses, Usage and Invoices are empty shapes until their slices.
- The milestone's "Cancel it" button on a card sends a fixed reason ("cancelled from the card"); the acceptance form is a card on the milestones tab (`?accept=`), not a page of its own. "Invoice this milestone" is a disabled placeholder until slice 6.
- A same-status move is refused in words ("Milestone … is already delivered.", "ENG-0001 is already active.") before the guard sees it. A name needed for a refusal is read BEFORE the write: after a refused INSERT the transaction is aborted.
- The `usage_charges` guard recomputes `cost_amount` from `quantity × unit_cost`: a fixture (and slice 5) sets the unit cost, never the total alone.
- A checkbox left out of a JSON or token request keeps its value (`ct_yes()` with keep); the browser form always sends its checkboxes through a hidden `no`.

## Open questions
