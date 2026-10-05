# Build spec: clients, rates and the vocabulary — the CRUD exemplar for workers (slice 1)

What exists at the end: Billing keeps the clients and their contacts; the admin keeps the consulting roles, the rate cards and their
rates, the cost rates (the most guarded table), capacity, the activity types, the expense categories, the usage providers, the tax
rates, the settings and the numbering. Every screen is cards for named things and a full-page form; every save is a handler on the
kit's shape; every row is logged. This slice fixes the shape every later CRUD screen copies.
Schema: `clients`, `client_contacts`, `attachments`, `notes` (db/006); `consulting_roles`, `activity_types`, `expense_categories`,
`usage_providers`, `tax_rates`, `ct_settings`, `document_sequences`, `next_document_number()` (db/005); `rate_cards`, `rate_card_rates`,
`member_cost_rates`, `member_capacity`, `ct_cost_rate()`, `ct_capacity()` (db/007); the views `mcp_clients`, `mcp_client_contacts`,
`mcp_consulting_roles`, `mcp_activity_types`, `mcp_expense_categories`, `mcp_usage_providers`, `mcp_tax_rates`, `mcp_rate_cards`,
`mcp_rate_card_rates`, `mcp_member_cost_rates`, `mcp_member_capacity`, `mcp_settings`, `mcp_attachments`, `mcp_notes` (db/015). Never
modify them. **The database is the referee**: one live name per client, card, role, type, category, provider and tax rate (the partial
unique indexes); cost rates and capacity never overlap for a person (EXCLUDE); the handlers translate a `23505`/`23P01` into its sentence.

## Screens (375 px first; cards for named things; a full-page form; no modals)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `client-list` | `/clients/` | cards: name, code, currency, terms, active engagements, open balance (`billing`); search; archived toggle |
| `client-add` / `client-edit` | `/clients/new`, `/clients/{id}/edit` | the form: name, legal name, code, email, phone, billing address, tax id, terms, currency, PO required, default rate card, notes |
| `client-view` | `/clients/{id}` | the card in full; **Contacts** (add/edit/remove inline rows — a full-page form on a phone); **Engagements** (slice 2 fills); **Invoices** and **Statement** (slice 6 fills; empty shapes now); documents; notes; the timeline |
| `client-statement` | `/clients/{id}/statement` | slice 6 fills; the placeholder now |
| `role-list` | `/settings/roles/` | the consulting roles as a sortable table: title, key, archived; add/edit inline (a form page on a phone) |
| `rate-card-list` / `rate-card-add` / `rate-card-view` / `rate-card-edit` | `/settings/rate-cards/…` | cards: name, currency, effective from, rate count, engagements using it; the view lists one row per consulting role with the rate editable in place (`rate_card_rate_save`); "Copy to a new card" |
| `cost-rate-list` | `/settings/cost-rates/` | people as cards, each with its dated ranges; add a range, end a range; **admin and Finance only** (403 in words otherwise, and the menu hides it) |
| `capacity-list` | `/settings/capacity/` | the same shape for hours a week; the settings' default shown where none is set |
| `activity-type-list`, `category-list`, `provider-list`, `tax-rate-list` | `/settings/…/` | tables with inline add/edit; archive; a provider's saved CSV mapping shown as JSON |
| `settings-general` | `/settings/general` | one form for `ct_settings` (every column but `id`); the morning-note recipient a member picker |
| `numbering` | `/settings/numbering` | the five kinds: prefix, next number, padding; a change confirms |

## Rules this slice enforces (beside the database's)
- A client's **code** is uppercase 2–8 (the form uppercases); its **currency** defaults to the base currency; **terms** default to the settings'.
- Archiving a client with an active engagement is refused in words ("SMOKE Acme has 2 active engagements; close them first."). Deleting a client
  needs no engagement at all and is `deletion`-paused for agents.
- A rate card's **currency** is fixed once an engagement uses it (the handler checks `engagements.rate_card_id`); its rates are per consulting
  role, one row each, editable in place; "Copy to a new card" copies the rates with a new name and effective date.
- A **cost rate** is in the base currency; a new range for a person **ends the open one** the day before (`cost_rate_save` does both in one
  transaction when `valid_from` falls inside an open range) — the EXCLUDE is the backstop, its refusal shown in words. The same for capacity.
- The vocabulary **archives, never deletes** once used: a role with a staffing, an activity with an entry, a category with an expense, a
  provider with a charge → the Delete button is absent and `*_archive` is the verb.
