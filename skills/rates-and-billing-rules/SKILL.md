---
name: rates-and-billing-rules
description: The rules of money in Consultant Tracking — how a rate is resolved and when it is snapshotted, what is billable, markup and resale pricing, the not-to-exceed cap, when an item is locked, billed once, and what a void or a credit note does. Use before drafting or explaining an invoice, a usage charge or a rate.
---

# Rates and billing rules

1. **A rate is resolved for a person on a day** (`resolve_rate`): member override on the engagement → engagement role rate → rate card
   (the engagement's, else the client's default) → none. It is **snapshotted onto the entry at approval**; a later rate change never
   touches approved hours.
2. **No rate = no invoice line.** Time with no rate is approved, billable, listed in the unbilled report as "no rate", and skipped by
   `invoice_add_unbilled` until a manager sets a rate and the entry is re-approved. Never invoice at zero.
3. **Non-billable** hours (travel, admin by default) cost but never bill; they count for utilization's logged hours, not billable ones.
4. **Expenses** bill at `amount × (1 + markup %)` — the category's default markup, else the engagement's policy, else the settings' (0 %).
   Tax is inside the amount. A receipt is required to submit at or above the threshold (75 by default; a category or an engagement may say otherwise).
5. **Usage charges**: `cost` bills the cost; `markup` bills cost × (1 + %); `resale` bills quantity × resale price (per million when the unit is
   tokens). The cost of a kernel-statement piece is the statement's amount × the rule's share, rounded once; the remainder stays on the statement.
6. **An item is billed once**: a time entry, an expense, a usage charge or a milestone sits on at most one live invoice line; a second attempt is
   refused by the database. A **void** releases the items (they are unbilled again); a **write-off** keeps them billed and forgives the balance.
7. **Not-to-exceed** (T&M): issuing an invoice that would take the engagement's issued total over the cap is refused unless Billing gives a reason,
   which the invoice records.
8. **A PO** is required on every invoice of a client flagged `po_required`; the engagement's PO is the default.
9. **Issued is immutable**: client, engagement, dates, number, totals never change; a correction is a credit note (`CN-`) applied to the invoice.
10. **Paid is derived** from the payments recorded (and credits applied), never typed. A client's claim on the secure link is not a payment until
    billing confirms it. The ledger keeps the receipt; this application keeps the status.
