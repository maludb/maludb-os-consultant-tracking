# Build spec: invoicing (slice 6)

What exists at the end: billing drafts an invoice for one engagement and a period, pulls its approved unbilled items through the engagement's
grouping, edits the lines, issues it (numbered gapless; the PO rule; the not-to-exceed cap with an override reason), sends it by MaluMail with
the secure link, reminds, records payments as status, confirms a client's claim, credits and voids, writes off; the client opens the link, sees
the backing detail with shared receipts, prints, and says "I paid this". Every item is billed once; a void releases them.
Schema: `invoices`, `invoice_lines`, `invoice_line_items`, `credit_notes`, `credit_note_lines`, `credit_applications`, `invoice_payments`,
`payment_claims`, `invoice_links_secure`, `ct_item_billed()`, `ct_invoice_recalculate()`, `ct_invoice_add_unbilled()`, `ct_invoice_issue()`,
`ct_invoice_mark_sent()`, `ct_invoice_void()`, `ct_invoice_write_off()`, `ct_credit_note_issue()`, `ct_payment_claim_confirm()`,
`ct_secure_link_mint()`, `ct_secure_link_invoice()` (db/012); `ct_unbilled()`, `ct_unbilled_items()`, `ct_ar_aging()`, `ct_client_statement()`
(db/014); the views `mcp_invoices`, `mcp_invoice_lines`, `mcp_invoice_line_items`, `mcp_credit_notes`, `mcp_credit_note_lines`,
`mcp_credit_applications`, `mcp_invoice_payments`, `mcp_payment_claims`, `mcp_clients`, `mcp_client_contacts`, `mcp_engagements`,
`mcp_attachments` (db/015). Never modify them. **The database is the referee**: born a draft, lines only while draft, money per line, totals in one
function, billed once, the number on issue, the PO and NTE rules, issued immutable, paid derived, a void releasing, the link's hash and expiry.

## Screens (billing's screens designed at 1280 px, usable at 375 px; the client's secure page at 375 px first)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `unbilled` | `/billing/unbilled?as_of=&client=` | `ct_unbilled()` as cards per engagement: hours and amount, no-rate hours (`warning`), expenses, usage, milestones, total, oldest item; **Draft invoice** → `/invoices/new?engagement=&to=` |
| `unbilled-items` | `/billing/unbilled/{id}` | `ct_unbilled_items()` for one engagement: the items by date with person, description, quantity, amount, `no_rate` rows marked |
| `invoice-list` | `/invoices/?q=&client=&engagement=&status=&overdue=` | rows (cards on a phone): number, client, engagement, issue and due, total, paid, balance, status chip, overdue chip, open claims chip; a totals footer per currency |
| `invoice-add` | `/invoices/new?engagement=&from=&to=` | the form: engagement (managed), period, issue and due dates (the client's terms), PO (the engagement's), memo, terms text, internal note, **Add the unbilled items** (checked) |
| `invoice-view` | `/invoices/{id}` | the head; the lines with their backing items expandable (entries with person and date, expenses with the receipt chip, usage, milestones); payments, credits, claims; the link's state (live / rotated / expires — never the token) with **Rotate**; the client's preview; the actions by status — Edit (draft), Issue, Send, Remind, Record payment, Credit, Void, Write off, External ref; the timeline |
| `invoice-edit` | `/invoices/{id}/edit` | the draft's lines editor: regroup (re-run add-unbilled with another grouping after removing the time lines), add a fixed / adjustment / discount line, tax rate per line, reorder; each item removable (returns to unbilled); the totals strip live |
| `invoice-preview` | `/invoices/{id}/preview` | the invoice as the client sees it on the link (the same partial) |
| `due-to-draft` | `/billing/due` | `invoices_due_to_draft`: engagements whose billing date passed with WIP, accepted milestones not invoiced, engagements with an open draft |
| `credit-note-list` / `-add` / `-view` / `-edit` | `/credit-notes/…` | the credit notes; a draft against an invoice (`?invoice=`); lines; Issue; Apply to the invoice (amount); Unapply; Void |
| `payment-list` | `/payments/?client=&from=&to=` | payments recorded with their source (manual, claim, ledger) and Void |
| `claims` | `/billing/claims` | what clients said on the link: invoice, date, method, reference, amount, note; **Confirm** (an amount) / **Dismiss** |
| `ar-aging` | `/reports/ar-aging?as_of=` | `ct_ar_aging()` per client in the five buckets, a CSV |
| `client-statement` | `/clients/{id}/statement?from=&to=` | `ct_client_statement()` with the running balance; **Send** (slice 1's placeholder becomes real) |
| `invoice-secure` (public) | `/i/{token}` · `/i/{token}/print` · `/i/{token}/claim` · `/i/{token}/attachment/{id}` | the client's door (below) |

## Drafting and the lines
- **One engagement per invoice** (D9); the client is the engagement's; the currency the engagement's; the PO the engagement's unless typed.
- **Add the unbilled items** calls `ct_invoice_add_unbilled()` through the engagement's grouping (`time_grouping`, else the settings'): time lines per
  group with the backing entries; one line per expense (its receipt travels when `share_receipts_with_client`); one per usage charge; one per
  accepted milestone. The handler reports the counts and the **no-rate hours skipped** ("4.0 h on ENG-0042 have no rate — set one and re-approve").
