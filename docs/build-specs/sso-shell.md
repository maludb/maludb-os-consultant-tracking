# Build spec: Phase 2 — sign-on, the mirror, the shell (before any slice)

What exists at the end: a person clicks Consultant Tracking on the launcher — or types `consulting.<domain>` on a phone — and lands,
signed in, on a home screen that looks finished though it holds no engagement yet; the mirror refreshes every minute; every request
is logged; the command bar answers through the kernel. The kit is in the repo already (Phase 0: General Ledger's un-ledgered identity,
roles and sign-on files, the MCP common files, the HTTP helper and sync) and is proven without a kernel (`tests/phase0/run.sh`, 15
checks); this phase wires the shell around it, installs it beside the kernel and proves it.
Schema: `members`, `departments`, `department_members`, `directory_sync_state`, `sso_nonces`, `member_sessions` (db/001), `activity_log`,
`activity_ingest_state` (db/002), `mcp_access_tokens` + `mcp_resolve_token()` (db/003), `ct_rights`, `ct_roles`, `ct_role_rights`,
`ct_has_right()`, `ct_sees_all()`, `ct_is_admin()`, `mcp_app_roles` (db/004), `ct_settings` (db/005), `notification_prefs`,
`notifications` (db/013), `mcp_members`, `mcp_departments`, `mcp_settings`, `mcp_notifications`, `mcp_my_tokens`, `mcp_activity_log`
(db/015). Never modify them.

## Files
**Already in the repo (Phase 0, proven):** `app/bootstrap.php` (env from `config/.env` or real environment variables, session cookie
`CTSID`, `json_mode_begin()`, the acting member on the PDO connection, the action-token / approval-replay path, the per-request mirror
re-check), `app/db.php`, `app/http.php`, `app/auth.php` (`has_right()`, `require_right()`, `is_ct_admin()`, `require_admin()`,
`can_see_engagement()`, `require_can_see_engagement()`, `can_manage_engagement()`, `require_can_manage_engagement()`, `require_visible()`,
the verifiers), `app/directory.php` (the mirror appliers incl. `mirror_apply_roles()` and `access[]`), `app/activity.php` (`log_activity()`
with `engagement_id`, `client_id`, `invoice_id`, `timesheet_id`, `actor_client_id`), `app/handler.php` (`ct_handler_begin()`, `ct_guard()`,
`ct_refuse_fields()`, `ct_done()`, `req_val()`, `ct_int()`, `ct_ref()`, `ct_yes()`, `ct_diff()`), `app/partial_update.php`, `app/mail.php`,
`app/api/bootstrap.php`, `app/features/shell/nav.php` (the one menu table), `html/sso.php`, `html/sso/logout.php`, `html/api/v1/health.php`,
`app/views/sso/refused.php`, `bin/directory_sync.php` (`--full`, `--from-file`), `bin/mint_mcp_token.php`, `bin/build_action_registry.php`,
`bin/dev_handoff.php` + `bin/dev_directory.json`, `mcp/activity_ingest.py`, `tests/dev_router.php`, `tests/setup_dev.sh`, `deploy/*` templates.
**Phase 2 adds:** `app/views/layout.php` (the nxl shell, phone first), `app/views/shared/{assistant-bar,tab-bar,flash,message,page-header}.php`,
`app/views/home/dashboard.php`, `html/index.php` (screen `dashboard`), `html/login.php` (302 to `OS_LAUNCHER_URL?app=consultant_tracking`),
`html/logout.php` (own sign-out: POST + CSRF, end this session, back to the launcher), `html/notifications.php` + `html/settings/notifications/read.php`,
`html/settings/index.php` (screen `settings`) + `html/settings/prefs.php`, `html/settings/tokens/index.php` + `tokens/mint.php` + `tokens/revoke.php`,
`html/activity.php`, `html/assistant/ask.php` (the bar's POST → the kernel's chat endpoint), `app/features/home/queries.php`,
`app/features/settings/{queries,present}.php`, `app/features/activity/{queries,present}.php`, `html/assets/` (the design system's
`examples/assets/` verbatim + `htmx.min.js`), `html/manifest.webmanifest` + two icons (installable on a phone; no service worker, no push),
`tests/phase2/` (the proofs: `servers.sh`, `lib.php`, `sso.php`, `sync.php`, `gates.php`, `ingest.php`, `vhost.php`, `browser.mjs`, the fakes
`tests/fake_kernel.php`, `tests/fake_maludb.php`, `tests/fake_malumail.php`; ports 8301–8307). Every other menu item of `nav.php` answers a
placeholder page saying which slice builds it (200 with an empty state, or 403 by the right); the registry builder reads a placeholder as unbuilt
(`render_nav_stub()` in `nav.php`).

