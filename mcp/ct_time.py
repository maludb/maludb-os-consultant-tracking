"""Time (tool surface: my_timesheet, my_timer, missing_time, timesheets_pending, time_summary, utilization, time_written_off). Reads mcp_timesheets, mcp_time_entries, mcp_time_timers and the functions
ct_missing_time() and ct_utilization(). The views blank a bill rate without rates.view, a cost rate without cost.view on a managed engagement, and an internal note from anyone who may not read it."""
from __future__ import annotations

from pydantic import Field

from ct_common import RO, BadDate, Where, _Base, day, dumps, err, has_right, lim, match_words, me, need, period, plain, q_alone, today


class MyTimesheetIn(_Base):
    week_start: str | None = Field(None, description="Any day of the week (YYYY-MM-DD); this week by default")
    member_id: int | None = Field(None, description="Another person's week (needs time.approve on a staffed engagement)")
    q: str | None = Field(None, description="Words of an entry (engagement, activity, note, person) — yours first. `q` alone answers a plain list (entry_id, label)")
    limit: int = Field(25, ge=1, le=100)


class MissingIn(_Base):
    week_start: str | None = Field(None, description="Any day of the week; last week by default")


class PendingIn(_Base):
    q: str | None = Field(None, description="A person's name or the week's date. `q` alone answers a plain list (timesheet_id, label = 'Priya — week of Mar 2')")
    member_id: int | None = None
    limit: int = Field(25, ge=1, le=100)


class SummaryIn(_Base):
    engagement_id: int | None = None
    client_id: int | None = None
    member_id: int | None = None
    from_: str = Field(..., alias="from")
    to: str
    group_by: str = Field("person", description="person, activity, day, week, billable or status")
    status: list[str] | None = Field(None, description="draft, submitted, approved, rejected, written_off")


class UtilizationIn(_Base):
    from_: str = Field(..., alias="from")
    to: str
    member_id: int | None = None
    department_id: int | None = None


class WrittenOffIn(_Base):
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    engagement_id: int | None = None
    limit: int = Field(50, ge=1, le=100)


GROUPS = {
    "person": ("t.member_id::text", "t.member_name"),
    "activity": ("COALESCE(t.activity_type_id::text, '0')", "COALESCE(t.activity_label, '—')"),
    "day": ("t.entry_date::text", "t.entry_date::text"),
    "week": ("ct_week_start(t.entry_date)::text", "'week of ' || ct_week_start(t.entry_date)::text"),
    "billable": ("t.billable::text", "CASE WHEN t.billable THEN 'billable' ELSE 'not billable' END"),
    "status": ("t.status", "t.status"),
}
ENTRY_LABEL = "to_char(t.entry_date, 'Mon FMDD') || ' — ' || t.engagement_number || ' — ' || trim(trailing '.' from to_char(t.hours, 'FM990.99')) || ' h' || COALESCE(' — ' || left(t.note, 40), '')"


