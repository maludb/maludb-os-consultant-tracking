# Build spec: time — THE EXEMPLAR (slice 3)

Built by the planning-class model. Every later slice replicates its files, ids, gates, flow and proof style. This is the application's
spine: a consultant logs hours on a phone against the engagements they are staffed on, runs a timer, submits the week; a manager approves
or rejects it; approval snapshots the rate and the cost onto each entry; approved hours are what invoicing bills (slice 6), utilization
and realization measure (slice 7), and the margin costs (slice 7). Every step logged, every rule kept by the database.
Schema: `timesheets`, `time_entries`, `time_timers`, `ct_timesheet_for()`, `ct_timesheet_totals()`, `ct_time_entry_guard()`,
`ct_timesheet_guard()`, `ct_timesheet_submit()`, `ct_timesheet_reopen()`, `ct_timesheet_approve()`, `ct_timesheet_reject()`,
`ct_time_entry_write_off()`, `ct_timer_start()`, `ct_timer_stop()`, `ct_via_function()`, `ct_item_billed()` (db/009, db/012);
`ct_resolve_rate()`, `ct_can_log_on()`, `ct_managed_engagement_ids()` (db/008); `ct_cost_rate()`, `ct_capacity()` (db/007);
`ct_week_start()` (db/005); `ct_utilization()`, `ct_missing_time()`, `ct_budget_alerts_check()` (db/014); the views `mcp_timesheets`,
`mcp_time_entries`, `mcp_time_timers`, `mcp_engagements`, `mcp_engagement_members`, `mcp_activity_types`, `mcp_members`, `mcp_activity_log`
(db/015). Never modify them. **The database is the referee**: the week, the increment, the day's cap, staffing on an active engagement,
the locking, the snapshots, no self-approval, the status moves only through the verbs — the handlers call the verb inside `ct_guard()`
and translate a refusal (`P0001`) into its sentence as a 422; PHP decides nothing twice.

## Screens (375 px is the design — a consultant logs time on a phone; the approvals usable at 375 px, designed at 1280 px)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `timesheet` | `/time/?week=&member=` | **My week**: the week's Monday–Sunday; on a phone a day list (each day: its entries as rows — engagement, hours, activity, note —, the day's total, "+ Add"); on a desktop the grid (engagements down, days across, hours in the cells, a row total and a day total); above it the **timer bar** (start on an engagement / the running one with elapsed and Stop); the week's status chip and totals; **Submit** when open or rejected, **Reopen** when submitted and nothing approved; a rejected week shows the manager's reason in a `warning` box; ◂ ▸ to other weeks; `?member=` for a manager looking at someone's week (read, and Submit on their behalf) |
| `time-entry-add` / `time-entry-edit` | `/time/entries/new?engagement=&date=`, `/time/entries/{id}/edit` | the form: date (the week's day picker), engagement (the ones I am staffed on today — `ct_can_log_on()`; a manager also those they manage), hours (a stepper in the settings' increment; quick chips 0.5 · 1 · 2 · 4 · 8), activity (the billable default follows), billable, note (client-visible — the label says so), internal note; Save and **Save and add another** |
| `time-approvals` | `/time/approvals?member=` | the submitted weeks awaiting me (entries on engagements I manage): per person a card — the week, the hours, billable hours, the engagements in it, submitted when; **Approve** (what I manage) and **Reject** (reason) on each; "Approve all of Priya's" |
| `timesheet-view` | `/time/weeks/{id}` | one week as its manager sees it: the entries by day with activity, note, internal note, billable, the **rate that will apply** (`resolve_rate`, with `rates`) and the bill value; after approval the snapshotted rate and (with `cost`) the cost; Approve / Reject / Reopen (when submitted, nothing approved); write-off on an approved unbilled entry; the timeline |
| `team-time` | `/time/team?week=&department=` | everyone's hours per day for a week (people down, days across), missing days `danger`, the week's status chip; a practice filter |
| `missing-time` | `/time/missing?week=` | `ct_missing_time()`: who has not submitted, hours logged, empty weekdays, capacity; **Nudge** per person (slice 8 sends; here it queues) |
| `written-off` | `/time/written-off?from=&to=&engagement=` | the hours written off with reasons |

## Logging time
- **Who may log on what**: the engagements `ct_can_log_on(engagement, me, date)` admits — active, started, staffed on that date (or its manager).
  The form lists only those; a POST naming another → the database's sentence ("Not staffed on ENG-0042 on 2026-03-02 (or the engagement is not active)") as a 422.