## Consultant Tracking is not Help Desk
- **Unscoped**, like General Ledger: the claims carry `capability`, `roles[]`, `rights[]`; `/sso` writes `members.roles` through
  `mirror_apply_roles()` (only keys `ct_roles` knows); there is no site, no switcher. The session re-check is the mirror row (`status =
  'active'`, `capability IS NOT NULL`) and `member_sessions` — nothing else.
- **Roles from db/004.** A member whose roles are still `{}` reads from the capability: `admin` → admin, else `user` (`ct_has_right()`). The
  shell's badge shows the highest role held (CT admin › Billing › Finance › Manager › Consultant).
- **Three kinds of person reach the shell differently**: a member through the launcher (this phase); a client through the secure invoice link
  (slice 6, no session, the bare `(public)` layout); an agent never sees HTML — the kit's action-token path answers JSON.
- **The public door is rewritten by the vhost already** (`/i/{token}`, `/claim`, `/print`, `/attachment/{id}`); in Phase 2 its handlers do
  not exist and the rewrites land on 404.

## The receiver (`/sso`) — in order (as the kit does it)
1. `verify_sso_token($token, APP_KEY)` and `verify_sso_claims($claims)` — constant-time, both must pass, member ids must match.
2. Expiry, audience (`consultant_tracking`), then the nonce: `INSERT INTO sso_nonces … ON CONFLICT DO NOTHING`; zero rows = replay → refuse.
3. In one transaction: `mirror_apply_member()` (the row with `capability`), `mirror_apply_roles()` from `claims.roles`.
4. Open the session: `session_regenerate_id(true)`, `$_SESSION['member_id']`, fresh CSRF token; `member_sessions` row.
5. `log_activity('member.sign_on', 'member', $id, ['after' => ['capability', 'roles']])`; redirect to `/`.
6. Any failure: one page `sso/refused.php` (*"This sign-on link has expired. Open Consultant Tracking from app.<domain> again."*),
   `member.sign_on.refused` with `after.reason` (token, claims, status, capability, replay, mirror) — never shown.

## Every request (bootstrap, as the kit does it)
- Cookie `CTSID`, `SameSite=Lax`, `Secure` when HTTPS, `HttpOnly`, strict mode. A signed-in request re-checks the mirror row and the session
  list; otherwise the session is destroyed (`ended_by = 'directory'`) and the visitor sent to the launcher with `?app=consultant_tracking`.
- Action token (`X-Action-Token` + `X-Action-Relay`, or `X-Approval-Replay`): acts as that member for one request; `Set-Cookie` removed;
  an id with no mirror row is refused and logged `member.refused`; a known, active agent with no capability is admitted at first contact
  only when the kernel's run-facts call vouches for it. `SET app.member_id` before any query; `log_screen_view()` on every rendered GET.
- `/sso/logout` (POST from the kernel): `verify_sso_logout_notice` → every session of the member ended (`ended_by = 'kernel'`); 204 always.

## The shell
- Design-system skeleton, exactly. **Phone first**: 375 px is the design. The bottom tab bar carries **Home, Time, Expenses, Engagements,
  More**; the header shows the person's name and role badge, the bell (slice 8 fills it) and the command bar; `#page-content` is the HTMX
  target; CSRF meta + `htmx:configRequest` listener; `htmx:afterSwap` re-init; the layout re-stamps `data-screen`/`data-entity`/`data-record-id`
  from `X-Screen`/`X-Entity`/`X-Record-Id` (app/http.php).
- Sidebar from 992 px (and as **More** on a phone): the five groups of `nav_groups()` — **Work** (Home, My timesheet, My expenses, Engagements,
  Clients), **Manage** (Time approvals, Team time, Missing time, Expense approvals, AI usage and hosting, The OS statement, Budgets), **Billing**
  (Unbilled, Invoices, Credit notes, Payments, Payment claims, Who owes us, Reimbursements), **Reports** (Reports, Exports), **Settings**. An item
  shows only when the person holds its right (`|` = any one). A Consultant therefore sees Work and My settings alone.
