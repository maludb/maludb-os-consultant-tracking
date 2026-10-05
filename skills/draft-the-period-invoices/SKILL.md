---
name: draft-the-period-invoices
description: The runbook for drafting the invoices that fell due — which engagements and milestones, one draft per engagement from its approved unbilled items, what was skipped and why, the report to billing. For the Billing Clerk's duty or any agent asked to prepare an invoice.
kind: runbook
---

# Draft the period's invoices (a runbook)

1. `invoices_due_to_draft` — every active engagement whose `next_billing_date` has passed with something unbilled, and every accepted milestone
   not yet invoiced. An engagement with a draft already open is listed as such: do not draft a second.
2. For each: `invoice_draft` (the engagement, the period from the last invoice's end to the billing date), then `invoice_add_unbilled` through the
   period's end. Read the counts it answers: time lines and entries, expenses, usage, milestones — and **time skipped for having no rate**.
3. `get_invoice` on the draft: the total, the lines. Say nothing is issued.
4. In the note, per draft: the engagement, the period, the total with its currency, the lines by kind, and the skipped no-rate hours by person
   (the manager must set the rate). A not-to-exceed cap the draft would cross is said plainly — billing decides.
5. Never issue, never send. `invoice_issue` pauses for a person when a person asks you to; say so.