- **The editor**: a `fixed` line (a deliverable at a price), an `adjustment` (± an amount, a reason), a `discount` (a negative line); tax rate per line
  from `tax_rates`; reorder; **remove a line** (its items return to unbilled) or **remove one item** (`invoice_item_remove` — the line's quantity and
  total are recomputed by the handler from the remaining items: hours summed, amount summed). The totals strip is `ct_invoice_recalculate()`'s truth.
- **Issue** (`ct_invoice_issue()`): `billing.write`; the PO rule and the NTE cap in the database's words; above the cap billing gives
  `nte_override_reason`; the number INV-00001…; the milestones marked invoiced; the link minted (`ct_secure_link_mint()`), the raw token held only
  for the outbox row. **money_out** for agents.
- **Send** (`invoice_send`): `ct_invoice_mark_sent()` + an outbox row per recipient (the contacts who receive invoices, else the client's email, or
  `to_email`): subject "Invoice INV-00042 — Acme pilot, March 2026", the body from `writing-to-clients`, the **link**; sent by slice 8's worker.
  **external_send**. **Remind** likewise (the body a courtesy), stamps `last_reminder_at`, `reminder_count`.
- **Payments as status** (D10): `payment_record` (date, amount, method, reference) → `invoice_payments`; the database derives partially paid / paid
  and refuses an overpayment; `payment_void` with a reason. A claim confirmed makes a payment with `source = 'claim'`; the ledger's receipts
  (slice 8's read) make payments with `source = 'ledger'`.
- **Credit notes**: a draft against an invoice (client, engagement, currency copied); lines; Issue (`CN-00001`); Apply to the invoice (never over what
  is owed); Unapply; Void (unapplied only). **Void** an invoice: nothing paid or credited; the items released, milestones back to accepted, the lines
  kept as text. **Write off**: the balance forgiven, the items stay billed.
- **External ref**: what the ledger calls it, set by hand or by slice 8's read; shown on the view and in `invoices_issued`.

## The client's door (`/i/{token}`, no session, the bare layout, 375 px first)
- `ct_secure_link_invoice($token)` → the invoice id or NULL (one page for every failure: "This link has expired. Ask the firm for a new one."); the
  view counts; `invoice.view` logged with `actor_client_id` = the client, `source = 'portal'`, never the token.
- The page: the firm's name and address (`ct_settings`), the client's legal name, the invoice number, dates, PO, the lines, the **backing detail**
  — time by day and person with the client-visible notes; expenses with a thumbnail link to `/i/{token}/attachment/{id}` when the engagement shares
  receipts (`client_visible` set on the receipt when the invoice was issued and the engagement says so); usage by provider and period; milestones —,
  payments received, the balance due, the terms text, the footer. **Never** a cost, a rate card's name, an internal note, another document.
- **Print** (`/i/{token}/print`): the same page in a print stylesheet, no chrome — the browser's "Save as PDF" is the PDF (D9).
- **"I paid this on … by …"** (`POST /i/{token}/claim`): date, method, reference, amount (optional), note (200 chars) → `payment_claims`; CSRF like the
  login form's (the anonymous session), rate-limited (5 a token an hour), logged `payment_claim.submit`; the page says "Thank you — we will confirm
  when it shows." Billing is told (`claim_submitted`).
- Reads base tables as the writer with no acting member (`$GLOBALS['__public_door'] = 'portal'`), like Help Desk's `/t/`.

## Files (exactly these)
- `html/billing/unbilled.php` · `html/billing/unbilled/view.php` · `html/billing/due.php` · `html/billing/claims.php` · `html/billing/claims/confirm.php` · `dismiss.php`
- `html/invoices/index.php` · `form.php` · `view.php` · `preview.php` · `save.php` · `add-unbilled.php` · `delete.php` · `issue.php` · `send.php` · `remind.php` · `void.php` · `write-off.php` · `external-ref.php` · `secure-link.php`
- `html/invoices/lines/save.php` · `remove.php` · `html/invoices/items/remove.php`
- `html/credit-notes/index.php` · `form.php` · `view.php` · `save.php` · `delete.php` · `issue.php` · `apply.php` · `unapply.php` · `void.php` · `html/credit-notes/lines/save.php`
- `html/payments/index.php` · `save.php` · `void.php`
- `html/reports/ar-aging.php` · `html/clients/statement.php` (real) · `html/clients/statement-send.php` (real)
- `html/i.php` · `html/i/print.php` · `html/i/claim.php` · `html/i/attachment.php`
- `app/features/invoices/{queries,present,write,lines,secure,handler}.php` · `app/features/credit_notes/{queries,present,write}.php` · `app/features/payments/{queries,present,write}.php` · `app/features/public/{queries,secure}.php` (base tables, no member)
- `app/views/invoices/{index,form,view,edit,preview,partials/invoice-row,partials/lines,partials/line-items,partials/totals,partials/link-state,partials/timeline}.php` · `app/views/credit-notes/{index,form,view}.php` · `app/views/payments/index.php` · `app/views/billing/{unbilled,unbilled-items,due,claims}.php` · `app/views/public/{invoice,print,claimed,expired}.php` · `app/views/reports/ar-aging.php` · `app/views/clients/statement.php`

## Query functions (signatures fixed)
- `find_invoices(PDO, array $filters, int $limit = 100, int $offset = 0): array` · `find_invoice(PDO, int $id): ?array` (+ lines, items with their records, payments, credits, claims, link state) · `find_invoice_by_number(...)`
- `create_invoice(PDO, int $engagementId, array $fields, int $by, bool $addUnbilled, ?string $through): array` (`id`, the add-unbilled counts) · `update_invoice(PDO, int $id, array $fields, int $by): array` · `add_unbilled(PDO, int $invoiceId, int $by, ?string $through): array` · `delete_invoice(...)`
- `save_invoice_line(PDO, int $invoiceId, ?int $lineId, array $fields, int $by): int` · `remove_invoice_line(PDO, int $lineId, int $by): array` · `remove_invoice_item(PDO, int $itemId, int $by): array` (recomputes the line)
- `issue_invoice(PDO, int $id, int $by, ?string $nteReason): array` (+ the link minted and the raw token for the outbox) · `send_invoice(PDO, int $id, int $by, ?string $toEmail, ?string $message): array` · `remind_invoice(...)` · `void_invoice(PDO, int $id, int $by, string $reason): array` · `write_off_invoice(...)` · `set_external_ref(...)` · `rotate_secure_link(PDO, int $id, int $by): string`
- `record_payment(PDO, int $invoiceId, array $fields, int $by, string $source = 'manual'): int` · `void_payment(...)` · `find_payments(...)`
- `find_claims(PDO, array $filters): array` · `confirm_claim(PDO, int $id, int $by, ?float $amount): array` · `dismiss_claim(PDO, int $id, int $by, ?string $reason): void`
- `find_credit_notes(...)` · `find_credit_note(...)` · `save_credit_note(...)` · `save_credit_note_line(...)` · `issue_credit_note(...)` · `apply_credit_note(PDO, int $id, int $invoiceId, float $amount, int $by): void` · `unapply_credit_note(...)` · `void_credit_note(...)`
- `unbilled(PDO, ?string $asOf, ?int $engagementId, ?int $clientId): array` · `unbilled_items(PDO, int $engagementId, ?string $asOf): array` · `invoices_due_to_draft(PDO, ?string $asOf): array` · `ar_aging(PDO, ?string $asOf): array` · `client_statement(PDO, int $clientId, ?string $from, ?string $to): array`
- `secure_invoice(PDO, string $token): ?array` (base tables: the invoice, lines, items with the client-visible detail, payments, the firm) · `secure_attachment(PDO, string $token, int $attachmentId): ?array` · `submit_claim(PDO, string $token, array $fields, string $ip): int`
- `queue_invoice_mail(PDO, int $invoiceId, string $kind, array $recipients, string $subject, string $body, string $rawLink): void` (outbox rows per recipient; the link only in the body)

## Handlers (every one: `ct_handler_begin()`; the gate; `ct_guard()`; `log_activity` with `invoice_id`, `engagement_id`, `client_id`; `ct_done()`; `HX-Trigger: invoiceChanged`)
- `invoices/save.php` (`invoice_draft` / `invoice_update`): `invoices.draft` + manage; `invoice.draft` (engagement_id, client_id, period, currency; the counts when `add_unbilled`) / `invoice.update`; location `/invoices/{id}`.
- `add-unbilled.php`: `invoice.add_unbilled` (the counts, the no-rate hours). `lines/save.php` / `lines/remove.php` / `items/remove.php`: a draft; `invoice.line_save|line_remove|item_remove` (kind, amount, currency). `delete.php`: a draft; **deletion**.
- `issue.php` (`invoice_issue`): `billing.write`; the verb; `invoice.issue` (number, total, currency, nte_override_reason); the link minted; **money_out**.
- `send.php` / `remind.php`: `billing.write`; `invoice.send` / `invoice.remind` (recipients' count, never addresses in the payload beyond the client's card); **external_send**.
- `void.php` / `write-off.php`: `billing.write`; `invoice.void` / `invoice.write_off` (amount = the balance, currency, reason); **money_out**. `external-ref.php`: `invoice.external_ref`. `secure-link.php`: `invoice.secure_link_rotate` (never the token).
- `payments/save.php` (`payment_record`): `billing.write`; `payment.record` (amount, currency, method, reference, source); **money_out**. `payments/void.php`: `payment.void`; **money_out**.
- `billing/claims/confirm.php` (`payment_claim_confirm`): `billing.write`; `payment_claim.confirm` (amount, currency); **money_out**. `dismiss.php`: `payment_claim.dismiss`; **other**.
- `credit-notes/*`: `billing.write`; `credit_note.draft|line_save|delete|issue|apply|unapply|void` (number, total, currency, invoice_id, reason); issue/apply/unapply/void **money_out**; delete **deletion**.
- `clients/statement-send.php` (`statement_send`): `billing.write`; the outbox rows; `client.statement_send`; **external_send**.
- `i.php`, `i/print.php`, `i/attachment.php`: GET, no session, `secure_invoice()`; `invoice.view` once per visit (`actor_client_id`). `i/claim.php`: POST, the anonymous CSRF, the rate limit, `payment_claim.submit`; **not an action**.

## Action manifest entries
Screens: `unbilled`, `unbilled-items`, `invoice-list`, `invoice-add`, `invoice-view`, `invoice-edit`, `invoice-preview`, `due-to-draft`, `credit-note-list`, `credit-note-add`, `credit-note-view`, `credit-note-edit`, `payment-list`, `claims`, `ar-aging`, and slice 1's `client-statement` made real; the public `invoice-secure`.
Actions (27): `invoice_draft`, `invoice_update`, `invoice_add_unbilled`, `invoice_line_save`, `invoice_line_remove`, `invoice_item_remove`, `invoice_delete`, `invoice_issue`, `invoice_send`, `invoice_remind`, `invoice_void`, `invoice_write_off`, `invoice_external_ref_set`, `secure_link_rotate`, `payment_record`, `payment_void`, `payment_claim_confirm`, `payment_claim_dismiss`, `credit_note_draft`, `credit_note_line_save`, `credit_note_delete`, `credit_note_issue`, `credit_note_apply`, `credit_note_unapply`, `credit_note_void`, `statement_send`. Agent approvals as the manifest says. `PARTIAL_UPDATE_TARGETS` gains `/invoices/save.php => ['invoices', 'invoice', 'mcp_invoices', 'invoice_id']`.

## Activity log events (every row: `invoice_id`, `engagement_id`, `client_id`)
`invoice.draft|update|add_unbilled|line_save|line_remove|item_remove|delete|issue|send|view|remind|void|write_off|external_ref|secure_link_rotate`, `payment.record|void`, `payment_claim.submit|confirm|dismiss`, `credit_note.draft|line_save|delete|issue|apply|unapply|void`, `client.statement_send`, `screen.view`. **A secure token, a client's address beyond the party card, a cost: never.**

## Notifications this slice queues (the sender is slice 8)
| Step | Who is told | Kind |
|---|---|---|
| issued and sent | the client's invoice contacts (email with the link) | `invoice` (outbox kind) |
| reminded | the same | `reminder` |
| the client opened the link | billing (the bell) | `invoice_viewed` |
| a claim submitted | billing | `claim_submitted` |
| paid | billing; the engagement's manager | `invoice_paid` |
| a statement sent | the client's invoice contacts | `statement` |

## Status vocabulary
Invoice status: draft `secondary`, issued `primary`, sent `info`, viewed `info` with `feather-eye`, partially_paid `warning`, paid `success`, void `dark`, written_off `danger`. Overdue chip `danger` "Overdue 12 d". Claims chip `warning` "1 claim". Line kind chips: time `feather-clock`, expense `feather-credit-card`, usage `feather-cpu`, milestone `feather-flag`, fixed, adjustment, discount. Link state: live `success`, rotated `secondary`, expired `dark`. Ids: `invoice-row-{id}`, `invoice-lines`, `line-{id}`, `line-items-{id}`, `totals-strip`, `link-state`, `claim-{id}`, `payment-{id}`, `secure-invoice`, `claim-form`.

## Out of scope for this slice
Sending the outbox and the ledger's receipts read (8); the PDF (Extended); online payment (Extended); the client's "my invoices" page (Extended).

## Proof (`tests/phase3/slice6/run.sh`; the fake MaluMail on :8306)
- [ ] Draft for Acme with Add unbilled: the counts (two time lines by person, four entries, two expenses, three usage), the no-rate hours named, the total; a second add adds nothing; remove one item → the line recomputed, the item WIP again; a fixed line and a discount; a tax rate per line; the draft's currency and PO from the engagement; a draft deleted burns no number.
- [ ] Issue: the PO rule refused in words, then with the PO; the NTE cap refused without a reason, a Manager cannot override, Bea's reason recorded; INV-00001; issued immutable (lines, totals, dates) in the database's words; the link minted, the raw token only in the outbox body; `invoice.issue` carries amount and currency.
- [ ] The client's door: the page with the backing detail (hours by day and person with client-visible notes, the receipt thumbnail served through `/i/…/attachment/`, usage, no cost, no internal note, no rate card name); print; a dead/unknown token → the one page; the claim form (CSRF, rate limit) → `payment_claims`, billing told; `invoice.view` with `actor_client_id` and `source = portal`; rotating kills the old link.
- [ ] Payments: the claim confirmed → partially paid; overpay refused; a second payment → paid (`paid_at`); void an invoice with payments refused; void a payment with a reason → the invoice reopens to partially paid; `payment.record` carries amount and currency.
- [ ] Fixed bid: M1's invoice INV-00002; void → the milestone accepted and WIP again, the lines kept as text; INV-00003 issued; a credit note drafted, lines, issued CN-00001, applied 2500 → owed 7500; unapply; apply again; write off with a reason; the aging and the statement agree with the proof's figures.
- [ ] Send and remind: the outbox rows per invoice contact with the link in the body, never the token in the log; the fake MaluMail (slice 8 sends; here the rows); a reminder increments the count.
- [ ] Who sees: Priya nothing of `/invoices` (403); Marco the invoices of his engagements and may draft, not issue (403 in words); Bea everything; Ann read-only (every POST 403).
- [ ] JSON mode: every handler under a signed action token; the Clerk's run token drafts and adds unbilled (no pause); `invoice_issue` and `invoice_send` by an agent → 202 pending.
- [ ] 375 × 740 and 1280 × 800: the secure page first-class on the phone (the detail collapsible per section, the claim button ≥ 44 px), the lines editor usable, `scrollWidth` = viewport, no console errors; the registry reads the slice's screens and actions as built.

## Open questions
