# Build spec: reports (slice 7)

What exists at the end: the firm's nine reports — utilization, realization, unbilled work, revenue, margin, budget burn, AI pass-through, the
expense report, missing time — each a page over its SQL function with filters and a CSV, honouring `cost.view` and the record rule; the
reports index as cards. Nothing here writes.
Schema: `ct_unbilled()`, `ct_unbilled_items()`, `ct_utilization()`, `ct_realization()`, `ct_budget_status()`, `ct_margin()`, `ct_ar_aging()`,
`ct_revenue()`, `ct_ai_passthrough()`, `ct_missing_time()`, `ct_client_statement()` (db/014); `mcp_expenses`, `mcp_engagements`, `mcp_members`,
`mcp_departments` (db/015). Never modify them. **The functions are the referee**: a report shows exactly what the function answers the caller.

## Screens (designed at 1280 px, usable at 375 px — cards per row on a phone)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `reports` | `/reports/` | the reports as cards with one line each; the ones the caller may open (`reports.view`, `reports.view_own` for utilization alone, `cost.view` for margin, `billing.write|reports.view` for the aging) |
| `report` | `/reports/{report}?from=&to=&engagement=&client=&member=&department=&by=` | one report: the filter row (a period picker with This month / Last month / This quarter / Year to date, the record filters that apply), the table (a card list on a phone), the totals row, **CSV**; `report` ∈ utilization, realization, wip, revenue, margin, budget-burn, ai-passthrough, expenses, missing-time |
| `ar-aging` | `/reports/ar-aging` | slice 6's (listed on the index) |