- **The command bar** (`#assistant-bar`, chat-actions): `html/assistant/ask.php` POSTs `{utterance, screen, context, conversation_id}` to
  `{OS_INTERNAL_URL}/api/v1/agents/chat.php?agent=expert` with `OS_APPLICATION_TOKEN` and `X-Acting-Member`; renders `reply`, lists `actions`
  (a paused one as "waits for approval"), follows nothing blindly; `wait` 60, then `GET ?run=` while `finished` is false; logs `assistant.ask`
  (the utterance's length and the run id, never the text). A kernel refusal (403/404/409) is shown in the kernel's words.
- Home (`/`, screen `dashboard`): the regions as empty states naming the slice that fills them — **My week** and the timer (slice 3),
  **My expenses** (slice 4), **Approvals waiting** (`time.approve|expenses.approve`; slices 3–5), **Budget alerts** (`engagements.manage`;
  slice 2), **Billing** (`invoices.draft|billing.write`: WIP, drafts, due to draft, aging, the OS statement; slices 5–7), **The Clerk's note**
  (slice 8) — and the **Log time** button (`time.write`, → `/time/entries/new`, 404 until slice 3 with the placeholder page).
- `/settings/` (screen `settings`): tab **How I am told** — email on/off, text on/off (a note that texts need a phone verified in the OS, linking
  `<OS_LAUNCHER_URL>/settings/channels`), the event kinds as checkboxes, the text kinds — `prefs_save` (upsert `notification_prefs`).
- `/settings/tokens/` (screen `tokens`): my tokens as rows (label, scope, last used, expiry); **Mint** shows the raw value once; **Revoke** confirms.
- `/notifications` (screen `notifications`): the empty shape ("Nothing yet"), `notification_read` wired (marks one or all).
- `/activity` (screen `activity`): the member's own trail from `mcp_activity_log`, 50 a page; `?engagement=`, `?invoice=`, `?timesheet=` are the
  slices' record trails.

## Query functions (signatures fixed)
- `home_summary(PDO, int $memberId): array` — the dashboard's regions as empty shapes now, filled by the slices (each slice adds its key:
  `my_week`, `timer`, `my_expenses`, `approvals`, `budget_alerts`, `billing`, `clerk_note`).
- `find_prefs(PDO, int $memberId): array` (defaults when no row) · `save_prefs(PDO, int $memberId, array $prefs): void`
- `find_my_tokens(PDO, int $memberId): array` · `mint_token(PDO, int $memberId, string $label, string $scope): string` (raw, shown once) ·
  `revoke_token(PDO, int $memberId, int $tokenId): bool`
- `find_my_notifications(PDO, int $memberId, bool $unreadOnly, int $limit = 50): array` · `mark_notifications_read(PDO, int $memberId, ?int $id): int`
- `find_my_activity(PDO, int $memberId, array $filters, int $limit = 50, int $offset = 0): array` (from `mcp_activity_log`; worded by `present.php`)
- `assistant_ask(PDO, int $memberId, string $utterance, array $context): array` (the kernel call; the dispatch row is the slices')

## Handlers (every one: `require_post(); verify_csrf();` the gate; one transaction; `log_activity`; `emit_action_status`)
- `settings/prefs.php` (`prefs_save`): own; `prefs.save`. `settings/notifications/read.php` (`notification_read`): own; `notification.read`.
- `settings/tokens/mint.php` (`token_mint`): own, a human (`require_human()`); the raw token in the response once; `token.mint` (label, scope).
  `tokens/revoke.php` (`token_revoke`): own; `token.revoke`.
- `logout.php`: POST + CSRF; `end_session(db(), 'member')`; `member.sign_out`; 302 to the launcher.
- `assistant/ask.php`: POST + CSRF; `require_human()`; the kernel call; `assistant.ask`; the reply as a partial.

## Action manifest entries
Screens: `dashboard`, `notifications`, `activity`, `settings`, `tokens`. Actions: `prefs_save`, `notification_read`, `token_mint`, `token_revoke`.
Every other screen of the manifest answers the placeholder.

## Activity log events
`member.sign_on`, `member.sign_on.refused`, `member.sign_out`, `member.refused`, `directory.sync`, `directory.sync.failed`, `screen.view`,
`prefs.save`, `notification.read`, `token.mint`, `token.revoke`, `assistant.ask`.

## Out of scope for this phase
Everything with a record: clients, engagements, time, expenses, usage, invoices, reports (slices 1–9); the outbox's sender and the worker
(slice 8); the two MCP servers (Phase 4).

## Proof (`tests/phase2/run.sh`: the scratch database, the fake kernel on :8302, fake MaluDB on :8303, fake MaluMail on :8306, the app on `php -S :8301` through
`tests/dev_router.php` — or a real Apache with `CT_APP=apache` —, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
**Built and proven 2026-10-05: 247 checks green** (sso 43, gates 81, sync 25, ingest 12, kernel_compat 9, vhost 26, browser 51; the registry matches the manifest);
the installer's `plan` reads the repository clean. The world the proofs reason about is `bin/dev_directory.json`: the Owner (super-admin, IT), Priya (Consultant,
AI Strategy), Marco (Manager, AI Strategy), Bea (Billing, Accounting), Ann (Finance — the finance role ALONE, external, read), Dana (Consultant, ML Engineering),
and the Billing Clerk (agent 40, billing).
- [x] **Sign-on**: a minted hand-off opens a session (302 to `/`), the mirror row carries capability and roles, `member.sign_on` logged with both; replay, another
  audience, a tampered signature, an unknown member, an inactive claim, a claim with no capability → the one page, each refusal logged with its reason; the sign-out
  notice ends every session (204 both ways); roles {} with capability admin → every right and the badge "CT admin"; roles [user] alone → a Consultant.
