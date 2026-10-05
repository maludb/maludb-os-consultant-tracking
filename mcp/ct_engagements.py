"""Clients and engagements (tool surface: find_clients, get_client, client_statement, find_engagements, get_engagement, engagement_staffing, milestones, budget_status, budget_alerts).
Reads mcp_clients, mcp_client_contacts, mcp_engagements, mcp_engagement_members, mcp_engagement_role_rates, mcp_milestones, mcp_budget_alerts, mcp_attachments and the functions ct_budget_status() and
ct_client_statement(). A record's last events are the activity server's (record_history) — the records role cannot read the log."""
from __future__ import annotations

from pydantic import Field

from ct_common import OPEN_INVOICE, RO, BadDate, Where, _Base, day, dumps, err, has_right, lim, match_words, me, need, plain, q_alone, receivables_ok, today


class FindClientsIn(_Base):
    q: str | None = Field(None, description="Words of a name, legal name or code. `q` alone answers a plain list (client_id, name)")
    archived: bool = Field(False, description="True to include archived clients")
    limit: int = Field(25, ge=1, le=100)


class ClientIn(_Base):
    client_id: int


class StatementIn(_Base):
    client_id: int
    from_: str | None = Field(None, alias="from", description="First day; the beginning by default")
    to: str | None = Field(None, description="Last day; today by default")


class FindEngagementsIn(_Base):
    q: str | None = Field(None, description="Words of the number, name or client. `q` alone answers a plain list (engagement_id, label = 'ENG-0042 — Acme pilot')")
    client_id: int | None = None
    kind: str | None = Field(None, description="time_and_materials, fixed_bid or retainer")
    status: list[str] | None = Field(None, description="draft, active, on_hold, closed, cancelled")
    manager: str | None = Field(None, description="'me' or a member id")
    department_id: int | None = None
    ending_within_days: int | None = Field(None, ge=0, le=730, description="Active engagements whose end date falls within this many days")
    mine: bool = Field(False, description="Only those you manage or are staffed on")
    limit: int = Field(25, ge=1, le=100)


class EngagementIn(_Base):
    engagement_id: int | None = None
    number: str | None = Field(None, description="ENG-0042")


class StaffingIn(_Base):
    engagement_id: int | None = Field(None, description="Who is staffed on this engagement")
    member_id: int | None = Field(None, description="...or everything this person is staffed on")
    on_date: str | None = Field(None, description="Staffed on that day (today by default); 'all' is not a date — pass include_ended instead")
    include_ended: bool = Field(False, description="Also staffing that has ended")
    limit: int = Field(50, ge=1, le=100)


class MilestonesIn(_Base):
    q: str | None = Field(None, description="Words of a milestone's name. `q` alone answers a plain list (milestone_id, name)")
    engagement_id: int | None = None
    status: list[str] | None = Field(None, description="planned, delivered, accepted, invoiced, cancelled")
    due_before: str | None = Field(None, description="YYYY-MM-DD")
    unbilled_accepted: bool = Field(False, description="Only accepted milestones not yet on an invoice")
    limit: int = Field(25, ge=1, le=100)


class BudgetIn(_Base):
    engagement_id: int | None = Field(None, description="One engagement; omit for every active one")
    over_pct: float | None = Field(None, ge=0, description="Only engagements at or over this percent of any budget they have")
    limit: int = Field(25, ge=1, le=100)


class AlertsIn(_Base):
    engagement_id: int | None = None
    unacknowledged: bool = Field(True, description="Only alerts nobody has acknowledged (the default)")
    limit: int = Field(25, ge=1, le=100)


ENG_COLS = """e.engagement_id, e.number, e.name, e.client_id, e.client_name, e.kind, e.status, e.start_date, e.end_date, e.currency, e.department_id, e.department_name,
       e.manager_member_id, e.manager_name, e.billing_cadence, e.next_billing_date, e.i_manage, e.i_am_staffed, e.staffed_count,
       e.number || ' — ' || e.name AS label"""


