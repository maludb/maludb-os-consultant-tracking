"""Billing and the reports (tool surface: unbilled_items, invoices_due_to_draft, find_invoices, get_invoice, revenue, ar_aging, payment_claims, realization, margin; the share tools are in ct_shares).
Reads mcp_invoices, mcp_invoice_lines, mcp_invoice_line_items, mcp_invoice_payments, mcp_credit_applications, mcp_payment_claims, the backing views and the report functions ct_unbilled(), ct_unbilled_items(), ct_revenue(),
ct_ar_aging(), ct_realization() and ct_margin() — one definition for a screen, a tool and a CSV. The secure link's state is not in any view and not here; the last events are record_history's."""
from __future__ import annotations

from pydantic import Field

from ct_common import RO, BadDate, Where, _Base, day, dumps, err, has_right, lim, match_words, need, period, plain, q_alone, receivables_ok, today

INV_LABEL = "COALESCE(NULLIF(i.number, ''), 'Draft ' || i.invoice_id) || ' — ' || i.client_name || ', ' || COALESCE(to_char(i.issue_date, 'Mon YYYY'), 'draft')"
INV_COLS = """i.invoice_id, i.number, i.client_id, i.client_name, i.engagement_id, i.engagement_number, i.engagement_name, i.period_start, i.period_end, i.issue_date, i.due_date, i.currency, i.status, i.subtotal, i.tax_total, i.total,
       i.amount_paid, i.amount_credited, i.balance_due, i.po_number, i.external_ref, i.sent_at, i.viewed_at, i.paid_at, i.voided_at, i.void_reason, i.written_off_at, i.write_off_reason, i.last_reminder_at, i.reminder_count,
       i.overdue, i.open_claims"""


class UnbilledItemsIn(_Base):
    engagement_id: int
    as_of: str | None = Field(None, description="YYYY-MM-DD; today by default")


class DueIn(_Base):
    as_of: str | None = Field(None, description="YYYY-MM-DD; today by default")
    limit: int = Field(25, ge=1, le=100)


class FindInvoicesIn(_Base):
    q: str | None = Field(None, description="Words of the number, client or engagement. `q` alone answers a plain list (invoice_id, label = 'INV-00042 — Acme, Mar 2026')")
    client_id: int | None = None
    engagement_id: int | None = None
    status: list[str] | None = Field(None, description="draft, issued, sent, viewed, partially_paid, paid, void, written_off")
    overdue: bool | None = None
    issued_from: str | None = None
    issued_to: str | None = None
    limit: int = Field(25, ge=1, le=100)


class InvoiceIn(_Base):
    invoice_id: int | None = None
    number: str | None = Field(None, description="INV-00042")


class RevenueIn(_Base):
    from_: str = Field(..., alias="from")
    to: str
    by: str = Field("client", description="client, engagement, kind, practice or month")


class AgingIn(_Base):
    as_of: str | None = Field(None, description="YYYY-MM-DD; today by default")


class ClaimsIn(_Base):
    q: str | None = Field(None, description="Words of the invoice number, method or reference. `q` alone answers a plain list (claim_id, label)")
    invoice_id: int | None = None
    status: str = Field("submitted", description="submitted (the default), confirmed or dismissed")
    limit: int = Field(25, ge=1, le=100)


class RealizationIn(_Base):
    from_: str = Field(..., alias="from")
    to: str
    engagement_id: int | None = None


class MarginIn(_Base):
    from_: str = Field(..., alias="from")
    to: str
    engagement_id: int | None = None


