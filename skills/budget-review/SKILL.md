---
name: budget-review
description: The runbook for a budget review — every active engagement against its hours, fee, not-to-exceed and AI budgets, the pace, the alerts to write, the note to the managers. For the Billing Clerk's duty or any manager asking "where do we stand".
kind: runbook
---

# Budget review (a runbook)

1. `find_engagements` (active) → `budget_status` for each: hours used of budget, bill value of budget, invoiced of the not-to-exceed, AI cost of the
   AI budget, the pace in hours a week and the weeks left at that pace.
2. `budget_alerts` — what is already written and unacknowledged. For a threshold crossed with no alert: `budget_alert_write`.
3. Order the note by urgency: over the cap first, then over 80 %, then the pace that will cross within two weeks. Each line: the engagement's number
   and name, the manager, the figure and the budget, with the currency.
4. Say what the numbers do not say: a fixed bid near its fee with milestones unaccepted is a conversation, not an alarm. Change nothing; a review is a reading.