## The reports
| Report | Function | Filters | Columns |
|---|---|---|---|
| Utilization | `ct_utilization(from, to, member, department)` | period, member, practice | person, practice, capacity, logged, billable, approved billable, utilization %; a totals row; a practice subtotal when grouped |
| Realization | `ct_realization(from, to, engagement)` | period, engagement, client | engagement, client, approved h, billed h, written off h, unbilled h, no-rate h, standard, billed, realization % (hours, amount) |
| Unbilled work (WIP) | `ct_unbilled(as_of, engagement, client)` | as of, client | engagement, client, hours, time amount, no-rate hours, expenses, usage, milestones, total, oldest item; → `/billing/unbilled/{id}` |
| Revenue | `ct_revenue(from, to, by)` | period, by (client / engagement / kind / practice / month) | group, invoices, subtotal, tax, total per currency |
| Margin | `ct_margin(from, to, engagement)` | period, engagement | engagement, client, bill value, time cost, expense cost, usage cost, total cost, margin, margin % — **only to `cost.view`** and for a Manager their engagements |
| Budget burn | `ct_budget_status()` per active engagement | status, practice, manager | engagement, hours used / budget, amount / fee, invoiced / NTE, AI cost / AI budget (cost shown under `cost.view` or `usage.write`), pace, weeks left; bars |
| AI pass-through | `ct_ai_passthrough(from, to, engagement)` | period, engagement | engagement, provider, kind, charges, tokens, cost, billable, billed, unbilled, markup |
| Expense report | `mcp_expenses` grouped | period, member, engagement, category, status | person, engagement, category, count, amount, tax, billable amount, reimbursable, reimbursed |
| Missing time | `ct_missing_time(week)` | week | person, status, hours logged, empty weekdays, capacity; → Nudge (slice 3's) |

## Rules this slice enforces (beside the functions')
- The **period picker** defaults to this month; `from`/`to` are dates; a period over 24 months is refused in words.
- **CSV** is the same rows the page shows, through one presenter (`report_cells()`: typed rows → strings; the page, the JSON answer and the CSV share it);
  the download logs `export.download` with the report's name, the filters and the row count.
- A report the caller may not open → 403 in its right's words; the index hides it.
- The margin page says, above the table, whose engagements it covers ("Your engagements" for a Manager, "Every engagement" for Billing/Finance/admin).

## Files (exactly these)
- `html/reports/index.php` · `html/reports/report.php` (`report=` from the allow-list, else 404) · `html/reports/csv.php` (GET, the same filters, `export.download`)
- `app/features/reports/{queries,present,csv}.php` — `queries.php` holds ONE function per report returning typed rows; Phase 4's tools reuse them
- `app/views/reports/{index,report,partials/filters,partials/report-table,partials/report-cards}.php`

## Query functions (signatures fixed)
- `report_utilization(PDO, array $f): array` · `report_realization(...)` · `report_wip(...)` · `report_revenue(...)` · `report_margin(...)` · `report_budget_burn(...)` · `report_ai_passthrough(...)` · `report_expenses(...)` · `report_missing_time(...)` — each `$f` the filters as the page parses them; each returns `['columns' => [...], 'rows' => [...], 'totals' => [...]]`
- `report_period(array $query): array` (the picker → `from`, `to`) · `report_cells(array $report): array` (strings for the page and the CSV) · `report_csv(array $report): string`
- `reports_for(PDO, int $memberId): array` (the cards the caller may open)

## Handlers
None but `csv.php` (GET with the right; `export.download`). Every page: `require_login()`, the right, `log_screen_view()`.

## Action manifest entries
Screens: `reports`, `report` (the nine), `ar-aging` (slice 6's). Actions: none (the CSV is a GET download logged as `export.download`; `export_download` is slice 8's exports page).

## Activity log events
`screen.view` (with `after.report` and the filters), `export.download` (report, filters, rows).

## Status vocabulary
Utilization: under 50 % `danger`, 50–70 `warning`, over 70 `success`. Realization %: under 80 `warning`. Margin %: negative `danger`. Budget bars as slice 2. Ids: `report-filters`, `report-table`, `report-totals`, `report-card-{key}`.

## Out of scope for this slice
The exports page (8), the aging (6), charts (Extended — tables and bars only in v1).

## Proof (`tests/phase3/slice7/run.sh`)
- [x] Every report answers the proof's figures for the slice-3 to slice-6 world, derived by hand in each proof's comments (the world is `report_world()`: slice 6's plus a written-off week, a marked-up charge, INV-00001 and INV-00002, an unbilled charge, this week's work) — utilization 17.5 % per billable person and 7.5 % in all, realization 60.9 % of the hours and 73.0 % of the value, WIP 620.00, revenue by each of the five groupings (2,479.00 + 300.00), margin 1,500.00 = 48.4 %, budget burn 23 of 400 h, AI pass-through 20.00 markup, the expense report's reimbursed 40.00, missing time for a week (utilization 27, realization 17, wip 16, revenue 21, margin 20, budget 24, ai 14, expenses 21, missing 13).
- [x] The CSV equals the page's cells for every report; `export.download` logged with the row count and no row (exports 33).
- [x] Who sees (visibility 34): Priya and Dana open utilization (their own row only) and nothing else (the index shows one card; the others 403 in words); Marco realization, WIP, budget burn, margin ("Your engagements"), AI pass-through, expenses, missing time and utilization — not revenue; Bea, Ann and the owner everything; the margin page absent for a Manager with no engagement.
- [x] A 25-month period refused in words; the picker's presets land on the right dates (units 37, json 30).
- [x] 375 × 740 and 1280 × 800 (browser 104): the index as cards, the table in its scrolling wrapper on both (`scrollWidth` = viewport), the filter row wraps, no console errors, the CSV downloaded and checked; the registry reads 75 screens as built (73 + `reports`, `report`).

**Built and proven 2026-10-05: 411 checks green** (`tests/phase3/slice7/run.sh`: utilization 27, realization 17, wip 16, revenue 21, margin 20, budget 24, ai 14, expenses 21, missing 13, exports 33, visibility 34, json 30, units 37, browser 104; the registry reads 75 screens and 106 actions built, `--check` OK).

## Decisions taken while building (defaults resolved)
- **No exports page, no `/exports` actions**: the spec's own files list and "Out of scope" leave `exports` to slice 8; its placeholder stays. The nine CSVs are `GET /reports/csv?report=…` (`html/reports/csv.php`).
- **A CSV needs `exports.download` AND the report's own right**: Billing, Finance and the admin download; a Manager sees the page and has no CSV button (403 "You may not download exports." if he asks).
- **The table is a table on the phone too** (scrolling inside `.table-responsive`; the page never scrolls sideways) — the spec's "card list on a phone" is not built, on the lead's instruction that ledgers and line items are tables. The index is cards.
- **The CSV is the page's cells verbatim** (an amount reads "1,260.00 USD", a negative "(110.00) USD"); typed numbers are in the JSON `rows`. One safety addition: a cell beginning `= + - @` gets a leading apostrophe. No byte-order mark.
- **Two derived rights** (`app/auth.php`): `revenue.view` = `reports.view` AND `ct_sees_all()` (see Open questions) and `margin.view` = `cost.view` AND (sees all OR manages an engagement), so the margin card and page are absent for a Manager with nothing to cover (403 "You manage no engagement, so there is no margin to show you.").
- **Totals rows are sums of the function's rows per currency**; percentages on them use the function's own formula (100 × part / base, one decimal). Budget burn and missing time have no totals row; revenue by kind leaves the invoice count blank (an invoice counts under each kind it holds).
- **Realization** shows the engagement's currency from `mcp_engagements` (the function has none); its client filter selects rows after the function answers. **Utilization** `by=practice` adds a subtotal row per practice (`is_subtotal` in the JSON rows). A Consultant's page offers no person or practice picker.
- **Expense report** defaults to status Approved (the filter offers every status); it groups `mcp_expenses` in SQL (person, engagement, category, currency).
- **Budget burn** defaults to active engagements; the AI-cost column shows only with `cost.view` or `usage.write`. **Missing time** keeps slice 3's Nudge on `/time/missing` (each row links there).
- **Logging**: `screen.view` (screen `report`, `after` = the report and the filters that are set) — a JSON read logs only with `X-Screen-View: 1`; `export.download` after = report, filters, `rows`.

## Open questions
- **`ct_revenue()` (db/014) answers a Manager the whole firm's invoices.** Its gate is `ct_has_right('reports.view') OR i.engagement_id IN (SELECT ct_managed_engagement_ids())`, and a Manager holds `reports.view`, so the second branch is dead. Expected (design §3 "reports.view (their engagements)", tool surface "`manage` for their engagements"): Marco sees INV-00001 only (2,479.00 USD). Got (proved in `revenue.php`): both invoices, including INV-00002 on Bea's engagement he does not manage (2,779.00 USD). The page keeps a Manager out (`revenue.view`) rather than patch a number in PHP; a migration should scope the function (reports.view across every engagement, else managed engagements only) and then the right can relax. Phase 4's `revenue` tool will leak the same way until then.
- `ct_utilization()` and `ct_missing_time()` likewise answer a Manager every person (reports.view / time.approve), not only his engagements' staff; people are not engagement-scoped in the design, so left as the function answers — the owner may want it narrowed.
