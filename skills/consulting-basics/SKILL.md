---
name: consulting-basics
description: How Consultant Tracking (the firm's professional-services record) is organised and which tool answers which question — clients, engagements of two kinds, staffing and rates, weekly timesheets, expenses with receipts, usage charges and the kernel's AI statement, invoices and their backing items, who sees what. Use before any question or action about an engagement, a timesheet, an expense, a usage charge or an invoice.
---

# Consultant Tracking basics

## The shape
- A **client** is a party the firm bills: a name, billing contacts, terms, a currency, a default rate card. Never a member.
- An **engagement** (`ENG-0042`) is the agreement with a client: `time_and_materials` (hours × rate plus expenses, an optional not-to-exceed cap)
  or `fixed_bid` (a fee against milestones, billed on acceptance). It has a manager, a practice (department), a currency, a billing cadence,
  budgets (hours, fee, AI), an expense policy (markup, receipt threshold) and the people **staffed** on it, each in a consulting role.
- A **rate** for a person on a day: their override on the engagement → the engagement's rate for their role → the rate card's → none.
  "None" is billable with no amount and is never invoiced at zero; it is listed for a manager to fix.
- A **timesheet** is one person's week starting Monday; **entries** are hours (in 0.25 h steps) on an engagement on a day, billable or not,
  with a client-visible note and an internal one. open → submitted → approved (the rate and the cost are snapshotted) or rejected (with a reason).
- An **expense** (`EXP-000123`) is a person's spend on an engagement: a receipt is required at or above the threshold; paid personally it is a
  reimbursement HR pays; billable at the engagement's markup. draft → submitted → approved / rejected.
- A **usage charge** (`USG-000123`) is tokens, API calls, GB or hosting at a cost on an engagement for a period, priced at cost, cost plus markup,
  or resale per million tokens; from a hand entry, a provider CSV, a hosting schedule, or the kernel's monthly AI statement allocated by rules.
- An **invoice** (`INV-00042`, numbered on issue) bills one engagement for a period: lines of kind time / expense / usage / milestone / fixed /
  adjustment / discount, each line backed by the items it bills — an item is on at most one live invoice. draft → issued → sent → viewed →
  partially paid → paid; or void (its items are released) or written off. A **credit note** (`CN-`) corrects an issued invoice.
- **Unbilled** (WIP) = approved, billable, not yet on an invoice. **Utilization** = billable hours ÷ capacity. **Realization** = billed ÷ approved hours.

## Which tool
| Question | Tool |
|---|---|
| An engagement, a client | `find_engagements`, `get_engagement`, `find_clients`, `get_client` |
| Who is staffed, at what rate | `engagement_staffing`, `resolve_rate`, `rate_cards` |
| Budget burn, alerts | `budget_status`, `budget_alerts` |
| My week, my timer | `my_timesheet`, `my_timer` |
| Who has not submitted, what awaits approval | `missing_time`, `timesheets_pending`, `expenses_pending`, `usage_pending` |
| Hours on an engagement, utilization, realization | `time_summary`, `utilization`, `realization` |
| Expenses, reimbursements | `my_expenses`, `expense_summary`, `my_reimbursements_due` |
| AI cost vs billed, the OS statement | `usage_summary`, `ai_passthrough`, `ai_statement_status` |
| What is unbilled, what to draft | `unbilled_summary`, `unbilled_items`, `invoices_due_to_draft` |
| An invoice, who owes us | `find_invoices`, `get_invoice`, `ar_aging`, `client_statement`, `payment_claims` |
| Margin (cost.view only) | `margin` |
| What happened to a record | `record_history` |

## Who sees what
A Consultant sees the engagements they are staffed on and their own time and expenses — never a bill rate, a cost, an invoice or a client's
contact details. A Manager sees the engagements they manage (and their practice's), approves time and expenses on them, sees bill rates, and
cost and margin on their own engagements only. Billing sees every engagement, invoice and client. Finance reads everything, cost included.
Agents follow the same rule as people.
