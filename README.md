# maludb-os-consultant-tracking — Consultant Tracking

MaluDB Business OS application: **Consultant Tracking** — professional-services time, expenses and billing for a technical
consultancy with an AI focus. Clients and engagements (time-and-materials with expenses, or fixed bid against milestones),
weekly timesheets with a timer, expenses with receipts snapped on a phone, **hosting and model-usage pass-through** (by hand,
from a provider's usage export, from a hosting schedule, or allocated from the kernel's own monthly AI statement), invoices
that travel by email and a secure link with their backing detail, and the firm's reports: unbilled work, utilization,
realization, budget burn, margin, AI cost vs. billed. **It is not the books**: the accounting system (General Ledger, or
QuickBooks/Xero/Sage by export) reads it through the kernel's application reads (K7); HR pays the reimbursements.

An optional application: installed on request beside [maludb-os-core](https://github.com/maludb/maludb-os-core) for a
consultancy or an AI agency, granted to every insider (everyone logs time). Built with `htmx-php-builder`, fitted to
`maludb-os-integration` 0.6.0, served as `consulting.<domain>`; catalog key `consultant_tracking`.

- **The plan:** `docs/consultant-tracking-design.md` (Phase 0, 2026-10-04 — **approved**; the owner's sixteen decisions are
  §15, D1–D16).
- **The build:** `CLAUDE.md` — the order, the rules, the handoff to the worker model.
- Status: approved plan; **K17 and Phase 0's second half built and proven 2026-10-05** (schema db/001–017 with 171 proof checks, the
  kit with 15, `maludb-os.json`, agents, skills, deploy). Phase 1 written 2026-10-05 (the tool surface, the manifest with 78 screens and 108 actions, the registry, the ten specs) — approved by the owner 2026-10-05; **Phase 2 (sign-on, the mirror, the shell) built and proven the same day (247 checks)**; **slice 1 (clients, rates, the vocabulary, the settings) built and proven 2026-10-05 (259 checks)**; **slice 2 (engagements) built and proven 2026-10-05 (173 checks)**; **slice 3 (time, the exemplar) built and proven 2026-10-05 (195 checks) — the handoff point**; **slice 4 (expenses and receipts) built and proven 2026-10-05 by the worker model (223 checks)**; **slice 5 (AI usage and hosting, the OS statement) built and proven 2026-10-05 by the worker model (331 checks)**; **slice 6 (billing: invoices, credit notes, payments, claims, the secure link) built and proven 2026-10-05 by the worker model (407 checks)**; **slice 7 (reports and CSVs) built and proven 2026-10-05 by the worker model (411 checks)**; then Phase 2 and slices 1–3 (time, the exemplar) by the planning model; slices 8–9 and Phases 4–5 by the worker model.
- The proofs: `tests/phase0/run.sh` (a scratch database, `php -S`, fixtures; nothing installed is touched).
