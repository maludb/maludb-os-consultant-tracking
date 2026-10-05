---
name: allocate-the-ai-statement
description: The runbook for turning the kernel's closed monthly AI statement into usage charges on engagements by the allocation rules — pull, check the month is closed, allocate, report the pieces and the remainder, what to do when a rule is missing. For the Billing Clerk's duty or any agent asked about AI costs by client.
kind: runbook
---

# Allocate the AI statement (a runbook)

1. `ai_statement_status` — the months the kernel reports and what this application holds of each (pulled, allocated, the remainder).
2. A month the OS has **closed** and we have **not allocated**: `ai_statement_pull` (kept verbatim), then `ai_statement_allocate` — per line, per
   matching rule (agent, department, application), a piece = amount × share, rounded once; one DRAFT usage charge per engagement and provider.
   Say how many charges, for which engagements, and the **remainder** no rule placed (by line: agent, department, application, amount).
3. A month still **open** in the OS: say "not yet closed in the OS"; never allocate it.
4. A remainder a person wants placed: they add a rule (`allocation_rules` shows what exists) and ask to allocate again — drafts are remade, approved
   charges kept. Rules sharing out more than 100 % of a line are refused by name: say which.
5. The charges await a manager's approval and then billing; say so. Never approve one.
