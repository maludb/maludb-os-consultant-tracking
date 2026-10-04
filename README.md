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

- **The plan:** `docs/consultant-tracking-design.md` (Phase 0, 2026-10-04). The owner's sixteen decisions are §13; their
  answers go in §15 before anything is built.
- **The build:** `CLAUDE.md` — the order, the rules, the handoff to the worker model.
- Status: plan written; decisions open. Next: the owner's answers, K17 in the kernel, Phase 0's second half, Phase 1 as the
  checkpoint before any PHP.
