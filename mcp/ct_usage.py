"""AI usage and hosting (tool surface: usage_summary, usage_pending, ai_passthrough, ai_statement_status, allocation_rules, recurring_due). Reads mcp_usage_charges, mcp_ai_statements, mcp_ai_statement_lines,
mcp_ai_statement_allocations, mcp_allocation_rules, mcp_recurring_charges and ct_ai_passthrough() / ct_ai_statement_summary(). A cost is a cost: the views blank cost_amount and unit_cost without cost.view on the
engagement, and ai_passthrough's cost and markup are nulled here without cost.view or usage.write (as the screen does)."""
from __future__ import annotations

from pydantic import Field

from ct_common import RO, BadDate, Where, _Base, day, dumps, err, has_right, lim, match_words, need, period, plain, q_alone, today

USG_LABEL = "u.number || ' — ' || COALESCE(NULLIF(u.description, ''), u.kind) || ' (' || u.engagement_number || ')'"
USG_COLS = """u.usage_charge_id, u.number, u.engagement_id, u.engagement_number, u.period_start, u.period_end, u.kind, u.provider_id, u.provider_name, u.description, u.quantity, u.unit, u.unit_cost, u.cost_amount,
       u.pricing, u.markup_pct, u.resale_unit_price, u.billable_amount, u.billable, u.currency, u.source, u.status, u.approved_at, u.void_reason, u.billed"""


class SummaryIn(_Base):
    q: str | None = Field(None, description="Words of the number or description. `q` alone answers a plain list (usage_charge_id, label)")
    engagement_id: int | None = None
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    status: list[str] | None = Field(None, description="draft, approved, invoiced, void")
    source: str | None = Field(None, description="manual, csv_import, kernel_ledger or schedule")
    group_by: str | None = Field(None, description="provider, kind, source or month — totals instead of the charges")
    limit: int = Field(25, ge=1, le=100)


class PendingIn(_Base):
    q: str | None = Field(None, description="Words of the number or description. `q` alone answers a plain list (usage_charge_id, label)")
    engagement_id: int | None = None
    limit: int = Field(25, ge=1, le=100)


class PassthroughIn(_Base):
    from_: str = Field(..., alias="from")
    to: str
    engagement_id: int | None = None


class StatementIn(_Base):
    q: str | None = Field(None, description="A month, '2026-09'. `q` alone answers a plain list (ai_statement_id, period)")
    period: str | None = Field(None, description="'2026-09' — one month")
    with_lines: bool = Field(False, description="Add the lines of that month with their remainders")


class RulesIn(_Base):
    q: str | None = Field(None, description="Words of the rule's name. `q` alone answers a plain list (rule_id, name)")
    engagement_id: int | None = None
    active: bool | None = None
    period: str | None = Field(None, description="'2026-09' — add what each rule produced on that month")
    limit: int = Field(25, ge=1, le=100)


class RecurringIn(_Base):
    q: str | None = Field(None, description="Words of the description, provider or engagement. `q` alone answers a plain list (schedule_id, description)")
    engagement_id: int | None = None
    due_only: bool = Field(True, description="Only the schedules fallen due and not yet run (the default)")
    limit: int = Field(25, ge=1, le=100)


GROUPS = {"provider": "COALESCE(u.provider_name, '—')", "kind": "u.kind", "source": "u.source", "month": "to_char(u.period_start, 'YYYY-MM')"}