- **Hours** in the settings' increment (0.25 by default): the stepper and the chips never produce another value; a typed 0.3 → the sentence. The
  day's cap (`max_hours_per_day`, 16) → the sentence. Up to 24 h a day is the schema's hard limit.
- **Billable** defaults from the activity type's `billable_default` (the form sets the checkbox when the activity changes — a small script; without
  JavaScript the form posts what it shows). The note is **client-visible** — it goes on the invoice's backing detail; the internal note never leaves.
- **The week** is made by the database when the first entry lands (`ct_timesheet_for()`); an entry in a submitted week → "The week of … is submitted —
  reopen it before adding time"; in an approved week → the same sentence with "approved" (a manager rejects what must change).
- **For someone else** (`member` on the form, a manager): the entry is theirs; the log row says who logged it (`after.logged_by`).
- **The timer**: Start names an engagement (and an activity, a note); one per person — starting another stops the first; **Stop** writes a draft entry
  rounded UP to the increment (at least one step) dated by the start in the person's time zone; the bar shows the elapsed time live (a small script;
  the page reloads fine without it).
- **Delete**: a draft or rejected entry only; the week's totals follow.

## Submitting, approving, rejecting, reopening
- **Submit** (`ct_timesheet_submit`): the person (or a manager for them); open or rejected → submitted; a week in the future refused; an empty week may
  be submitted (a week off). The approvals waiting notice goes to the managers of the engagements in it (`timesheet_submitted`, slice 8 sends).
