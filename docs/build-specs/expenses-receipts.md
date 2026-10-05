# Build spec: expenses and receipts (slice 4)

What exists at the end: a consultant snaps a receipt on a phone and records the expense against an engagement; the database refuses to
submit it without a receipt at or above the threshold; a manager approves or rejects; an approved personal-card expense is a reimbursement
HR pays (the share, slice 8) and billing marks paid until then; an approved billable expense is WIP for slice 6 at the engagement's markup.
Schema: `expenses`, `ct_receipt_threshold()`, `ct_expense_has_receipt()`, `ct_expense_guard()`, `ct_expense_submit()`, `ct_expense_approve()`,
`ct_expense_reject()`, `ct_expense_mark_reimbursed()` (db/010); `attachments` (db/006); `expense_categories` (db/005); the views
`mcp_expenses`, `mcp_expense_categories`, `mcp_attachments`, `mcp_engagements` (db/015). Never modify them. **The database is the referee**:
the number, the currency, the policy markup, the receipt rule on submit, staffing, the lock once approved, the status moves through the verbs.

## Screens (375 px is the design)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `expense-list` | `/expenses/?status=&engagement=&member=` | my expenses as cards: number, date, category, engagement, amount with currency, the receipt chip (present / **missing** `danger` when required), status chip, billed chip, reimbursed chip; tabs by status; a manager's `?member=` |
| `expense-add` / `expense-edit` | `/expenses/new?engagement=&date=`, `/expenses/{id}/edit` | **the camera first**: `<input type="file" accept="image/*,application/pdf" capture="environment">` as the top field with the thumbnail once chosen; then engagement (staffed today), date, category, amount, tax inside, vendor, description, paid with (personal / company card), billable, markup (the policy's, editable by a manager), for someone (a manager); **Save** and **Save and submit** |
| `expense-view` | `/expenses/{id}` | the receipt large (inline image or PDF link, served through the gate), the money (amount, tax, markup → billable amount), the engagement, status with dates and reasons, Submit / Approve / Reject / Mark reimbursed by right, documents, notes, the timeline |
| `expense-approvals` | `/expenses/approvals?engagement=&missing_receipt=` | submitted expenses on the engagements I manage, oldest first: the receipt thumbnail beside the amount, Approve / Reject (reason) on each |
| `reimbursements` | `/expenses/reimbursements?member=` | per person the approved personal-card expenses not yet reimbursed with the total per currency; **Mark reimbursed** (one, or all of a person) with a reference; what HR read (slice 8) |
| `company-card` | `/expenses/company-card?from=&to=` | approved company-card spend by category and month — the shape the ledger reads |

## Rules this slice enforces (beside the database's)
- The **receipt threshold** that applies is `ct_receipt_threshold(category, engagement)` — the form shows it ("A receipt is required at or above 75.00");
  Save works without one; **Submit** calls the verb and shows its sentence when the receipt is missing. The receipt is an `attachments` row
  (`record_type = 'expense'`), stored before the submit in the same request when the form carried a file.
- **Markup** defaults in the database (category → engagement policy → settings); the form shows the resulting billable amount live (a small script)
  and the handler posts what it shows; a Consultant may not change the markup (the field is read-only for them; a forged value is ignored and logged).
- **Paid with** decides reimbursement: personal → reimbursable; company card → not. **Billable** defaults from the category.
- **For someone else** (`member`): a manager of the engagement; the expense is theirs; the log says who recorded it.
- **Approve / Reject**: the engagement's manager (`expenses.approve` + manage); never one's own; rejecting an approved expense is allowed until it is
  billed or reimbursed (the verb refuses after); the reason goes to the person.
- **Mark reimbursed**: `billing.write`; one or all of a person's with one reference; slice 8's HR read calls the same verb.
- **Edit** a draft or rejected expense freely; a submitted one's money is locked (the sentence); an approved one is locked.

## Files (exactly these)
- `html/expenses/index.php` · `form.php` · `view.php` · `approvals.php` · `reimbursements.php` · `company-card.php`
- `html/expenses/save.php` · `delete.php` · `submit.php` · `approve.php` · `reject.php` · `reimburse.php`
- `html/expenses/attachments/get.php` (the receipt through the gate; slice 1's generic `attachment_add` serves `expense`)
- `app/features/expenses/{queries,present,write,handler}.php`
- `app/views/expenses/{index,form,view,approvals,reimbursements,company-card,partials/expense-card,partials/receipt,partials/timeline}.php`

## Query functions (signatures fixed)
- `find_expenses(PDO, array $filters, int $limit = 100, int $offset = 0): array` (`mcp_expenses`; `member_id`, `engagement_id`, `status[]`, `from`, `to`, `missing_receipt`, `q`) · `find_expense(PDO, int $id): ?array` (+ receipt attachments, notes)
- `save_expense(PDO, ?int $id, array $fields, int $memberId, int $by, ?array $file): int` (stores the receipt in the same transaction when given) · `delete_expense(PDO, int $id, int $by): void`
- `submit_expense(PDO, int $id, int $by): array` · `approve_expense(...)` · `reject_expense(PDO, int $id, int $by, string $reason): array` · `mark_reimbursed(PDO, array $ids, int $by, ?string $reference): int`
- `expenses_pending(PDO, int $approverId, array $filters): array` · `reimbursements_due(PDO, ?int $memberId): array` (grouped per person and currency) · `company_card_spend(PDO, string $from, string $to): array`
- `expense_timeline(PDO, int $id, int $limit = 100): array` · `queue_expense_notice(PDO, int $memberId, string $kind, int $expenseId, array $data): void`

## Handlers (every one: `ct_handler_begin()`; the gate; `ct_guard()`; `log_activity` with `engagement_id`; `ct_done()`; `HX-Trigger: expenseChanged`)
- `save.php` (`expense_save`): `expenses.write`; for another member → `expenses.approve` and manage; a Consultant's `markup_pct` ignored; `expense.save` (`after`: number, amount, currency, category_id, paid_with, billable, markup_pct, recorded_by; `attach` when a file came); location `/expenses/{id}`; `submit=1` from "Save and submit" calls `submit_expense()` after.
- `delete.php`: own or manage; draft or rejected; `expense.delete`; **deletion**. `submit.php`: own or manage; `expense.submit`; the `expense_submitted` notice to the managers.
- `approve.php` / `reject.php`: `expenses.approve` + manage; `expense.approve` (amount, currency, billable_amount) / `expense.reject` (reason); the notices; **other** for agents.
- `reimburse.php` (`expense_mark_reimbursed`): `billing.write`; `expense.reimburse` (amount, currency, reference) per expense; the `expense_reimbursed` notice; **other**.
- `attachments/get.php`: GET, the view decides (`mcp_attachments`), `nosniff`, inline for images and PDFs.

## Action manifest entries
Screens: `expense-list`, `expense-add`, `expense-view`, `expense-edit`, `expense-approvals`, `reimbursements`, `company-card`. Actions (6): `expense_save`, `expense_delete`, `expense_submit`, `expense_approve`, `expense_reject`, `expense_mark_reimbursed` (+ slice 1's `attachment_add`/`attachment_delete`/`note_add` for `expense`). Agent approvals: `expense_approve`, `expense_reject`, `expense_mark_reimbursed` (`other`); `expense_delete` (`deletion`). `PARTIAL_UPDATE_TARGETS` gains `/expenses/save.php => ['expenses', 'expense', 'mcp_expenses', 'expense_id']`.

## Activity log events (every row: `engagement_id`)
`expense.save|delete|submit|approve|reject|reimburse|attach`, `attachment.add|delete`, `note.add`, `screen.view`.

## Notifications this slice queues
| Step | Who is told | Kind |
|---|---|---|
| submitted | the engagement's manager | `expense_submitted` |
| approved / rejected | the person (the reason when rejected) | `expense_approved` / `expense_rejected` (text when chosen — K6, slice 8) |
| reimbursed | the person, with the reference | `expense_reimbursed` |

## Status vocabulary
Status: draft `secondary`, submitted `info`, approved `success`, rejected `warning`. Chips: receipt `feather-paperclip` (`danger` "Receipt needed" when required and missing), billed `dark` "Billed", reimbursed `success` "Reimbursed", company card `feather-credit-card`. Ids: `expense-card-{id}`, `receipt-{id}`, `expense-money`, `approvals-list`, `reimbursements-{memberId}`.

## Out of scope for this slice
Billing the expense (6 — the card shows "Billed" from `ct_item_billed()`), sending the notices and HR's read (8), receipt reading by a model (Extended), mileage and per-diem (Extended).

## Proof (`tests/phase3/slice4/run.sh`)
- [x] Priya records a 120.00 meal with a JPEG from the camera field (stored under `storage/attachments/expense/<id>/`, the mime from `finfo`, served inline with `nosniff`); EXP-000001 in USD with the 10 % policy markup → 132.00; **Save and submit** without the file → the threshold's sentence; with it → submitted; a 40.00 coffee on the company card submits without a receipt and is not reimbursable; an `.exe` receipt → 422; a 10 MB + 1 file → 422.
- [x] A Consultant posting `markup_pct=50` is ignored (10 stays) and the log notes it; a manager's 50 is kept.
- [x] Marco approves both (the notices); self-approval refused; Dana (no right) → 403; an approved expense's money locked in words; rejection with a reason returns it editable; a billed one (slice 6's fixture, else skipped) is not rejected.
- [x] Reimbursements: Priya's 132 shown as owed; Bea marks it with PAYRUN-2026-03 (the notice); the company-card one is not offered; Priya sees her own "owed" total; the company-card page sums by category and month.
- [x] Who sees: Priya her own; Marco everything on his engagements with receipts; Bea all; the receipt of Dana's expense → 404 for Priya.
- [x] JSON mode: every handler under a signed action token; the expert's run token records and submits an expense for the acting person; `expense_approve` by the agent → 202 pending.
- [x] 375 × 740 and 1280 × 800: the camera field first and large on the phone, the thumbnail, cards; every button ≥ 44 px; `scrollWidth` = viewport; no console errors; JavaScript off still saves and submits; the registry reads 7 screens and 6 actions as built.

**Built and proven 2026-10-05: 223 checks green** (record 31, receipt 13, markup 17, approve 36, reimburse 31, visibility 31, partial 6, json 18, browser 40; `tests/phase3/slice4/run.sh`; the registry reads 46 screens and 68 actions built; Phase 2's browser follows the built Expenses tab; all five suites green: 247, 259, 173, 195, 223).

**Decisions taken while building (2026-10-05):**
- **Save and submit is all or nothing**: the save, the receipt and the submit run in one transaction; a refused submit saves nothing (no duplicate on a retry, no gap in the numbering) and a receipt stored for it is taken away again. The sentence is the verb's, shown in `#flash`.
- **Owed is the amount paid** (120.00), not the billable amount (132.00 — what the client is billed with the markup); the proof's "132" is the billed figure.
- The receipt's `client_visible` = the engagement's `share_receipts_with_client`, else the settings' `share_receipts_default` (nothing in db/012 decides it yet).
- A manager's `markup_pct` of 0 on a NEW expense lets the database's policy apply (the guard resolves 0 to the default); a Consultant's value is ignored unless it differs from the policy's (the form posts the policy's own) — then the log carries `markup_ignored`.
- `expense_approve` by an agent answers 200 on this door, like `timesheet_approve`; the kernel's hook pauses it as `other` on the MCP path (Phase 4), so the proof's "202 pending" is Phase 4's.
- The reimbursement list takes ticks (`expenses[]`) with one reference; `member=` pays all a person is owed; any refusal rolls back all. `mark_reimbursed()` answers the count.
- A deleted draft takes its receipts (rows and files) and notes with it. The expense timeline leaves out `screen.view` rows. `queue_expense_notice()` is a sibling of `queue_time_notice()` with record type `expense`.
- `/expenses/attachments/get.php` requires the same door as `/attachments/{id}`. Reimbursements and the company card read through `mcp_expenses`; the company-card page is `billing.write` or `reports.view`.
- The dev environment's attachment limit is 5 MB, so "10 MB + 1" is proven as the limit + 1 byte; JavaScript-off buttons are clicked with `force` (the page is mid-animation).

## Open questions
