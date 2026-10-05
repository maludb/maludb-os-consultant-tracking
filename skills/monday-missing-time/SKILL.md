---
name: monday-missing-time
description: The runbook for Monday's missing-time pass — who has not submitted last week, which days are empty, one nudge each (paused for a person), the note to the managers. For the Billing Clerk's duty or any agent asked who is behind on time.
kind: runbook
---

# Monday — missing time (a runbook)

1. `missing_time` with last week's Monday — every person with capacity whose week is not submitted or approved, with the hours they did log and
   the weekdays that are empty.
2. Leave out anyone whose week shows every weekday logged and simply unsubmitted — one line in the note is enough for them; nudge the rest.
3. For each person to nudge: `timesheet_nudge_send` — one a person a week; the kernel pauses it for a person (external_send) unless the policy was
   relaxed; say "waiting for approval" when it does. The text names the week and the empty days, nothing else.
4. In the morning note: who was nudged, who is complete but unsubmitted, who has nothing logged at all. No reasons guessed, no tone.
5. A manager asking "who is behind" between Mondays: the same `missing_time`, read aloud, no nudges unless asked.