- **Numbering** may raise `next_value`, never lower it below the highest number issued (the handler checks the tables); a change is confirmed.

## Files (exactly these)
- `html/clients/index.php` · `form.php` · `view.php` · `statement.php` (placeholder) · `save.php` · `archive.php` · `restore.php` · `delete.php` · `statement-send.php` (placeholder: 501 until slice 6)
- `html/clients/contacts/save.php` · `remove.php`
- `html/settings/roles/index.php` · `save.php` · `archive.php`
- `html/settings/rate-cards/index.php` · `form.php` · `view.php` · `save.php` · `rate.php` · `archive.php` · `delete.php`
- `html/settings/cost-rates/index.php` · `save.php` · `end.php`
- `html/settings/capacity/index.php` · `save.php`
- `html/settings/activity-types/index.php` · `save.php` · `archive.php`
- `html/settings/categories/index.php` · `save.php` · `archive.php`
- `html/settings/providers/index.php` · `save.php`
- `html/settings/tax-rates/index.php` · `save.php` · `archive.php`
- `html/settings/general.php` (GET form + POST `settings_save`) · `html/settings/numbering.php` (GET + POST `numbering_save`)
- `app/features/clients/{queries,present,write,handler}.php` · `app/features/rates/{queries,present,write}.php` · `app/features/vocabulary/{queries,present,write}.php` · `app/features/settings/{queries,present,write}.php` (Phase 2's grows)
- `app/views/clients/{index,form,view,partials/client-card,partials/contacts}.php` · `app/views/settings/{roles,rate-cards/index,rate-cards/form,rate-cards/view,cost-rates,capacity,activity-types,categories,providers,tax-rates,general,numbering}.php`

## Query functions (signatures fixed)
- `find_clients(PDO, array $filters, int $limit = 100): array` (`mcp_clients`; `q`, `archived`) · `find_client(PDO, int $id): ?array` (+ contacts, engagement counts) · `find_client_contacts(PDO, int $clientId): array`
- `save_client(PDO, ?int $id, array $fields, int $by): int` · `archive_client(PDO, int $id, bool $archive, int $by): void` · `delete_client(PDO, int $id, int $by): void` · `save_client_contact(PDO, ?int $id, int $clientId, array $fields, int $by): int` · `remove_client_contact(PDO, int $id, int $by): void`
- `find_consulting_roles(PDO, bool $withArchived = false): array` · `save_consulting_role(...)` · `archive_consulting_role(...)` — the same trio for `activity_types`, `expense_categories`, `usage_providers`, `tax_rates`
- `find_rate_cards(PDO, bool $withArchived = false): array` · `find_rate_card(PDO, int $id): ?array` (+ rates per role, engagements using it) · `save_rate_card(PDO, ?int $id, array $fields, ?int $copyOf, int $by): int` · `save_rate_card_rate(PDO, int $cardId, int $roleId, ?float $rate, int $by): void` · `archive_rate_card(...)` · `delete_rate_card(...)`
- `find_cost_rates(PDO): array` (every person's ranges; `mcp_member_cost_rates`) · `save_cost_rate(PDO, ?int $id, int $memberId, float $cost, string $from, ?string $to, ?string $note, int $by): int` (ends the open range) · `end_cost_rate(PDO, int $id, string $to, int $by): void`
- `find_capacity(PDO): array` · `save_capacity(PDO, ?int $id, int $memberId, float $hours, string $from, ?string $to, int $by): int`
- `find_settings(PDO): array` · `save_settings(PDO, array $fields, int $by): array` (changed only) · `find_numbering(PDO): array` · `save_numbering(PDO, string $kind, array $fields, int $by): void`
- `store_attachment(PDO, string $recordType, int $recordId, array $file, int $byMember, bool $clientVisible = false): int` · `attachment_path(PDO, int $id): ?array` · `delete_attachment(PDO, int $id): array` (Help Desk's, generalised; in `app/features/documents/write.php`) · `add_note(PDO, string $recordType, int $recordId, string $body, int $by): int`

## Handlers (every one: `ct_handler_begin()`; the right; `ct_guard()`; `log_activity`; `ct_done()`; `HX-Trigger: {entity}Changed`)
- `clients/save.php` (`client_save` / `client_update`): `require_right('clients.manage')`; create logs `client.create` (`after`: name, code, currency, terms_days, po_required, rate_card_id — never email or phone); update `client.update` (changed only); location `/clients/{id}`.
- `clients/archive.php` / `restore.php` / `delete.php`: `clients.manage`; delete `require_admin()`; refusals in words; `client.archive` / `client.restore` / `client.delete`.
- `clients/contacts/save.php` / `remove.php`: `clients.manage`; `client_contact.save` / `client_contact.remove` (name, role, receives_invoices — never the email).
- `settings/roles/*`, `activity-types/*`, `categories/*`, `providers/*`, `tax-rates/*`: `require_admin()`; `*.save` (changed fields), `*.archive`.
- `settings/rate-cards/*`: `require_right('rate_cards.manage')`; delete `require_admin()`; `rate_card.save` (name, currency, effective_from, copy_of), `rate_card.rate` (role_id, bill_rate), `rate_card.archive`, `rate_card.delete`.
- `settings/cost-rates/*`: `require_admin()`; `cost_rate.save` / `cost_rate.end` — **the payload carries member_id and the dates, never the rate**.
- `settings/capacity/save.php`: `require_admin()`; `capacity.save` (member_id, hours_per_week, dates).
- `settings/general.php`, `settings/numbering.php`: `require_admin()`; `settings.save` (changed only), `numbering.save` (kind, before/after prefix and next).

## Action manifest entries
Screens: `client-list`, `client-add`, `client-view`, `client-edit`, `client-statement` (placeholder), `role-list`, `rate-card-list`, `rate-card-add`, `rate-card-view`, `rate-card-edit`, `cost-rate-list`, `capacity-list`, `activity-type-list`, `category-list`, `provider-list`, `tax-rate-list`, `settings-general`, `numbering`.
Actions (26): `client_save`, `client_update`, `client_archive`, `client_restore`, `client_delete`, `client_contact_save`, `client_contact_remove`, `consulting_role_save`, `consulting_role_archive`, `rate_card_save`, `rate_card_rate_save`, `rate_card_archive`, `rate_card_delete`, `cost_rate_save`, `cost_rate_end`, `capacity_save`, `activity_type_save`, `activity_type_archive`, `expense_category_save`, `expense_category_archive`, `usage_provider_save`, `tax_rate_save`, `tax_rate_archive`, `settings_save`, `numbering_save`, `attachment_add` / `attachment_delete` / `note_add` / `note_delete` (the generic ones, built here, used everywhere). `statement_send` is slice 6's. `PARTIAL_UPDATE_TARGETS` gains `/clients/save.php => ['clients', 'client', 'mcp_clients', 'client_id']`.

## Activity log events (every client row: `client_id`)
`client.create|update|archive|restore|delete`, `client_contact.save|remove`, `consulting_role.save|archive`, `rate_card.save|rate|archive|delete`, `cost_rate.save|end`, `capacity.save`, `activity_type.save|archive`, `expense_category.save|archive`, `usage_provider.save`, `tax_rate.save|archive`, `settings.save`, `numbering.save`, `attachment.add|delete`, `note.add|delete`, `screen.view`.

## Status vocabulary
Archived: `secondary` chip "Archived". A client card: code chip, currency, terms "Net 30", PO chip when required. Ids: `client-card-{id}`, `client-contacts`, `rate-card-{id}`, `rate-row-{roleId}`, `cost-rate-{memberId}`, `settings-form`.

## Out of scope for this slice
Engagements (2), everything on the client's view that needs them (invoices, statement, open balance — slice 6), the statement's Send (6), the provider CSV import (5), the per-engagement role rates and overrides (2).

## Proof (`tests/phase3/slice1/run.sh`: the scratch database `ct_dev3`, members through dev hand-offs, curl with signed action tokens, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
**Built and proven 2026-10-05: 259 checks green** (rights 10, clients 54, rates 30, cost_rates 26, vocabulary 34, settings 24, json 25, browser 56; the registry matches the manifest — 23 screens and 34 actions built).
- [x] **Clients**: Bea adds Acme with a code and the Standard card; a second "Acme" → 422 in words; the code and currency arrive uppercase; Priya sees Acme's card (staffed on its engagement) without email, phone, address, tax id or notes and gets 403 on the form, 404 on a client she may not see; Marco (its manager) likewise without the email; archive with an active engagement refused ("SMOKE Acme has 1 active engagement; close them first.") and allowed once closed; the name is free again, restoring refused while taken; delete needs the admin and no engagement at all; a contact added, made primary (the old primary unset), changed keeping what it is not sent, removed; a document and a note on the client through the generic doors, logged with `client_id`, downloaded through the gate, a shell script refused, Priya refused to attach; the timeline in words.
- [x] **Rate cards**: the Standard card lists one row per live consulting role; five rates set in place and cleared; a copy carries the rates (`copy_of` logged); the currency changes freely until an engagement uses the card, then "The currency is fixed: 1 engagement uses this card. Copy it to a new card instead." (the form shows it read-only); delete refused in use (engagements or a client's default), archive and restore, an archived card's rates frozen, an unused card deleted by the admin.
- [x] **Cost rates**: the admin adds Priya's 70 from Jan 1 and 80 from Jul 1 — the first range ends; an overlap refused in words; the end runs into the next range refused; `ct_cost_rate()` answers by date; Ann (Finance) AND Bea (Billing) read the table (design §16 c: cost.view across every engagement), Marco and Priya get 403 in words and the menu hides it; the view itself answers a Manager nothing; **no log payload carries a rate** (a change is logged as `rate_changed: true`); capacity: the default, two ranges, the overlap refused, 200 h refused.
- [x] **Vocabulary**: a role (the key from the title), an activity (not billable), a category with a receipt override (cleared back to the settings') and a markup, a provider with a mapping (the log says `has_mapping`, never the content; the kernel's key unique), a tax rate (one default at a time; the default cannot be archived); archiving one in use keeps it on existing rows; the Delete button absent once used; the key free again after archiving.
- [x] **Settings and numbering**: the increment changed to 0.5 and shown on the next form; `settings.save` carries changed fields only; "Nothing changed" logs nothing; five bad fields named; Sunday weeks move `ct_week_start()`; the base currency changes until a cost rate is kept; the next invoice number raised to 1001 and handed out by `next_document_number()`, lowering below an issued one refused ("The next number cannot go below 1003: INV-01002 is already issued."); a prefix without a dash; `numbering.save` before/after.
- [x] **JSON mode**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}`; `_partial=1` keeps the client's, the contact's, the card's and the role's fields; 422 `{error: {code: invalid, fields, errors}}`; a Billing token refused the admin's writes; the Billing Clerk (an agent, run token + relay) adds a client with its row `source agent`, run 901, the kernel's request id; it may not delete, set a cost rate or open a person's screen.
- [x] **375 × 740 and 1280 × 800**: thirteen screens render with `scrollWidth` = viewport and every control ≥ 44 px; a client saved lands on it with the code uppercased; a contact added inline; a refused save in `#flash` with the form kept; a card priced in place; a cost-rate range; a role with its key; the settings; the search narrows by HTMX with the URL pushed; Billing reads the cost rates without the form; a Manager refused in the right's words; JavaScript off posts and lands; no console errors.

**Decisions taken while building (2026-10-05):**
- **`cost.table`** is a derived right the shell and the screen share: `cost.view` AND `ct_sees_all()` — the admin, Billing and Finance read the cost-rate table (design §16 c); a Manager's `cost.view` is their own margin, never the table. `has_right('cost.table')` computes it; the menu item names it, so the menu and the gate cannot disagree.
- The generic doors live where the manifest put them: `/engagements/attachments/save.php` · `delete.php`, `/engagements/notes/save.php` · `delete.php` (`record_type` + `record`); a file is served at **`/attachments/{id}`** through `html/attachments/view.php` (the `mcp_attachments` view decides), never by Apache from `storage/`. The record's writer rule is `record_writable()` in `app/features/documents/write.php`; every slice's record page uses `app/views/shared/{attachments,notes,timeline}.php`.
- A provider has an archive button too (the screen's own, logged `usage_provider.archive`; the manifest names no such action because the kernel's statement makes providers on sight).
- The vocabulary's five tables share one view (`app/views/settings/vocabulary.php`) and one handler body (`app/features/vocabulary/handler.php`); `delete=yes` on the archive endpoint deletes an unused row (the Delete button is absent once used).
- A client's contact is archived, never deleted (an invoice already sent keeps its address); removing it unsets primary and receives_invoices.
- `jsonb` keeps its own key order: a proof compares a mapping or a diff with `==` or sorted keys. PHP's `is_file()` is cached: a proof calls `clearstatcache()` after a delete.

## Open questions
