# Build spec: AI usage and hosting, and the kernel's statement (slice 5)

What exists at the end: a manager records a client's hosting and model-usage costs as usage charges priced at cost, cost plus markup, or a
resale price per million tokens; imports a provider's CSV usage export; keeps hosting schedules that draft a charge each period; pulls the
kernel's closed monthly AI statement and allocates it to engagements by rules, the remainder shown and never billed; approved charges are WIP
for slice 6. The AI budget warns at 80 %.
Schema: `usage_charges`, `usage_imports`, `allocation_rules`, `recurring_charges`, `ai_statements`, `ai_statement_lines`,
`ai_statement_allocations`, `ct_usage_charge_guard()`, `ct_usage_charge_approve()`, `ct_usage_charge_void()`, `ct_recurring_next()`,
`ct_recurring_charge_run()`, `ct_ai_statement_load()`, `ct_ai_statement_allocate()`, `ct_ai_statement_summary()` (db/011); `usage_providers`,
`ct_usage_provider()`, `ct_billable_amount()` (db/005); the views `mcp_usage_charges`, `mcp_usage_imports`, `mcp_allocation_rules`,
`mcp_recurring_charges`, `mcp_ai_statements`, `mcp_ai_statement_lines`, `mcp_ai_statement_allocations`, `mcp_usage_providers` (db/015);
`ct_budget_alerts_check()`, `ct_ai_passthrough()` (db/014). Never modify them. **The database is the referee**: the number, the money from the
pricing, the lock once approved, a closed month only, the rules' shares, the rounding once, the remainder, re-allocation around approved charges.