- **Approve** (`ct_timesheet_approve`): `time.approve`; approves the entries on the engagements the approver manages — the rate and cost snapshotted
  per entry (`bill_rate`, `rate_source`, `consulting_role_id`, `cost_rate`); the week becomes approved when nothing is left submitted, else stays
  submitted and the page says "2 entries on ENG-0043 await its manager". Never one's own (the sentence). After approval: `ct_budget_alerts_check()`
  for each engagement touched (slice 2's alerts), the `timesheet_approved` notice to the person.
- **Reject** (`ct_timesheet_reject`): a reason is required; the submitted entries return to the person as rejected (editable); approved entries stay;
  the `timesheet_rejected` notice carries the reason.
- **Reopen** (`ct_timesheet_reopen`): a submitted week nobody approved anything in, by the person or a manager → open.
- **Write-off** (`ct_time_entry_write_off`): an approved, unbilled entry, with a reason; it never bills; realization shows it. **money_out** for agents.
- **Nudge** (`timesheet_nudge_send`): a manager or billing (or the Clerk) asks a person to submit; this slice queues the notice (`missing_time` kind,
  the week and the empty days) — slice 8 sends it; **external_send** for agents.

## The week page's shape
- Header: "Week of Mar 2–8" with ◂ ▸, the status chip, totals "9.5 h · 7.5 billable"; the timer bar; the rejection box when rejected.
- A day: its date as a heading, the entries as rows (`time-entry-row` partial: engagement number and name, hours, activity, billable chip, the note's
  first line; tap → edit when editable), the day's total, "+ Add" (→ `/time/entries/new?date=`).
- The desktop grid (`timesheet-grid` partial): a table, engagements down, Mon–Sun across, the cell shows the hours (several entries sum; tap opens the day's
  list), a row total, a day total row; the same data, another shape — no second query function.
- Footer: **Submit** / **Reopen**; a manager viewing another's week sees **Submit for Priya**.
- `data-screen="timesheet" data-entity="timesheet" data-record-id="{id}"`; a week the member may not see → 404 "Week not found."

## Files (exactly these)
- `html/time/index.php` (`timesheet`) · `team.php` · `missing.php` · `written-off.php` · `approvals.php`
- `html/time/entries/form.php` (`time-entry-add`, `time-entry-edit`) · `save.php` · `delete.php` · `write-off.php`
- `html/time/timer/start.php` · `stop.php`
- `html/time/weeks/view.php` (`timesheet-view`) · `submit.php` · `reopen.php` · `approve.php` · `reject.php` · `nudge.php`
- `app/features/time/{queries,present,write,handler}.php` (`handler.php`: load the entry or the week, the gate, `time_log()` — `timesheet_id` and `engagement_id` on every row)
- `app/views/time/{index,form,approvals,week,team,missing,written-off,partials/timer-bar,partials/day-list,partials/timesheet-grid,partials/time-entry-row,partials/week-card,partials/timeline}.php`

## Query functions (signatures fixed)
- `find_timesheet(PDO, int $memberId, string $weekStart): ?array` (`mcp_timesheets`; null when no row — the page shows an empty open week) · `find_timesheet_by_id(PDO, int $id): ?array` · `timesheet_entries(PDO, int $timesheetId): array` (`mcp_time_entries`, by day) · `week_days(string $weekStart): array`
- `loggable_engagements(PDO, int $memberId, string $date): array` (`mcp_engagements` filtered by `ct_can_log_on()`) · `find_time_entry(PDO, int $id): ?array`
- `save_time_entry(PDO, ?int $id, array $fields, int $memberId, int $by): int` (INSERT/UPDATE on the base table; the guard decides) · `delete_time_entry(PDO, int $id, int $by): void`
- `start_timer(PDO, int $memberId, int $engagementId, ?int $activityId, ?string $note): array` · `stop_timer(PDO, int $memberId): array` (the entry) · `my_timer(PDO, int $memberId): ?array`
- `submit_timesheet(PDO, int $timesheetId, int $by): array` · `reopen_timesheet(...)` · `approve_timesheet(PDO, int $timesheetId, int $by): array` (`approved`, `left_submitted`, `week_status`, the engagements touched) · `reject_timesheet(PDO, int $timesheetId, int $by, string $reason): array` · `write_off_entry(PDO, int $entryId, int $by, string $reason): array`
- `timesheets_pending(PDO, int $approverId, ?int $memberId): array` · `team_time(PDO, string $weekStart, ?int $departmentId): array` · `missing_time(PDO, string $weekStart): array` · `written_off(PDO, array $filters): array`
- `week_timeline(PDO, int $timesheetId, int $limit = 100): array` · `queue_time_notice(PDO, int $memberId, string $kind, int $timesheetId, array $data): void` (the `notifications` row + the outbox row per the person's prefs; slice 8 sends)

## Handlers (every one: `ct_handler_begin()`; the gate; `ct_guard()`; `log_activity` with `timesheet_id` and `engagement_id`; `ct_done()`; `HX-Trigger: timesheetChanged`)
- `entries/save.php` (`time_entry_save`): `time.write`; for another member → `time.approve` and manage of the engagement; `time_entry.save` (`after`: entry_date, engagement_id, hours, billable, activity_type_id, logged_by; never the internal note); location `/time/?week=`.
- `entries/delete.php`: own or manage; `time_entry.delete`; **deletion**. `entries/write-off.php`: manage; `time_entry.write_off` (hours, reason, bill value, currency); **money_out**.
- `timer/start.php` / `stop.php`: own; `timer.start` (engagement_id) / `timer.stop` (hours, entry id).
- `weeks/submit.php` (`timesheet_submit`): own or manage (`week` as a Monday or an id; this week by default); `timesheet.submit` (week_start, hours, entries); the notice to the managers.
- `weeks/reopen.php`: own or manage; `timesheet.reopen`. `weeks/approve.php` (`timesheet_approve`): `time.approve`; `timesheet.approve` (`approved`, `left_submitted`, `week_status`); the alerts check; the notice; **other** for agents. `weeks/reject.php`: `time.approve`; `timesheet.reject` (reason); the notice; **other**.
- `weeks/nudge.php` (`timesheet_nudge_send`): `time.approve` or `billing.write`; one per person per week (the dedupe key); `timesheet.nudge`; **external_send**.

## Action manifest entries
Screens: `timesheet`, `time-entry-add`, `time-entry-edit`, `time-approvals`, `timesheet-view`, `team-time`, `missing-time`, `written-off`.
Actions (10): `time_entry_save`, `time_entry_delete`, `timer_start`, `timer_stop`, `timesheet_submit`, `timesheet_reopen`, `timesheet_approve`, `timesheet_reject`, `time_entry_write_off`, `timesheet_nudge_send`. Agent approvals: `timesheet_approve`, `timesheet_reject` (`other`); `time_entry_write_off` (`money_out`); `timesheet_nudge_send` (`external_send`); `time_entry_delete` (`deletion`). `PARTIAL_UPDATE_TARGETS` gains `/time/entries/save.php => ['time_entries', 'entry', 'mcp_time_entries', 'entry_id']`.

## Activity log events (every row: `timesheet_id`, `engagement_id`)
`time_entry.save|delete|write_off`, `timer.start|stop`, `timesheet.submit|reopen|approve|reject|nudge`, `screen.view` (on `timesheet` and `timesheet-view` `after.week_start`). **No row carries an internal note, a cost rate or a bill rate** (the rate is on the row; the log says `rate_source`).

## Notifications this slice queues (`notifications` + `notification_outbox`; the sender is slice 8)
| Step | Who is told | Kind |
|---|---|---|
| a week is submitted | the managers of the engagements in it | `timesheet_submitted` |
| approved (fully) | the person | `timesheet_approved` |
| rejected | the person, with the reason | `timesheet_rejected` |
| nudged | the person: the week and the empty days | `missing_time` (text when they chose it — K6, slice 8) |

## Status vocabulary
Week status: open `secondary`, submitted `info`, approved `success`, rejected `warning`. Entry status chips on the manager's view: draft none, submitted `info`, approved `success`, rejected `warning`, written_off `dark`. Billable chip `feather-dollar-sign` `success`; non-billable none. A missing day `danger` cell. Ids: `timer-bar`, `week-header`, `day-{date}`, `time-entry-row-{id}`, `timesheet-grid`, `week-footer`, `week-card-{id}`, `approvals-list`.

## Out of scope for this slice
Expenses (4), usage (5), invoicing the approved hours (6 — this slice shows "billed" from `ct_item_billed()` as a chip only), the reports beyond the two pages here (7), sending the notices (8), the OS-side K6 text (8).

## Proof (`tests/phase3/slice3/run.sh`: the scratch database, members through dev hand-offs, curl with signed action and run tokens, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
The world: the slice-2 fixtures (Acme T&M with Priya, Marco, Dana staffed; Beta fixed bid with Priya), the cost rates and the Standard card.
- [ ] **Log**: Priya logs Mon 4, Tue 3.5, Wed 2 travel (non-billable follows the activity) — the week made open with 9.5 / 7.5; 0.3 h → the increment's sentence; a 13 h entry on a 4 h day → the cap's sentence; on an engagement she is not staffed on → 404 on the form's list and 422 on a forged POST; before the engagement started → 422; Lee (staffed nowhere) sees an empty engagement list and "Ask your manager to staff you".
- [ ] **The timer**: start on Acme, start on Beta (the first stops into a 0.25 h draft), stop → a second entry; the bar shows elapsed; a timer on an engagement she is not staffed on → 422.
- [ ] **Submit, approve, snapshots**: Priya submits (Marco told); edits refused in words; Marco approves — 180 rate_card, cost 70, approved_by, the week approved, Priya told; self-approval refused; Dana (no `time.approve`) → 403 in words; an approved entry's edit and delete refused in the database's words; approving twice refused.
- [ ] **Reject and resubmit**: Dana's week rejected with a reason (shown in the box, the notice carries it); she edits and adds; the totals follow; resubmits; Marco approves at 120 / 55.
- [ ] **Partial approval**: a week with entries on Acme (Marco's) and on an engagement managed by another manager: Marco's approve leaves the other's submitted and says so; the other manager (or Bea) finishes it; the week turns approved.
- [ ] **Reopen**: Dana reopens her own submitted week; a week with an approved entry cannot be reopened (the sentence); a manager reopens for her.
- [ ] **Write-off**: an approved unbilled entry written off with a reason; without → 422; a billed one (slice 6's fixture, else skipped) refused.
- [ ] **Who sees**: Priya sees her weeks and entries without bill rate or cost; Marco sees every entry on his engagements with rates and cost; Dana sees hers; Ann sees all with cost; Bea all; `/time/weeks/{Dana's}` → 404 for Priya; the team page and missing time → 403 for Priya in words.
- [ ] **Missing time**: for a week Dana submitted and nobody else did, the list names the others with their empty days; Nudge queues one notice per person and a second nudge the same week queues nothing (dedupe).
- [ ] **JSON mode**: every handler under a signed action token: `{ok, did, record_id, location}`; the expert's run token logs an entry for the acting person and submits; `timesheet_approve` by the agent → 202 pending (the fake hook); `_partial=1` keeps the entry's fields.
- [ ] **375 × 740 and 1280 × 800**: the day list on the phone and the grid on the desktop show the same hours; the stepper and chips produce only increments; every button ≥ 44 px, `scrollWidth` = viewport, no console errors; JavaScript off logs, submits and approves; the registry reads 8 screens and 10 actions as built.

## Open questions
