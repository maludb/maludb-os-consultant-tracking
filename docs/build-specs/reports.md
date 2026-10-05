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
- [ ] Every report answers the proof's figures for the slice-3 to slice-6 world (utilization 18.8 %, realization 83.0 %, WIP totals, revenue by kind, margin 1427.00, budget burn, AI pass-through 56.00 markup, the expense report's reimbursable column, missing time for the week).
- [ ] The CSV equals the page's cells for every report; `export.download` logged with the row count.
- [ ] Who sees: Priya opens utilization (her own row only) and nothing else (the index shows one card; the others 403 in words); Marco realization, WIP, budget burn and margin for his engagements ("Your engagements"); Bea and Ann everything; the margin page absent for a Manager with no engagement.
- [ ] A 25-month period refused in words; the picker's presets land on the right dates.
- [ ] 375 × 740 and 1280 × 800: cards on the phone, the table on the desktop, the filter row wraps, `scrollWidth` = viewport, no console errors; the registry reads 2 screens as built.

## Open questions