## Screens (375 px first; the import and the statement designed at 1280 px, usable at 375 px)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `usage-list` | `/usage/?engagement=&from=&to=&kind=&source=&status=` | the charges as rows (cards on a phone): number, engagement, period, kind, provider, quantity and unit, cost (`cost`), billable amount, pricing chip, source chip, status chip, billed chip; a totals footer per currency |
| `usage-add` / `usage-edit` | `/usage/new?engagement=`, `/usage/{id}/edit` | the form: engagement (managed), kind, provider, description, period, quantity and unit, unit cost, pricing (cost / markup % / resale per million), billable, the provider's invoice (file); the billable amount shown live |
| `usage-view` | `/usage/{id}` | the charge in full: the money, the source (the import, the schedule, the statement lines and rules behind a kernel charge with their pieces), the document, Approve / Void, notes, the timeline |
| `usage-import` | `/usage/import?provider=&engagement=` | step 1 the file and the provider (its saved mapping prefilled); step 2 the mapping (date, quantity, unit, cost, model columns) and a five-row preview with duplicates counted (same provider, period, quantity, cost already imported); step 3 Import → draft charges, one per row or grouped by month (a switch) |
| `os-statement` | `/usage/os?period=` | the kernel's months (`ai_statement_status`): period, OS status, pulled, allocated, statement total, allocated, remainder; **Pull** (every closed month not yet pulled, or one); one month opened: its lines (provider, model, agent, department, application, tokens, amount, allocated, remainder) and the charges made; **Allocate** / **Re-allocate**; an open month says "not yet closed in the OS" |
| `allocation-rule-list` / `-add` / `-edit` | `/settings/allocation-rules/…` | the rules as rows: name, matches (agent / department / application by kernel id and the statement's names when seen), engagement, share, pricing, dates, active; what each produced on the last month |
| `schedule-list` / `-add` / `-edit` | `/settings/schedules/…` | the hosting schedules: engagement, description, amount per period, cadence, next date, last run, active; **Run now** when due |

## Rules this slice enforces (beside the database's)
- **Pricing**: cost → the cost; markup → cost × (1 + %); resale → quantity × price ÷ 1,000,000 when the unit is tokens, × price otherwise. The form
  shows the result live; the database writes the truth (`ct_billable_amount()`).
- **Approve** needs `usage.approve` and management of the engagement; a draft only; approval runs `ct_budget_alerts_check()` (the AI budget).
  **Void** needs a reason and an unbilled charge; a kernel charge voided returns its pieces to the statement's remainder.
- **Import**: the CSV's columns are mapped once per provider (saved on `usage_providers.csv_mapping`); a row makes a draft charge (kind model_usage
  or api by the mapping, quantity in the file's unit, cost from the file); duplicates (same provider, period, quantity, cost) are skipped and
  counted; the import row remembers the file name and counts; `usage_import_delete` removes the import's still-draft charges.
- **Schedules**: a due schedule (`next_date ≤ today`) runs by hand or by the worker (slice 8) → one draft charge for the period it covers and the
  next date; an end date deactivates it; `cadence` weekly / monthly / quarterly / yearly × `every_n`.
- **The OS statement**: Pull calls `GET {OS_INTERNAL_URL}/api/v1/ledger/periods.php` and, per closed month not yet pulled (or the one asked),
  `?period=`; each document goes through `ct_ai_statement_load()` verbatim; Allocate runs `ct_ai_statement_allocate()` for a **closed** month and
  reports the charges made and the remainder per provider; a rule added later → Re-allocate (drafts remade, approved kept); rules sharing out
  over 100 % of a line are refused by the database's sentence naming the line. The kernel unreachable → a 503 in words, nothing changed.
- **Rules** name kernel ids (agent, department, application — any subset); the page shows the names the statement's lines carried for those ids
  when it has seen them, else the id; a rule's dates bound the months it applies to.

## Files (exactly these)
- `html/usage/index.php` · `form.php` · `view.php` · `import.php` (GET steps + POST `usage_import`) · `import-delete.php`
- `html/usage/save.php` · `delete.php` · `approve.php` · `void.php`
- `html/usage/os/index.php` (`os-statement`) · `pull.php` · `allocate.php`
- `html/settings/allocation-rules/index.php` · `form.php` · `save.php` · `delete.php`
- `html/settings/schedules/index.php` · `form.php` · `save.php` · `run.php` · `delete.php`
- `app/features/usage/{queries,present,write,import,statement,handler}.php` (`statement.php`: the kernel call and `ct_ai_statement_load()`)
- `app/views/usage/{index,form,view,import,os,partials/charge-row,partials/pricing,partials/statement-lines}.php` · `app/views/settings/{allocation-rules/index,allocation-rules/form,schedules/index,schedules/form}.php`

## Query functions (signatures fixed)
- `find_usage_charges(PDO, array $filters, int $limit = 100, int $offset = 0): array` · `find_usage_charge(PDO, int $id): ?array` (+ source detail, documents) · `save_usage_charge(PDO, ?int $id, array $fields, int $by, ?array $file): int` · `delete_usage_charge(...)` · `approve_usage_charge(PDO, int $id, int $by): array` · `void_usage_charge(PDO, int $id, int $by, string $reason): array`
- `csv_preview(string $path, array $mapping, int $rows = 5): array` · `run_usage_import(PDO, int $providerId, int $engagementId, string $path, string $fileName, array $mapping, bool $groupByMonth, int $by): array` (`import_id`, `inserted`, `skipped`) · `delete_usage_import(PDO, int $id, int $by): int`
- `find_allocation_rules(PDO, array $filters): array` · `save_allocation_rule(...)` · `delete_allocation_rule(...)`
- `find_schedules(PDO, array $filters): array` · `save_schedule(...)` · `run_schedule(PDO, int $id, int $by): array` · `delete_schedule(...)`
- `pull_ai_statements(PDO, ?string $period, int $by): array` (the kernel calls; `ct_ai_statement_load()` each; returns the months touched) · `allocate_ai_statement(PDO, int $statementId, int $by): array` (charges made, the summary) · `ai_statement_status(PDO, ?string $period): array` · `ai_statement_lines(PDO, int $statementId): array`
- `ai_passthrough(PDO, string $from, string $to, ?int $engagementId): array`

## Handlers (every one: `ct_handler_begin()`; the gate; `ct_guard()`; `log_activity` with `engagement_id`; `ct_done()`; `HX-Trigger: usageChanged`)
- `save.php` (`usage_charge_draft`): `usage.write` + manage; `usage_charge.draft` (number, kind, provider, quantity, unit, cost_amount, billable_amount, currency, source); location `/usage/{id}`. `delete.php`: **deletion**.
- `approve.php` (`usage_charge_approve`): `usage.approve` + manage; `usage_charge.approve` (amount = billable_amount, currency); the alerts check; **other**. `void.php`: `usage.write` + manage; `usage_charge.void` (reason).
- `import.php` (POST `usage_import`): `usage.write` + manage; the file ≤ `ATTACHMENT_MAX_BYTES`, `text/csv` or `text/plain` by `finfo`; `usage_import.run` (provider, rows, inserted, skipped); the file is **not** stored (the import row and the charges are the record); `save_mapping` updates the provider. `import-delete.php`: **deletion**.
- `os/pull.php` (`ai_statement_pull`): `usage.write`; `ai_statement.pull` per month (period, status, lines, hash). `os/allocate.php` (`ai_statement_allocate`): `usage.write`; `ai_statement.allocate` (period, charges, allocated_amount, remainder).
- `settings/allocation-rules/*`: `usage.write`; `allocation_rule.save|delete`; **other** / **deletion**. `settings/schedules/*`: `usage.write`; `recurring_charge.save|run|delete`; `run.php` logs the charge made.

## Action manifest entries
Screens: `usage-list`, `usage-add`, `usage-view`, `usage-edit`, `usage-import`, `os-statement`, `allocation-rule-list`, `allocation-rule-add`, `allocation-rule-edit`, `schedule-list`, `schedule-add`, `schedule-edit`. Actions (13): `usage_charge_draft`, `usage_charge_delete`, `usage_charge_approve`, `usage_charge_void`, `usage_import`, `usage_import_delete`, `ai_statement_pull`, `ai_statement_allocate`, `allocation_rule_save`, `allocation_rule_delete`, `recurring_charge_save`, `recurring_charge_run`, `recurring_charge_delete`. Agent approvals: `usage_charge_approve`, `allocation_rule_save`, `recurring_charge_save` (`other`); `usage_charge_delete`, `usage_import_delete`, `allocation_rule_delete`, `recurring_charge_delete` (`deletion`). `PARTIAL_UPDATE_TARGETS` gains `/usage/save.php => ['usage_charges', 'charge', 'mcp_usage_charges', 'usage_charge_id']`.

## Activity log events (every row: `engagement_id` where one)
`usage_charge.draft|delete|approve|void`, `usage_import.run|delete`, `ai_statement.pull|allocate`, `allocation_rule.save|delete`, `recurring_charge.save|run|delete`, `screen.view`.

## Status vocabulary
Status: draft `secondary`, approved `success`, void `dark`. Pricing chip: cost none, markup `info` "+20 %", resale `primary` "15 /M". Source chip: manual none, csv_import `feather-upload`, kernel_ledger `feather-cpu` "OS", schedule `feather-refresh-cw`. OS month: open `warning` "open in the OS", pulled `info`, allocated `success` with the remainder in `warning` when > 0. Ids: `charge-row-{id}`, `pricing-preview`, `import-preview`, `statement-{id}`, `statement-line-{id}`, `rule-row-{id}`, `schedule-row-{id}`.

## Out of scope for this slice
Billing the charges (6), the worker's hourly poll and schedule runs (8), the AI pass-through report page (7 — the function exists), the kernel's K19 cost tag.

## Proof (`tests/phase3/slice5/run.sh`; the fake kernel serves `/api/v1/ledger/periods.php` from its state)
- [ ] Charges: the hosting 180 at +20 % → 216; two million tokens at resale 15 → 30; approve (the budget alert when over 80 %); void with a reason; a billed one (slice 6's fixture, else skipped) refused; a Consultant → 403.
- [ ] Import: an Anthropic CSV of six rows mapped and previewed, two duplicates skipped, four drafts; the mapping saved on the provider and prefilled next time; the import deleted removes the drafts and keeps an approved one.
- [ ] Schedules: a monthly VM due twice runs twice (Sep, Oct) and refuses the third; the end date deactivates.
- [ ] The OS statement: the fake kernel's two months (one open, one closed); Pull keeps the closed one verbatim (hash, lines); the open one shows "open" and Allocate refuses it; rules 60/40 → the four drafts and the remainder to the cent; a rule added → Re-allocate keeps the approved charge, remakes the drafts, the remainder is the cent; rules over 100 % refused by name; the kernel down → 503 in words, nothing changed; a changed document for an allocated month refused.
- [ ] Who sees: Priya nothing of `/usage` (403, the menu hides it); Marco his engagements' charges with cost; Bea all.
- [ ] JSON mode: every handler under a signed action token; the Clerk's run token pulls and allocates (no pause), drafts a charge, runs a schedule; `usage_charge_approve` by an agent → 202 pending.
- [ ] 375 × 740 and 1280 × 800: the import's three steps usable on the phone; the statement's lines a table on the desktop and cards on the phone; every button ≥ 44 px; `scrollWidth` = viewport; no console errors; the registry reads 12 screens and 13 actions as built.

## Open questions