def register(mcp, fetch) -> None:
    @mcp.tool(name="usage_summary", annotations={"title": "Usage charges", **RO})
    async def usage_summary(params: SummaryIn) -> str:
        """Usage charges on an engagement over a period — the charges, or with group_by totals by provider, kind, source or month: quantity, cost (only where you may see cost), the billable amount and what is billed.
        `q` alone resolves a charge by number or words: a plain list (usage_charge_id, label). Needs usage.write or engagements.manage on a staffed engagement; Billing and Finance see all."""
        w = Where()
        match_words(w, params.q, "u.number", "u.description", "u.engagement_number")
        if q_alone(params):                                   # the kernel's resolver for `usage_charge`: any live charge (a draft to approve, an approved one to void), never a void one
            w.raw("u.status <> 'void'")
            rows = await fetch(f"SELECT u.usage_charge_id, {USG_LABEL} || ' · ' || u.status AS label FROM mcp_usage_charges u WHERE {w.sql()} ORDER BY u.period_end DESC, u.usage_charge_id DESC LIMIT {lim(params.limit)}", *w.args)
            return plain(rows, "usage_charge_id", "label")
        try:
            f, t = day(params.from_, "from"), day(params.to, "to")
        except BadDate as ex:
            return err(str(ex))
        if params.group_by is not None and params.group_by not in GROUPS:
            return err("group_by is one of " + ", ".join(GROUPS) + ".")
        if params.engagement_id is not None:
            w.add("u.engagement_id = {}", params.engagement_id)
        if f:
            w.add("u.period_end >= {}", f)
        if t:
            w.add("u.period_start <= {}", t)
        w.add("u.status = ANY ({})", params.status) if params.status else w.raw("u.status <> 'void'")
        if params.source:
            w.add("u.source = {}", params.source)
        if params.group_by:
            rows = await fetch(f"""SELECT {GROUPS[params.group_by]} AS group_key, u.currency, count(*)::int AS charges, sum(u.quantity) AS quantity, sum(u.cost_amount) AS cost_amount, sum(u.billable_amount) FILTER (WHERE u.billable) AS billable_amount,
                                          sum(u.billable_amount) FILTER (WHERE u.billable AND u.billed) AS billed_amount
                                     FROM mcp_usage_charges u WHERE {w.sql()} GROUP BY 1, 2 ORDER BY 1, 2 LIMIT 400""", *w.args)
            return dumps({"group_by": params.group_by, "groups": rows})
        rows = await fetch(f"SELECT {USG_COLS} FROM mcp_usage_charges u WHERE {w.sql()} ORDER BY u.period_end DESC, u.usage_charge_id DESC LIMIT {lim(params.limit)}", *w.args)
        return dumps({"charges": rows})

    @mcp.tool(name="usage_pending", annotations={"title": "Usage awaiting approval", **RO})
    async def usage_pending(params: PendingIn) -> str:
        """Draft usage charges awaiting approval, by engagement, with their source (a schedule, an import, the OS statement). `q` alone answers a plain list (usage_charge_id, label). Needs usage.approve."""
        if not await has_right(fetch, "usage.approve"):
            return need("usage.approve", "Usage awaiting approval")
        w = Where().raw("u.status = 'draft'")
        match_words(w, params.q, "u.number", "u.description", "u.engagement_number")
        if params.engagement_id is not None:
            w.add("u.engagement_id = {}", params.engagement_id)
        rows = await fetch(f"SELECT {USG_COLS}, {USG_LABEL} AS label FROM mcp_usage_charges u WHERE {w.sql()} ORDER BY u.period_end, u.usage_charge_id LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return plain(rows, "usage_charge_id", "label")
        return dumps({"charges": rows})

    @mcp.tool(name="ai_passthrough", annotations={"title": "What clients' AI costs and what was billed", **RO})
    async def ai_passthrough(params: PassthroughIn) -> str:
        """What a client's AI systems cost and what was billed for it: per engagement, provider and kind — cost (cost.view or usage.write only), billable, billed, unbilled and the markup. Needs usage.write or reports.view on a staffed engagement."""
        if not (await has_right(fetch, "usage.write") or await has_right(fetch, "reports.view")):
            return need("usage.write or reports.view", "AI pass-through")
        try:
            f, t = period(params.from_, params.to)
        except BadDate as ex:
            return err(str(ex))
        rows = await fetch("SELECT * FROM ct_ai_passthrough($1, $2, $3)", f, t, params.engagement_id)
        if not (await has_right(fetch, "cost.view") or await has_right(fetch, "usage.write")):
            for r in rows:
                r["cost_amount"] = None
                r["markup"] = None
        return dumps({"from": f, "to": t, "rows": rows})

    @mcp.tool(name="ai_statement_status", annotations={"title": "The OS's months as we hold them", **RO})
    async def ai_statement_status(params: StatementIn) -> str:
        """The kernel's AI statement months as this application holds them: pulled or allocated, the statement's total, what is allocated and the remainder by provider — and the lines of one month with their remainders.
        `q` alone resolves a month: a plain list (ai_statement_id, period). Needs usage.write."""
        if not await has_right(fetch, "usage.write"):
            return need("usage.write", "The OS statement")
        want = params.period or params.q
        w = Where()
        if want:
            w.add("s.period = {}", want.strip()[:7])
        rows = await fetch(f"""SELECT s.ai_statement_id, s.period, s.period_start, s.period_end, s.kernel_status, s.pulled_at, s.status, s.allocated_at, s.late_calls, s.statement_amount, s.allocated_amount
                                 FROM mcp_ai_statements s WHERE {w.sql()} ORDER BY s.period DESC LIMIT 24""", *w.args)
        if q_alone(params):
            return plain(rows, "ai_statement_id", "period")
        for s in rows:
            s["by_provider"] = await fetch("SELECT provider, currency, statement_amount, allocated_amount, remainder, lines FROM ct_ai_statement_summary($1)", s["ai_statement_id"])
            if params.with_lines and want:
                s["lines"] = await fetch("""SELECT l.line_id, l.line_no, l.provider, l.model, l.department, l.agent, l.application, l.calls, l.input_tokens, l.output_tokens, l.amount, l.currency, l.allocated_amount, l.remainder
                                              FROM mcp_ai_statement_lines l WHERE l.statement_id = $1 ORDER BY l.line_no""", s["ai_statement_id"])
        return dumps({"statements": rows})

    @mcp.tool(name="allocation_rules", annotations={"title": "Allocation rules", **RO})
    async def allocation_rules(params: RulesIn) -> str:
        """The allocation rules — which kernel agent, department or application, which engagement, what share, how priced, which dates, active — and, with period, what each produced on that month. `q` alone answers a plain list (rule_id, name). Needs usage.write."""
        if not await has_right(fetch, "usage.write"):
            return need("usage.write", "Allocation rules")
        w = Where()
        match_words(w, params.q, "r.name", "r.engagement_number")
        if params.engagement_id is not None:
            w.add("r.engagement_id = {}", params.engagement_id)
        if params.active is not None:
            w.add("r.active = {}", params.active)
        rows = await fetch(f"""SELECT r.rule_id, r.name, r.kernel_agent_id, r.kernel_department_id, r.kernel_application_id, r.engagement_id, r.engagement_number, r.share_pct, r.pricing, r.markup_pct, r.resale_unit_price,
                                      r.valid_from, r.valid_to, r.active FROM mcp_allocation_rules r WHERE {w.sql()} ORDER BY lower(r.name), r.rule_id LIMIT {lim(params.limit)}""", *w.args)
        if q_alone(params):
            return plain(rows, "rule_id", "name")
        if params.period:
            for r in rows:
                r["produced"] = await fetch("""SELECT s.period, count(*)::int AS allocations, sum(a.amount) AS amount FROM mcp_ai_statement_allocations a JOIN mcp_ai_statements s ON s.ai_statement_id = a.statement_id
                                                WHERE a.rule_id = $1 AND s.period = $2 GROUP BY s.period""", r["rule_id"], params.period.strip()[:7])
        return dumps({"rules": rows})

    @mcp.tool(name="recurring_due", annotations={"title": "Hosting schedules", **RO})
    async def recurring_due(params: RecurringIn) -> str:
        """The hosting schedules: which have fallen due and not yet run, which run next when; or one engagement's. `q` alone answers a plain list (schedule_id, description). Needs usage.write on a staffed engagement."""
        if not await has_right(fetch, "usage.write"):
            return need("usage.write", "Hosting schedules")
        w = Where()
        match_words(w, params.q, "r.description", "r.provider_name", "r.engagement_number")
        if params.engagement_id is not None:
            w.add("r.engagement_id = {}", params.engagement_id)
        if not q_alone(params):
            w.raw("r.active")
            if params.due_only:
                w.add("r.next_date <= {}", await today(fetch)).raw("(r.end_date IS NULL OR r.next_date <= r.end_date)")
        rows = await fetch(f"""SELECT r.schedule_id, r.engagement_id, r.engagement_number, r.kind, r.provider_name, r.description, r.quantity, r.unit, r.unit_cost, r.pricing, r.markup_pct, r.cadence, r.every_n, r.next_date,
                                      r.end_date, r.last_run_at, r.run_count, r.active FROM mcp_recurring_charges r WHERE {w.sql()} ORDER BY r.next_date, r.schedule_id LIMIT {lim(params.limit)}""", *w.args)
        if q_alone(params):
            return plain(rows, "schedule_id", "description")
        return dumps({"schedules": rows})
