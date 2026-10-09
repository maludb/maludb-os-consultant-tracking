# The record picker (2026-10-09)

The owner's decision (the builder plugin 0.8.0, `design-system/references/record-picker.md`; the plan in the plugin's `docs/record-picker-plan.md`,
D1–D12 as recommended): a form chooses a **record** — a client, an engagement, a person — through one searchable modal, never a `<select>` that lists
every row. ProcessCore's purchase order was the exemplar and the cidery the first retrofit; this is the third, mechanical from the reference.

- **Added:** `app/picker.php` (`picker_field()`, `picker_label()`, `picker_source()`, and `picker_result()` for a source's page), `app/pickers.php`,
  `html/pick/index.php`, `app/views/shared/picker-rows.php`, `html/assets/js/record-picker.js`, the RECORD PICKER section of `app-overrides.css`, the
  `#record-picker` modal and the script in `layout.php`. This shell has no unsaved-changes guard, so none was changed. The application writes form rows
  by hand, so there is no `form_picker()`: each field calls `picker_field()` inside its own label markup, the label carrying `id="{id}-label"` and
  `for="{id}-open"`. New paged queries beside the feature's own: `find_clients()` takes an offset, `count_clients()`, `find_live_clients_page()`,
  `draftable_engagements_page()`, `loggable_engagements_page()`, `usage_engagements_page()`, `members_page()`.
- **The sources** — one per record kind and scope, because what a person may pick is what they may see (a consultant staffed on two engagements is
  offered those two, never the firm's). The endpoint answers 401 / 404 / 403 as fragments (never a redirect into the modal); the rights are the shell's
  `nav_has_right()`:

  | Source | Offers | Rights | Params | Used by |
  |---|---|---|---|---|
  | `client` | the clients the caller may see (`mcp_clients`) | engagements.view, clients.manage, invoices.draft, reports.view, billing.write | — | invoice / payment / unbilled / engagement-list filters, credit note form, report filters |
  | `client_live` | every live client by name | engagements.manage | — | the engagement form (the view guards details, not names, so a manager can open an engagement for a client none of whose engagements they see yet) |
  | `engagement` | the engagements the caller may see, any status | engagements.view, invoices.draft, reports.view, reports.view_own, usage.write | `client_id` (narrows; declared and proven, no screen needs it today) | invoice and usage list filters, report filters |
  | `engagement_draftable` | where an invoice may be drafted | invoices.draft, billing.write | — | the invoice form |
  | `engagement_loggable` | `ct_can_log_on(engagement, member, day)` | time.write, expenses.write | `member_id`, `on` (YYYYMMDD) | the time entry form, the timer bar, the expense form |
  | `engagement_managed` | live engagements the caller manages | usage.write | — | usage charge form, usage import, allocation rule, hosting schedule |
  | `member` | admitted people and agents; `human=1` drops the agents | settings.manage, cost.table, staffing.write, milestones.write, engagements.manage, reports.view | `human` | manager, morning-note recipient, capacity, cost rate, staffing, milestone acceptance, report filters |

- **Converted (the hidden input keeps each select's name and id, so no handler, `hx-include`, smoke or manifest row changed):** see the table in the
  commit message; an editing form that disabled its select (capacity, cost rates, staffing) disables the hidden input too, so it posts nothing, as before.
  The timer bar's select preselected the first engagement; its picker starts on the first engagement too.
- **Still selects, by the rule (D7):** the vocabulary lists (consulting roles, rate cards, activity types, expense categories, tax rates, providers,
  practices, kinds, statuses, units, cadences, weekdays, time zones). **Owed:** the credit note's "apply to invoice" choice (a client's open invoices — a
  short dependent list; an `invoice` source with a `client_id` param when it grows).
- **Found:** the plugin's `picker_field()` wrote "Choose a engagement" — the article is now chosen by the noun's first letter.
- **Proven:** `tests/picker/run.sh` on a scratch database (the endpoint's refusals, visibility per person, search, escaping, paging, the dependent param,
  every converted form rendering its picker under the old name and id, a 422 keeping the chosen label, a time entry and a staffing saved through the
  hidden inputs) and a headless-Chromium pass (`tests/picker/browser.mjs`: keyboard, Show more, Clear, a list filter refreshing through the
  picker's change event, a time entry saved from a phone, 375 px full-screen dialog, dark mode). The slices' own proofs stay green.