def register(mcp, fetch) -> None:
    @mcp.tool(name="unbilled_items", annotations={"title": "What is behind an engagement's unbilled work", **RO})
    async def unbilled_items(params: UnbilledItemsIn) -> str:
        """The items behind one engagement's unbilled work: each time entry, expense, usage charge and milestone with date, person, description, quantity and amount at bill value, and `no_rate` where a time entry has no rate.
        Needs invoices.draft, reports.view or billing.write on an engagement you may see (a Consultant sees no bill value)."""
        if not any([await has_right(fetch, r) for r in ("invoices.draft", "reports.view", "billing.write")]):
            return need("invoices.draft, reports.view or billing.write", "The unbilled items")
        try:
            on = day(params.as_of, "as_of") or await today(fetch)
        except BadDate as ex:
            return err(str(ex))
        if not (await fetch("SELECT ct_can_see_engagement($1) AS ok", params.engagement_id))[0]["ok"]:
            return err("No such engagement among those you may see.")
        rows = await fetch("SELECT kind, item_id, item_date, member_id, member_name, description, quantity, amount, no_rate FROM ct_unbilled_items($1, $2)", params.engagement_id, on)
        return dumps({"engagement_id": params.engagement_id, "as_of": on, "items": rows})

    @mcp.tool(name="invoices_due_to_draft", annotations={"title": "Which invoices are due to be drafted", **RO})
    async def invoices_due_to_draft(params: DueIn) -> str:
        """The Clerk's list: the active engagements whose billing date has come with something unbilled, the accepted milestones awaiting an invoice, and the engagements that already hold an open draft. Needs invoices.draft or billing.write."""
        if not (await has_right(fetch, "invoices.draft") or await has_right(fetch, "billing.write")):
            return need("invoices.draft or billing.write", "The invoices due to draft")
        try:
            on = day(params.as_of, "as_of") or await today(fetch)
        except BadDate as ex:
            return err(str(ex))
        due = await fetch(f"""SELECT e.engagement_id, e.number AS engagement_number, e.name AS engagement_name, e.client_name, e.billing_cadence, e.next_billing_date, u.currency, u.time_hours, u.time_amount, u.time_no_rate_hours,
                                     u.expense_amount, u.usage_amount, u.milestone_amount, u.total_amount, u.oldest_item,
                                     EXISTS (SELECT 1 FROM mcp_invoices d WHERE d.engagement_id = e.engagement_id AND d.status = 'draft') AS has_open_draft
                                FROM mcp_engagements e JOIN ct_unbilled($1) u ON u.engagement_id = e.engagement_id
                               WHERE e.status = 'active' AND e.next_billing_date IS NOT NULL AND e.next_billing_date <= $1 AND u.total_amount > 0
                               ORDER BY e.next_billing_date, e.number LIMIT {lim(params.limit)}""", on)
        miles = await fetch(f"""SELECT m.milestone_id, m.name, m.amount, e.currency, m.engagement_id, e.number AS engagement_number, e.client_name, m.accepted_at
                                  FROM mcp_milestones m JOIN mcp_engagements e ON e.engagement_id = m.engagement_id
                                 WHERE m.status = 'accepted' AND NOT COALESCE(m.billed, false) ORDER BY m.accepted_at LIMIT {lim(params.limit)}""")
        drafts = await fetch(f"""SELECT i.invoice_id, i.number, i.engagement_id, i.engagement_number, i.client_name, i.period_start, i.period_end, i.total, i.currency, i.created_at
                                   FROM mcp_invoices i WHERE i.status = 'draft' ORDER BY i.created_at LIMIT {lim(params.limit)}""")
        return dumps({"as_of": on, "engagements_due": due, "milestones_awaiting_invoice": miles, "open_drafts": drafts})

    @mcp.tool(name="find_invoices", annotations={"title": "Find an invoice", **RO})
    async def find_invoices(params: FindInvoicesIn) -> str:
        """Invoices by words, number, client, engagement, status, overdue or issue period: number, client, engagement, dates, total, paid, balance, status, overdue and open claims. `q` alone answers a plain list
        (invoice_id, label = 'INV-00042 — Acme, Mar 2026'). Needs invoices.draft on a staffed engagement, or Billing, Finance or the admin for all — a Consultant sees no invoice."""
        try:
            f, t = day(params.issued_from, "issued_from"), day(params.issued_to, "issued_to")
        except BadDate as ex:
            return err(str(ex))
        w = Where()
        match_words(w, params.q, "i.number", "i.client_name", "i.engagement_number", "i.engagement_name")
        if params.client_id is not None:
            w.add("i.client_id = {}", params.client_id)
        if params.engagement_id is not None:
            w.add("i.engagement_id = {}", params.engagement_id)
        if params.status:
            w.add("i.status = ANY ({})", params.status)
        if params.overdue is not None:
            w.add("i.overdue = {}", params.overdue)
        if f:
            w.add("i.issue_date >= {}", f)
        if t:
            w.add("i.issue_date <= {}", t)
        rows = await fetch(f"SELECT {INV_COLS}, {INV_LABEL} AS label FROM mcp_invoices i WHERE {w.sql()} ORDER BY i.created_at DESC, i.invoice_id DESC LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return plain(rows, "invoice_id", "label")
        return dumps({"invoices": rows})

    @mcp.tool(name="get_invoice", annotations={"title": "One invoice in full", **RO})
    async def get_invoice(params: InvoiceIn) -> str:
        """One invoice in full, by invoice_id or number: the head, the lines, the backing items per line (time entries with person and date, expenses with the receipt flag, usage charges, milestones), the payments, the credits applied,
        the clients' claims and the reminders sent. The secure link's state and the last events are not here (record_history). Same gate as find_invoices."""
        if params.invoice_id is None and not params.number:
            return err("Give invoice_id or number.")
        w = Where()
        if params.invoice_id is not None:
            w.add("i.invoice_id = {}", params.invoice_id)
        else:
            w.add("i.number = {}", params.number.strip().upper())
        head = await fetch(f"""SELECT {INV_COLS}, i.memo, i.terms_text, i.internal_note, i.nte_override_reason, i.issued_at, i.created_at FROM mcp_invoices i WHERE {w.sql()}""", *w.args)
        if not head:
            return err("No such invoice among those you may see.")
        inv = head[0]
        iid = inv["invoice_id"]
        lines = await fetch("SELECT l.line_id, l.sort_order, l.kind, l.description, l.quantity, l.unit, l.unit_price, l.tax_rate, l.line_subtotal, l.line_tax, l.line_total, l.item_count FROM mcp_invoice_lines l WHERE l.invoice_id = $1 ORDER BY l.sort_order, l.line_id", iid)
        items = await fetch("""SELECT x.line_id, x.amount,
                                      COALESCE(CASE WHEN x.time_entry_id IS NOT NULL THEN 'time' WHEN x.expense_id IS NOT NULL THEN 'expense' WHEN x.usage_charge_id IS NOT NULL THEN 'usage' ELSE 'milestone' END, 'item') AS kind,
                                      t.entry_id AS time_entry_id, t.entry_date AS time_date, t.member_name AS time_member, t.hours AS time_hours, t.note AS time_note,
                                      e.expense_id, e.number AS expense_number, e.expense_date, e.description AS expense_description, e.has_receipt AS expense_has_receipt,
                                      u.usage_charge_id, u.number AS usage_number, u.description AS usage_description, u.provider_name,
                                      m.milestone_id, m.name AS milestone_name
                                 FROM mcp_invoice_line_items x
                                 LEFT JOIN mcp_time_entries t ON t.entry_id = x.time_entry_id LEFT JOIN mcp_expenses e ON e.expense_id = x.expense_id
                                 LEFT JOIN mcp_usage_charges u ON u.usage_charge_id = x.usage_charge_id LEFT JOIN mcp_milestones m ON m.milestone_id = x.milestone_id
                                WHERE x.invoice_id = $1 ORDER BY x.line_id, x.item_id""", iid)
        for line in lines:
            line["items"] = [{k: v for k, v in it.items() if v is not None and k != "line_id"} for it in items if it["line_id"] == line["line_id"]]
        pays = await fetch("SELECT p.payment_id, p.paid_on, p.amount, p.currency, p.method, p.reference, p.source, p.status, p.voided_at, p.void_reason FROM mcp_invoice_payments p WHERE p.invoice_id = $1 ORDER BY p.paid_on, p.payment_id", iid)
        credits = await fetch("""SELECT a.credit_note_id, n.number AS credit_note_number, a.amount, a.applied_at FROM mcp_credit_applications a LEFT JOIN mcp_credit_notes n ON n.credit_note_id = a.credit_note_id WHERE a.invoice_id = $1 ORDER BY a.applied_at""", iid)
        claims = await fetch("SELECT k.claim_id, k.claimed_on, k.method, k.reference, k.amount, k.note, k.status, k.payment_id, k.decided_at FROM mcp_payment_claims k WHERE k.invoice_id = $1 ORDER BY k.created_at", iid)
        return dumps({"invoice": inv, "lines": lines, "payments": pays, "credits_applied": credits, "claims": claims,
                      "reminders": {"sent": inv["reminder_count"], "last_at": inv["last_reminder_at"]}})

    @mcp.tool(name="revenue", annotations={"title": "What was invoiced", **RO})
    async def revenue(params: RevenueIn) -> str:
        """What was invoiced (issued, not void) in a period by client, engagement, line kind, practice or month: invoices, subtotal, tax and total per currency. reports.view; a Manager only their own engagements."""
        if params.by not in ("client", "engagement", "kind", "practice", "month"):
            return err("by is one of client, engagement, kind, practice, month.")
        if not await has_right(fetch, "reports.view"):
            return need("reports.view", "Revenue")
        try:
            f, t = period(params.from_, params.to)
        except BadDate as ex:
            return err(str(ex))
        rows = await fetch("SELECT group_id, group_label, currency, invoices, subtotal, tax, total FROM ct_revenue($1, $2, $3)", f, t, params.by)
        return dumps({"from": f, "to": t, "by": params.by, "rows": rows})

    @mcp.tool(name="ar_aging", annotations={"title": "Who owes us", **RO})
    async def ar_aging(params: AgingIn) -> str:
        """Who owes us today, bucketed by the date asked: current, 1–30, 31–60, 61–90 and 90+ days late, per client and currency, with the open-invoice count. Billing, or a reader of every engagement (Finance, the admin)."""
        if not await receivables_ok(fetch):
            return need("receivables (billing, or reports across every engagement)", "The aging")
        try:
            on = day(params.as_of, "as_of") or await today(fetch)
        except BadDate as ex:
            return err(str(ex))
        rows = await fetch("SELECT client_id, client_name, currency, current_due, d1_30, d31_60, d61_90, d90_plus, total, open_invoices FROM ct_ar_aging($1)", on)
        return dumps({"as_of": on, "rows": rows})

    @mcp.tool(name="payment_claims", annotations={"title": "What clients say they paid", **RO})
    async def payment_claims(params: ClaimsIn) -> str:
        """What clients said on the secure link and billing has not yet decided — invoice, date, method, reference, amount — or one invoice's claims. `q` alone answers a plain list (claim_id, label). Billing, or the readers of an invoice."""
        w = Where()
        if not q_alone(params):
            w.add("k.status = {}", params.status)
        match_words(w, params.q, "k.invoice_number", "k.method", "k.reference")
        if params.invoice_id is not None:
            w.add("k.invoice_id = {}", params.invoice_id)
        rows = await fetch(f"""SELECT k.claim_id, k.invoice_id, k.invoice_number, k.claimed_on, k.method, k.reference, k.amount, k.note, k.status, k.payment_id, k.decided_at,
                                      k.invoice_number || ' — ' || COALESCE(k.method, 'payment') || ' ' || trim(to_char(k.amount, 'FM999999990.00')) AS label
                                 FROM mcp_payment_claims k WHERE {w.sql()} ORDER BY k.claimed_on, k.claim_id LIMIT {lim(params.limit)}""", *w.args)
        if q_alone(params):
            return plain(rows, "claim_id", "label")
        return dumps({"claims": rows})

    @mcp.tool(name="realization", annotations={"title": "Hours approved against hours billed", **RO})
    async def realization(params: RealizationIn) -> str:
        """Of the hours approved in a period, how many were billed, written off, still unbilled or had no rate; the standard value against the billed amount — per engagement. reports.view; a Manager their own."""
        if not await has_right(fetch, "reports.view"):
            return need("reports.view", "Realization")
        try:
            f, t = period(params.from_, params.to)
        except BadDate as ex:
            return err(str(ex))
        rows = await fetch("SELECT * FROM ct_realization($1, $2, $3)", f, t, params.engagement_id)
        return dumps({"from": f, "to": t, "rows": rows})

    @mcp.tool(name="margin", annotations={"title": "Margin per engagement", **RO})
    async def margin(params: MarginIn) -> str:
        """Bill value less cost (time at cost rate, expenses, usage at cost) per engagement for a period — only to those who may see cost, and for a Manager only on the engagements they manage; the same nothing otherwise."""
        if not await has_right(fetch, "cost.view"):
            return need("cost.view", "Margin")
        try:
            f, t = period(params.from_, params.to)
        except BadDate as ex:
            return err(str(ex))
        rows = await fetch("SELECT * FROM ct_margin($1, $2, $3)", f, t, params.engagement_id)
        return dumps({"from": f, "to": t, "rows": rows})