- [x] **The gates**: 37 screens × 5 roles answer 200 or 403 as the rights say; a refusal in words ("You may not approve time."); a Consultant's menu is Work, Reports
  (their own utilization), Settings, Me; a Manager's adds Manage and Billing (Unbilled, Invoices — no credit notes, no firm settings); Billing sees Rate cards and Credit
  notes, not the cost rates; Finance sees Engagements, Who owes us, Reports, Exports and no timesheet; the admin everything; the dashboard's regions by right; a revoked
  grant in the feed shuts the door within one sync; an inactive member's session ends on the next request.
- [x] **The sync**: `--full` applies the fixture (seven members, four practices, memberships, access); an incremental feed moves Marco to Billing and his open session
  sees Credit notes at once; back to Manager and it is gone; a suspended member's sessions end; `--from-file`; an unreachable kernel reported and cleared.
- [x] **The action-token path**: a signed action token with the relay acts as the member and answers JSON; without the relay → 401; a run token for an unknown agent → 401
  and `member.refused`; an agent the fake kernel vouches for is admitted at first contact and its rows carry the run's request id; an agent may not mint a token or open a
  person's dashboard, but may save its own notification choices.
- [x] **The ingest**: rows shipped to the fake MaluDB with `application: consultant_tracking` first, `engagement_id` and `timesheet_id` where set, nulls dropped, the
  checkpoint advanced only past accepted rows, no token value in any episode.
- [x] **The command bar**: the fake kernel's reply rendered; 404 and 409 shown in the kernel's words; a paused action shown as "waits for approval"; a succeeded
  `invoice_draft` fires `invoiceChanged`; `assistant.ask` logged without the text.
- [x] **The kernel's wire formats**: tokens, claims, the sign-out notice, a person's action token and the kernel token minted by the kernel's own functions are accepted.
- [x] **The vhost's rules** (through the dev router): `/sso`, `/sso/logout`, the canonical URLs, `/usage/os`, `/reports/{report}`, the public door 404 until slice 6,
  `/api` blocked but for health, the manifest and the assets served, nothing outside `html/` reachable.
- [x] **375 × 740 and 1280 × 800**: five tabs on the phone (Home, Time, Expenses, Engagements, More), the sidebar on the desktop, every tab ≥ 44 px, `scrollWidth` =
  viewport, HTMX navigation pushing URL and title and re-stamping `data-screen`, the placeholders naming their slice, the tokens flow, the settings form landing
  with its notice, no console errors, JavaScript off whole, installable.

**Decisions taken while building (2026-10-05):** the home's Billing region shows for `reports.view` too (Finance reads everything); a Consultant sees the Reports
group (their own utilization, `reports.view_own`); the tab bar honours a right spec with alternatives (`nav_has_right()`); Finance holds the `finance` role alone —
the `user` role carries `time.write` and `expenses.write`, so an external reader is never granted it (the fixture and the design's §3 say so); the home's regions
are keys of `home_summary()` that each slice fills.

## Open questions
