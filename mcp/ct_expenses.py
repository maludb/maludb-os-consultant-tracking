"""Expenses (tool surface: my_expenses, my_reimbursements_due, expenses_pending, expense_summary; the two shares that read expenses are in ct_shares). Reads mcp_expenses only — a receipt's image is never here,
only whether there is one (has_receipt) and the threshold that applies."""
from __future__ import annotations

from pydantic import Field

from ct_common import RO, BadDate, Where, _Base, day, dumps, err, has_right, lim, match_words, me, need, period, plain, q_alone

EXP_LABEL = "x.number || ' — ' || COALESCE(NULLIF(x.description, ''), x.vendor_name, 'Expense') || ', ' || to_char(x.expense_date, 'Mon FMDD')"
EXP_COLS = """x.expense_id, x.number, x.member_id, x.member_name, x.engagement_id, x.engagement_number, x.expense_date, x.category_id, x.category_label, x.vendor_name, x.description, x.amount, x.tax_amount, x.currency,
       x.paid_with, x.reimbursable, x.billable, x.markup_pct, x.billable_amount, x.status, x.submitted_at, x.approved_at, x.rejected_at, x.rejection_reason, x.reimbursed_at, x.reimbursed_ref,
       x.has_receipt, x.receipt_threshold, x.billed"""


class MyExpensesIn(_Base):
    q: str | None = Field(None, description="Words of the number, vendor or description. `q` alone answers a plain list (expense_id, label = 'EXP-000123 — Dinner, Mar 3')")
    status: list[str] | None = Field(None, description="draft, submitted, approved, rejected")
    member_id: int | None = Field(None, description="Another person's (needs expenses.approve on a staffed engagement)")
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    limit: int = Field(25, ge=1, le=100)


class PendingIn(_Base):
    engagement_id: int | None = None
    missing_receipt: bool = Field(False, description="Only those at or above their receipt threshold with no receipt")
    limit: int = Field(25, ge=1, le=100)


class SummaryIn(_Base):
    engagement_id: int | None = None
    client_id: int | None = None
    member_id: int | None = None
    from_: str = Field(..., alias="from")
    to: str
    group_by: str = Field("category", description="category, person, month or billable")
    status: list[str] | None = Field(None, description="Approved only by default")


GROUPS = {
    "category": ("x.category_id::text", "x.category_label"),
    "person": ("x.member_id::text", "x.member_name"),
    "month": ("to_char(x.expense_date, 'YYYY-MM')", "to_char(x.expense_date, 'YYYY-MM')"),
    "billable": ("x.billable::text", "CASE WHEN x.billable THEN 'billable' ELSE 'not billable' END"),
}