def register(mcp, fetch) -> None:
    @mcp.tool(name="my_timesheet", annotations={"title": "A week of time", **RO})
    async def my_timesheet(params: MyTimesheetIn) -> str:
        """The caller's week: the entries by day and engagement with their notes, the totals, the status and the rejection reason; any week by week_start; another person's with time.approve. An agent's "log my time" starts here.
        `q` alone resolves an entry by words — yours first, then a team member's an approver may see (labelled with the person): a plain list (entry_id, label)."""
        who = await me(fetch)
        if q_alone(params):
            # The resolver's call: the caller's own entries first, then any the view admits (an approver resolves a team member's entry by words to write it off) — labelled with the person when it is not the caller's.
            w = Where()
            me_ = w.p(who)
            match_words(w, params.q, "t.engagement_number", "t.engagement_name", "t.activity_label", "t.note", "t.member_name")
            rows = await fetch(f"""SELECT t.entry_id, CASE WHEN t.member_id = {me_} THEN '' ELSE t.member_name || ' — ' END || {ENTRY_LABEL} AS label FROM mcp_time_entries t WHERE {w.sql()}
                                    ORDER BY (t.member_id = {me_}) DESC, t.entry_date DESC, t.entry_id DESC LIMIT {lim(params.limit)}""", *w.args)
            return plain(rows, "entry_id", "label")
        try:
            on = day(params.week_start, "week_start") or await today(fetch)
        except BadDate as ex:
            return err(str(ex))
        member = params.member_id if params.member_id is not None else who
        ws = (await fetch("SELECT ct_week_start($1) AS d", on))[0]["d"]
        sheet = await fetch("""SELECT t.timesheet_id, t.member_id, t.member_name, t.week_start, t.status, t.total_hours, t.billable_hours, t.submitted_at, t.approved_at, t.rejected_at, t.rejection_reason
                                 FROM mcp_timesheets t WHERE t.member_id = $1 AND t.week_start = $2""", member, ws)
        entries = await fetch("""SELECT t.entry_id, t.entry_date, t.engagement_id, t.engagement_number, t.engagement_name, t.hours, t.activity_type_id, t.activity_label, t.billable, t.note, t.internal_note, t.status,
                                        t.bill_rate, t.rate_source, t.bill_value, t.cost_rate, t.currency, t.billed, t.written_off_at, t.written_off_reason
                                   FROM mcp_time_entries t WHERE t.member_id = $1 AND t.entry_date >= $2 AND t.entry_date < $2 + 7 ORDER BY t.entry_date, t.engagement_number, t.entry_id""", member, ws)
        by_day: dict[str, float] = {}
        for e in entries:
            by_day[str(e["entry_date"])] = round(by_day.get(str(e["entry_date"]), 0) + float(e["hours"]), 2)
        if not sheet and not entries:
            return dumps({"week_start": ws, "member_id": member, "timesheet": None, "entries": [], "hours_by_day": {}, "total_hours": 0})
        return dumps({"week_start": ws, "member_id": member, "timesheet": sheet[0] if sheet else None, "entries": entries, "hours_by_day": by_day, "total_hours": round(sum(by_day.values()), 2)})

    @mcp.tool(name="my_timer", annotations={"title": "Is a timer running", **RO})
    async def my_timer() -> str:
        """Whether a timer runs for the caller, since when, on what engagement and activity, and the elapsed hours."""
        rows = await fetch("SELECT t.engagement_id, t.engagement_number, t.engagement_name, t.activity_type_id, t.note, t.started_at, t.elapsed_hours FROM mcp_time_timers t")
        return dumps({"running": bool(rows), "timer": rows[0] if rows else None})

    @mcp.tool(name="missing_time", annotations={"title": "Who has not submitted a week", **RO})
    async def missing_time(params: MissingIn) -> str:
        """Who has not submitted a week and which weekdays are empty, with their hours logged and capacity — last week by default. Needs time.approve or reports.view."""
        if not (await has_right(fetch, "time.approve") or await has_right(fetch, "reports.view")):
            return need("time.approve or reports.view", "The missing-time list")
        try:
            ws = day(params.week_start, "week_start")
        except BadDate as ex:
            return err(str(ex))
        rows = await fetch("SELECT member_id, member_name, week_start, timesheet_id, status, hours_logged, empty_weekdays, capacity_hours FROM ct_missing_time($1)", ws)
        return dumps({"week_start": rows[0]["week_start"] if rows else ws, "people": rows})

    @mcp.tool(name="timesheets_pending", annotations={"title": "Weeks awaiting approval", **RO})
    async def timesheets_pending(params: PendingIn) -> str:
        """The submitted weeks awaiting the caller's approval (entries on engagements they may see with time.approve), oldest first, with the hours and the people. `q` alone resolves a week by person and date:
        a plain list (timesheet_id, label = 'Priya — week of Mar 2'). Needs time.approve; your own week is never yours to approve."""
        if not await has_right(fetch, "time.approve"):
            return need("time.approve", "Weeks awaiting approval")
        w = Where().raw("t.status = 'submitted'").add("t.member_id <> {}", await me(fetch))
        if params.member_id is not None:
            w.add("t.member_id = {}", params.member_id)
        match_words(w, params.q, "t.member_name", "to_char(t.week_start, 'Mon FMDD YYYY')")
        rows = await fetch(f"""SELECT t.timesheet_id, t.member_id, t.member_name, t.week_start, t.total_hours, t.billable_hours, t.submitted_at, t.submitted_entries,
                                      t.member_name || ' — week of ' || to_char(t.week_start, 'Mon FMDD') AS label
                                 FROM mcp_timesheets t WHERE {w.sql()} ORDER BY t.submitted_at, t.timesheet_id LIMIT {lim(params.limit)}""", *w.args)
        if q_alone(params):
            return plain(rows, "timesheet_id", "label")
        return dumps({"timesheets": rows})

    @mcp.tool(name="time_summary", annotations={"title": "Hours over a period", **RO})
    async def time_summary(params: SummaryIn) -> str:
        """Hours on an engagement (or a client, or a person) over a period grouped by person, activity, day, week, billable or status — with the bill value to those who may see rates. You see your own time and what the views admit."""
        if params.group_by not in GROUPS:
            return err("group_by is one of " + ", ".join(GROUPS) + ".")
        try:
            f, t = period(params.from_, params.to)
        except BadDate as ex:
            return err(str(ex))
        w = Where().add("t.entry_date >= {}", f).add("t.entry_date <= {}", t)
        if params.engagement_id is not None:
            w.add("t.engagement_id = {}", params.engagement_id)
        if params.client_id is not None:
            w.add("t.engagement_id IN (SELECT engagement_id FROM mcp_engagements WHERE client_id = {})", params.client_id)
        if params.member_id is not None:
            w.add("t.member_id = {}", params.member_id)
        w.add("t.status = ANY ({})", params.status) if params.status else w.raw("t.status <> 'rejected'")
        key, label = GROUPS[params.group_by]
        rows = await fetch(f"""SELECT {key} AS group_key, {label} AS group_label, round(sum(t.hours), 2) AS hours, round(COALESCE(sum(t.hours) FILTER (WHERE t.billable), 0), 2) AS billable_hours, count(*)::int AS entries,
                                      sum(t.bill_value) AS bill_value, min(t.currency) AS currency, count(DISTINCT t.currency)::int AS currencies
                                 FROM mcp_time_entries t WHERE {w.sql()} GROUP BY 1, 2 ORDER BY 1 LIMIT 400""", *w.args)
        return dumps({"from": f, "to": t, "group_by": params.group_by, "total_hours": round(sum(float(r["hours"]) for r in rows), 2), "groups": rows})

    @mcp.tool(name="utilization", annotations={"title": "Billable hours against capacity", **RO})
    async def utilization(params: UtilizationIn) -> str:
        """Billable hours against capacity per person for a period — your own (reports.view_own), a practice's or everyone's (reports.view)."""
        if not (await has_right(fetch, "reports.view") or await has_right(fetch, "reports.view_own")):
            return need("reports.view or reports.view_own", "Utilization")
        try:
            f, t = period(params.from_, params.to)
        except BadDate as ex:
            return err(str(ex))
        rows = await fetch("""SELECT member_id, member_name, department_id, department_name, capacity_hours, logged_hours, billable_hours, approved_billable_hours, utilization_pct FROM ct_utilization($1, $2, $3, $4)""",
                           f, t, params.member_id, params.department_id)
        return dumps({"from": f, "to": t, "people": rows})

    @mcp.tool(name="time_written_off", annotations={"title": "Hours written off", **RO})
    async def time_written_off(params: WrittenOffIn) -> str:
        """Hours written off (given away, never billed) in a period by engagement and person, with the reasons — and the entries themselves. Approvers and readers of every engagement; a person sees their own."""
        try:
            f, t = day(params.from_, "from"), day(params.to, "to")
        except BadDate as ex:
            return err(str(ex))
        w = Where().raw("t.status = 'written_off'")
        if f:
            w.add("t.entry_date >= {}", f)
        if t:
            w.add("t.entry_date <= {}", t)
        if params.engagement_id is not None:
            w.add("t.engagement_id = {}", params.engagement_id)
        rows = await fetch(f"""SELECT t.entry_id, t.entry_date, t.member_id, t.member_name, t.engagement_id, t.engagement_number, t.hours, t.bill_rate, t.bill_value, t.currency, t.written_off_at, t.written_off_reason
                                 FROM mcp_time_entries t WHERE {w.sql()} ORDER BY t.entry_date DESC, t.entry_id DESC LIMIT {lim(params.limit, 50)}""", *w.args)
        tot = await fetch(f"""SELECT t.engagement_number, t.member_name, round(sum(t.hours), 2) AS hours, sum(t.bill_value) AS bill_value, min(t.currency) AS currency
                                FROM mcp_time_entries t WHERE {w.sql()} GROUP BY 1, 2 ORDER BY 1, 2""", *w.args)
        return dumps({"entries": rows, "totals": tot})