async def ai_visible(fetch, engagement_id: int | None = None) -> bool:
    """The AI budget's figures are a cost: shown under cost.view (on a Manager's own engagements) or usage.write, else only whether a budget is set."""
    if await has_right(fetch, "usage.write"):
        return True
    if engagement_id is None:
        return await has_right(fetch, "cost.view")
    return bool((await fetch("SELECT ct_cost_visible($1) AS ok", engagement_id))[0]["ok"])


def mask_budget(b: dict, visible: bool) -> dict:
    if not visible:
        b["ai_budget_amount"] = "set" if b.get("ai_budget_amount") is not None else None
        b["ai_cost"] = None
        b["ai_pct"] = None
    return b


def register(mcp, fetch) -> None:
    @mcp.tool(name="find_clients", annotations={"title": "Find a client", **RO})
    async def find_clients(params: FindClientsIn) -> str:
        """Clients by name, legal name or code, with terms, currency, PO rule and active engagements; contact details only to those who bill or manage clients. Call before engagement_save or invoice_draft.
        `q` alone answers a plain list (client_id, name). You see clients you are staffed on (no details); clients.manage or billing see all."""
        w = Where()
        if not params.archived:
            w.raw("c.archived_at IS NULL")
        match_words(w, params.q, "c.name", "c.legal_name", "c.code")
        rows = await fetch(f"""SELECT c.client_id, c.name, c.legal_name, c.code, c.terms_days, c.currency, c.po_required, c.default_rate_card_id, c.archived_at, c.active_engagements,
                                      c.email, c.phone, c.billing_address, c.tax_id FROM mcp_clients c WHERE {w.sql()} ORDER BY lower(c.name), c.client_id LIMIT {lim(params.limit)}""", *w.args)
        if q_alone(params):
            return plain(rows, "client_id", "name")
        return dumps({"clients": rows})

    @mcp.tool(name="get_client", annotations={"title": "One client in full", **RO})
    async def get_client(params: ClientIn) -> str:
        """One client in full: contacts (who receives invoices), engagements by status, open invoices and the balance (to those who bill), and the last statement lines (to those who may read receivables)."""
        c = await fetch("""SELECT c.client_id, c.name, c.legal_name, c.code, c.terms_days, c.currency, c.po_required, c.default_rate_card_id, c.member_id, c.archived_at, c.created_at, c.updated_at,
                                  c.email, c.phone, c.billing_address, c.tax_id, c.notes, c.active_engagements FROM mcp_clients c WHERE c.client_id = $1""", params.client_id)
        if not c:
            return err("No such client among those you may see.")
        contacts = await fetch("SELECT k.contact_id, k.name, k.role, k.receives_invoices, k.is_primary, k.email, k.phone FROM mcp_client_contacts k WHERE k.client_id = $1 AND k.archived_at IS NULL ORDER BY k.is_primary DESC, lower(k.name)", params.client_id)
        engs = await fetch(f"SELECT {ENG_COLS} FROM mcp_engagements e WHERE e.client_id = $1 ORDER BY e.status, e.number", params.client_id)
        inv = await fetch("""SELECT i.invoice_id, i.number, i.engagement_number, i.issue_date, i.due_date, i.currency, i.status, i.total, i.balance_due, i.overdue
                               FROM mcp_invoices i WHERE i.client_id = $1 AND i.status = ANY ($2) ORDER BY i.due_date, i.number""", params.client_id, list(OPEN_INVOICE))
        balance: dict[str, float] = {}
        for i in inv:
            balance[i["currency"].strip()] = round(balance.get(i["currency"].strip(), 0) + float(i["balance_due"]), 2)
        statement = []
        if await receivables_ok(fetch):
            statement = (await fetch("SELECT entry_date, kind, number, description, currency, charge, credit, running_balance FROM ct_client_statement($1, NULL, current_date)", params.client_id))[-10:]
        return dumps({"client": c[0], "contacts": contacts, "engagements": engs, "open_invoices": inv, "balance_by_currency": balance, "statement_last_lines": statement})

    @mcp.tool(name="client_statement", annotations={"title": "A client's statement", **RO})
    async def client_statement(params: StatementIn) -> str:
        """A client's statement: invoices, credit notes, payments and write-offs in date order with the running balance per currency. Billing, or a reader of every engagement (Finance, the admin)."""
        if not await receivables_ok(fetch):
            return need("receivables (billing, or reports across every engagement)", "A client's statement")
        try:
            f, t = day(params.from_, "from"), day(params.to, "to") or await today(fetch)
        except BadDate as ex:
            return err(str(ex))
        if not await fetch("SELECT 1 FROM mcp_clients WHERE client_id = $1", params.client_id):
            return err("No such client among those you may see.")
        rows = await fetch("SELECT entry_date, kind, number, description, currency, charge, credit, running_balance FROM ct_client_statement($1, $2, $3)", params.client_id, f, t)
        return dumps({"client_id": params.client_id, "from": f, "to": t, "lines": rows})

    @mcp.tool(name="find_engagements", annotations={"title": "Find an engagement", **RO})
    async def find_engagements(params: FindEngagementsIn) -> str:
        """Engagements by words, client, kind, status, manager, practice or ending soon: number, name, client, kind, status, dates, currency, manager, i_manage, i_am_staffed, staffed count.
        `q` alone answers a plain list (engagement_id, label = 'ENG-0042 — Acme pilot') for the resolver. You see engagements you are staffed on, manage or whose practice you administer; Billing, Finance and the admin see all."""
        w = Where()
        match_words(w, params.q, "e.number", "e.name", "e.client_name")
        if params.client_id is not None:
            w.add("e.client_id = {}", params.client_id)
        if params.kind:
            w.add("e.kind = {}", params.kind)
        if params.status:
            w.add("e.status = ANY ({})", params.status)
        if params.manager == "me":
            w.add("e.manager_member_id = {}", await me(fetch))
        elif params.manager and params.manager.isdigit():
            w.add("e.manager_member_id = {}", int(params.manager))
        if params.department_id is not None:
            w.add("e.department_id = {}", params.department_id)
        if params.ending_within_days is not None:
            t = await today(fetch)
            w.raw("e.status = 'active'").add("e.end_date IS NOT NULL AND e.end_date >= {}", t).add("e.end_date <= {}::date + {}::int", t, params.ending_within_days)
        if params.mine:
            w.raw("(e.i_manage OR e.i_am_staffed)")
        rows = await fetch(f"SELECT {ENG_COLS} FROM mcp_engagements e WHERE {w.sql()} ORDER BY e.number DESC LIMIT {lim(params.limit)}", *w.args)
        if q_alone(params):
            return plain(rows, "engagement_id", "label")
        return dumps({"engagements": rows})

    @mcp.tool(name="get_engagement", annotations={"title": "One engagement in full", **RO})
    async def get_engagement(params: EngagementIn) -> str:
        """One engagement in full, by engagement_id or number: every field, the client, the manager, the staffing (roles; overrides with rates.view), the role rates (rates.view), the milestones, the budget status,
        the open alerts, the next billing date and the documents' names. Cost never comes here (margin); the last events are record_history's."""
        if params.engagement_id is None and not params.number:
            return err("Give engagement_id or number.")
        w = Where()
        if params.engagement_id is not None:
            w.add("e.engagement_id = {}", params.engagement_id)
        else:
            w.add("e.number = {}", params.number.strip().upper())
        e = await fetch(f"""SELECT e.engagement_id, e.number, e.client_id, e.client_name, e.name, e.kind, e.status, e.start_date, e.end_date, e.currency, e.po_number, e.rate_card_id, e.nte_amount, e.budget_hours,
                                   e.budget_amount, e.ai_budget_amount, e.expense_policy, e.billing_cadence, e.next_billing_date, e.time_grouping, e.share_receipts_with_client, e.department_id, e.department_name,
                                   e.manager_member_id, e.manager_name, e.projects_ref, e.description, e.activated_at, e.closed_at, e.created_at, e.updated_at, e.i_manage, e.i_am_staffed, e.staffed_count
                              FROM mcp_engagements e WHERE {w.sql()}""", *w.args)
        if not e:
            return err("No such engagement among those you may see.")
        eng = e[0]
        eid = eng["engagement_id"]
        vis = await ai_visible(fetch, eid)
        if not vis:
            eng["ai_budget_amount"] = "set" if eng["ai_budget_amount"] is not None else None
        staffing = await fetch("""SELECT s.staffing_id, s.member_id, s.member_name, s.member_kind, s.consulting_role_id, s.role_title, s.bill_rate_override, s.valid_from, s.valid_to, s.note,
                                         (s.valid_from <= current_date AND (s.valid_to IS NULL OR s.valid_to > current_date)) AS is_current
                                    FROM mcp_engagement_members s WHERE s.engagement_id = $1 ORDER BY s.valid_to NULLS FIRST, lower(s.member_name)""", eid)
        rates = await has_right(fetch, "rates.view")
        role_rates = await fetch("SELECT r.consulting_role_id, r.role_title, r.bill_rate FROM mcp_engagement_role_rates r WHERE r.engagement_id = $1 ORDER BY r.role_title", eid) if rates else None
        milestones = await fetch("""SELECT m.milestone_id, m.name, m.description, m.amount, m.due_date, m.status, m.delivered_at, m.accepted_at, m.accepted_by_name, m.acceptance_note, m.cancelled_at, m.cancel_reason, m.billed
                                      FROM mcp_milestones m WHERE m.engagement_id = $1 ORDER BY m.sort_order, m.milestone_id""", eid)
        b = await fetch("SELECT * FROM ct_budget_status($1)", eid)
        budget = mask_budget(b[0], vis) if b else None
        alerts = await fetch("SELECT a.alert_id, a.kind, a.threshold_pct, a.value_at, a.budget_at, a.written_at FROM mcp_budget_alerts a WHERE a.engagement_id = $1 AND a.acknowledged_at IS NULL ORDER BY a.written_at DESC", eid)
        if not vis:
            for a in alerts:
                if a["kind"] == "ai":
                    a["value_at"] = a["budget_at"] = None
        docs = await fetch("SELECT a.attachment_id, a.filename, a.mime_type, a.byte_size, a.client_visible, a.created_at FROM mcp_attachments a WHERE a.record_type = 'engagement' AND a.record_id = $1 ORDER BY a.created_at", eid)
        return dumps({"engagement": eng, "staffing": staffing, "role_rates": role_rates, "milestones": milestones, "budget": budget, "open_alerts": alerts, "documents": docs})

    @mcp.tool(name="engagement_staffing", annotations={"title": "Who is staffed where", **RO})
    async def engagement_staffing(params: StaffingIn) -> str:
        """Who is staffed on an engagement, in what role, from when, with the override (rates.view); or everything one person is staffed on. Staffed today by default; on_date for another day; include_ended for the history."""
        if params.engagement_id is None and params.member_id is None:
            return err("Give engagement_id or member_id.")
        try:
            on = day(params.on_date, "on_date") or await today(fetch)
        except BadDate as ex:
            return err(str(ex))
        w = Where()
        if params.engagement_id is not None:
            w.add("s.engagement_id = {}", params.engagement_id)
        if params.member_id is not None:
            w.add("s.member_id = {}", params.member_id)
        if not params.include_ended:
            w.add("s.valid_from <= {}", on).add("(s.valid_to IS NULL OR s.valid_to > {})", on)
        rows = await fetch(f"""SELECT s.staffing_id, s.engagement_id, e.number AS engagement_number, e.name AS engagement_name, s.member_id, s.member_name, s.member_kind, s.consulting_role_id, s.role_title,
                                      s.bill_rate_override, s.valid_from, s.valid_to, s.note
                                 FROM mcp_engagement_members s JOIN mcp_engagements e ON e.engagement_id = s.engagement_id WHERE {w.sql()} ORDER BY e.number, lower(s.member_name) LIMIT {lim(params.limit, 50)}""", *w.args)
        return dumps({"on_date": on, "staffing": rows})

    @mcp.tool(name="milestones", annotations={"title": "Milestones", **RO})
    async def milestones(params: MilestonesIn) -> str:
        """The milestones of an engagement, or every milestone due, delivered or accepted-and-unbilled across the engagements you see. `q` alone answers a plain list (milestone_id, name)."""
        w = Where()
        match_words(w, params.q, "m.name")
        if params.engagement_id is not None:
            w.add("m.engagement_id = {}", params.engagement_id)
        if params.status:
            w.add("m.status = ANY ({})", params.status)
        try:
            due = day(params.due_before, "due_before")
        except BadDate as ex:
            return err(str(ex))
        if due:
            w.add("m.due_date <= {}", due).raw("m.status NOT IN ('invoiced', 'cancelled')")
        if params.unbilled_accepted:
            w.raw("m.status = 'accepted' AND NOT COALESCE(m.billed, false)")
        rows = await fetch(f"""SELECT m.milestone_id, m.engagement_id, e.number AS engagement_number, e.name AS engagement_name, m.name, m.description, m.amount, e.currency, m.due_date, m.status, m.delivered_at,
                                      m.accepted_at, m.accepted_by_name, m.billed
                                 FROM mcp_milestones m JOIN mcp_engagements e ON e.engagement_id = m.engagement_id WHERE {w.sql()} ORDER BY m.due_date NULLS LAST, m.milestone_id LIMIT {lim(params.limit)}""", *w.args)
        if q_alone(params):
            return plain(rows, "milestone_id", "name")
        return dumps({"milestones": rows})

    @mcp.tool(name="budget_status", annotations={"title": "Where an engagement stands against its budgets", **RO})
    async def budget_status(params: BudgetIn) -> str:
        """Where an engagement stands: hours used of the budget, bill value against the fee, invoiced against the not-to-exceed, AI cost against the AI budget, the pace and the weeks left — for one engagement,
        or for every active one at or over `over_pct` of any budget. The AI figure is a cost: shown to cost.view (on a Manager's own engagements) and usage.write, else only whether a budget is set."""
        if params.engagement_id is not None:
            if not (await fetch("SELECT ct_can_see_engagement($1) AS ok", params.engagement_id))[0]["ok"]:
                return err("No such engagement among those you may see.")
            rows = await fetch("""SELECT e.number AS engagement_number, e.name AS engagement_name, e.client_name, e.currency, e.status, b.* FROM mcp_engagements e CROSS JOIN LATERAL ct_budget_status(e.engagement_id) b WHERE e.engagement_id = $1""", params.engagement_id)
        else:
            rows = await fetch("""SELECT e.number AS engagement_number, e.name AS engagement_name, e.client_name, e.currency, e.status, b.* FROM mcp_engagements e CROSS JOIN LATERAL ct_budget_status(e.engagement_id) b
                                   WHERE e.status = 'active' ORDER BY e.number""")
        sees_usage = await has_right(fetch, "usage.write")
        out = []
        for r in rows:
            vis = sees_usage or bool((await fetch("SELECT ct_cost_visible($1) AS ok", r["engagement_id"]))[0]["ok"])
            mask_budget(r, vis)
            if params.over_pct is not None:
                top = max([float(r[k]) for k in ("hours_pct", "amount_pct", "nte_pct", "ai_pct") if r.get(k) is not None] or [-1])
                if top < params.over_pct:
                    continue
            out.append(r)
        return dumps({"budgets": out[: lim(params.limit)]})

    @mcp.tool(name="budget_alerts", annotations={"title": "Budget alerts", **RO})
    async def budget_alerts(params: AlertsIn) -> str:
        """The alerts written and not yet acknowledged — which engagement crossed which threshold when — or an engagement's whole history of them. Managers of the engagement and readers of reports."""
        w = Where()
        if params.engagement_id is not None:
            w.add("a.engagement_id = {}", params.engagement_id)
        if params.unacknowledged:
            w.raw("a.acknowledged_at IS NULL")
        rows = await fetch(f"""SELECT a.alert_id, a.engagement_id, a.engagement_number, a.kind, a.threshold_pct, a.value_at, a.budget_at, a.written_at, a.acknowledged_by, a.acknowledged_at
                                 FROM mcp_budget_alerts a WHERE {w.sql()} ORDER BY a.written_at DESC, a.alert_id DESC LIMIT {lim(params.limit)}""", *w.args)
        if rows and not (await has_right(fetch, "usage.write")):
            for r in rows:
                if r["kind"] == "ai" and not (await fetch("SELECT ct_cost_visible($1) AS ok", r["engagement_id"]))[0]["ok"]:
                    r["value_at"] = r["budget_at"] = None
        return dumps({"alerts": rows})