def register(mcp, fetch) -> None:
    @mcp.tool(name="my_expenses", annotations={"title": "Expenses", **RO})
    async def my_expenses(params: MyExpensesIn) -> str:
        """The caller's expenses by status with the receipt flag, the billable amount and the reimbursement state; another person's with expenses.approve. `q` alone resolves one by number or words — yours first, then a team member's an approver may see (labelled with the person): a plain list (expense_id, label)."""
        who = await me(fetch)
        member = params.member_id if params.member_id is not None else who
        if q_alone(params):
            # The resolver's call: the caller's own expenses first, then any the view admits (an approver resolves a team member's by number or words) — labelled with the person when it is not the caller's.
            w = Where()
            me_ = w.p(who)
            match_words(w, params.q, "x.number", "x.vendor_name", "x.description", "x.member_name")
            rows = await fetch(f"""SELECT x.expense_id, CASE WHEN x.member_id = {me_} THEN '' ELSE x.member_name || ' — ' END || {EXP_LABEL} AS label FROM mcp_expenses x WHERE {w.sql()}
                                    ORDER BY (x.member_id = {me_}) DESC, x.expense_date DESC, x.expense_id DESC LIMIT {lim(params.limit)}""", *w.args)
            return plain(rows, "expense_id", "label")
        w = Where().add("x.member_id = {}", member)
        match_words(w, params.q, "x.number", "x.vendor_name", "x.description")
        try:
            f, t = day(params.from_, "from"), day(params.to, "to")
        except BadDate as ex:
            return err(str(ex))
        if params.status:
            w.add("x.status = ANY ({})", params.status)
        if f:
            w.add("x.expense_date >= {}", f)
        if t:
            w.add("x.expense_date <= {}", t)
        rows = await fetch(f"SELECT {EXP_COLS}, {EXP_LABEL} AS label FROM mcp_expenses x WHERE {w.sql()} ORDER BY x.expense_date DESC, x.expense_id DESC LIMIT {lim(params.limit)}", *w.args)
        return dumps({"member_id": member, "expenses": rows})

    @mcp.tool(name="my_reimbursements_due", annotations={"title": "What I am owed", **RO})
    async def my_reimbursements_due() -> str:
        """What the caller is owed: approved personal-card expenses not yet reimbursed, with the total per currency."""
        w = Where().add("x.member_id = {}", await me(fetch)).raw("x.status = 'approved' AND x.paid_with = 'personal' AND x.reimbursed_at IS NULL")
        rows = await fetch(f"SELECT {EXP_COLS} FROM mcp_expenses x WHERE {w.sql()} ORDER BY x.expense_date, x.expense_id", *w.args)
        totals = await fetch(f"SELECT x.currency, sum(x.amount) AS amount, count(*)::int AS expenses FROM mcp_expenses x WHERE {w.sql()} GROUP BY 1 ORDER BY 1", *w.args)
        return dumps({"expenses": rows, "totals": totals})

    @mcp.tool(name="expenses_pending", annotations={"title": "Expenses awaiting approval", **RO})
    async def expenses_pending(params: PendingIn) -> str:
        """Submitted expenses awaiting the caller's approval, oldest first, with the receipt flag and the threshold that applies. Needs expenses.approve; your own are never yours to approve."""
        if not await has_right(fetch, "expenses.approve"):
            return need("expenses.approve", "Expenses awaiting approval")
        w = Where().raw("x.status = 'submitted'").add("x.member_id <> {}", await me(fetch))
        if params.engagement_id is not None:
            w.add("x.engagement_id = {}", params.engagement_id)
        if params.missing_receipt:
            w.raw("NOT x.has_receipt AND x.amount >= COALESCE(x.receipt_threshold, 0)")
        rows = await fetch(f"SELECT {EXP_COLS}, {EXP_LABEL} AS label FROM mcp_expenses x WHERE {w.sql()} ORDER BY x.submitted_at, x.expense_id LIMIT {lim(params.limit)}", *w.args)
        return dumps({"expenses": rows})

    @mcp.tool(name="expense_summary", annotations={"title": "Spend over a period", **RO})
    async def expense_summary(params: SummaryIn) -> str:
        """Spend on an engagement (or a client, or a person) over a period by category, person, month or billable — approved only by default, or any status — with the billable amount (markup included). Your own, or what the views admit."""
        if params.group_by not in GROUPS:
            return err("group_by is one of " + ", ".join(GROUPS) + ".")
        try:
            f, t = period(params.from_, params.to)
        except BadDate as ex:
            return err(str(ex))
        w = Where().add("x.expense_date >= {}", f).add("x.expense_date <= {}", t).add("x.status = ANY ({})", params.status or ["approved"])
        if params.engagement_id is not None:
            w.add("x.engagement_id = {}", params.engagement_id)
        if params.client_id is not None:
            w.add("x.engagement_id IN (SELECT engagement_id FROM mcp_engagements WHERE client_id = {})", params.client_id)
        if params.member_id is not None:
            w.add("x.member_id = {}", params.member_id)
        key, label = GROUPS[params.group_by]
        rows = await fetch(f"""SELECT {key} AS group_key, {label} AS group_label, x.currency, sum(x.amount) AS amount, sum(x.tax_amount) AS tax_amount,
                                      sum(x.billable_amount) FILTER (WHERE x.billable) AS billable_amount, count(*)::int AS expenses
                                 FROM mcp_expenses x WHERE {w.sql()} GROUP BY 1, 2, 3 ORDER BY 1, 3 LIMIT 400""", *w.args)
        return dumps({"from": f, "to": t, "group_by": params.group_by, "groups": rows})
