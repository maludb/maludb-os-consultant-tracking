"""consultant_tracking_activity_mcp — Consultant Tracking's activity-memory server (activity_log through mcp_activity_log, read-only).

Tool surface: docs/consultant-tracking-mcp-tool-surface.md, "Activity server". Every query runs as the asking member; the view decides which rows they see: their own, everything for Billing, Finance and the admin, a visible
engagement's rows for reports.view or time.approve, and the log of their own weeks. The activity role reads mcp_activity_log plus mcp_members, mcp_departments, mcp_engagements, mcp_clients, mcp_invoices and mcp_timesheets for
names and record facts. A cost rate, a secure token, a client's email or phone and a note beyond 200 characters are never in a payload (the writers' rule), so nothing here can return one.
"""
from __future__ import annotations

from mcp.server.fastmcp import FastMCP
from pydantic import Field

import db
from ct_common import RO, BadDate, Where, _Base, caller_tz, day, dumps, err, has_right, lim, localize, me

mcp = FastMCP("consultant_tracking_activity_mcp")


async def _pool():
    return await db.get_pool(db.ENV["MCP_ACTIVITY_DB_USER"], db.ENV["MCP_ACTIVITY_DB_PASSWORD"])


async def fetch(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


COLS = """l.activity_id, l.occurred_at, l.actor_member_id, l.actor_name, l.actor_kind, l.actor_client_id, l.source, l.action, l.entity_type, l.entity_id, l.engagement_id, l.client_id, l.invoice_id, l.timesheet_id,
       l.before, l.after, l.agent_run_id,
       CASE WHEN l.actor_client_id IS NOT NULL THEN 'the client' WHEN l.actor_name IS NULL THEN l.source
            WHEN l.actor_kind = 'agent' THEN l.actor_name || ' (agent)' ELSE l.actor_name END AS actor_label"""


class SearchIn(_Base):
    sql: str = Field(..., description="A single read-only SELECT over mcp_activity_log and the fact views (mcp_members, mcp_departments, mcp_engagements, mcp_clients, mcp_invoices, mcp_timesheets).")


class HistoryIn(_Base):
    entity_type: str | None = Field(None, description="engagement, timesheet, time_entry, expense, usage_charge, invoice, client, credit_note, milestone …")
    entity_id: int | None = None
    engagement_id: int | None = Field(None, description="Everything that touched this engagement")
    timesheet_id: int | None = Field(None, description="Everything that touched this week")
    invoice_id: int | None = Field(None, description="Everything that touched this invoice")
    since: str | None = Field(None, description="YYYY-MM-DD or an ISO time")
    order: str = Field("oldest", description="oldest or newest first")
    limit: int = Field(100, ge=1, le=200)


class TimelineIn(_Base):
    member_id: str | None = Field(None, description="A person or an agent (an id), or 'me' (the default)")
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    action: str | None = Field(None, description="An action or a prefix ending in a dot, e.g. invoice.")
    limit: int = Field(100, ge=1, le=200)


class TouchedIn(_Base):
    engagement_id: int | None = None
    invoice_id: int | None = None
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    limit: int = Field(50, ge=1, le=200)


class RecentIn(_Base):
    since: str = Field(..., description="YYYY-MM-DD or an ISO time")
    engagement_id: int | None = None
    limit: int = Field(100, ge=1, le=200)


class ShareReadsIn(_Base):
    from_: str | None = Field(None, alias="from")
    to: str | None = None
    app: str | None = Field(None, description="The sibling application's key, where the row names one (our own reads of gl and hr do; the kernel's token names none)")
    limit: int = Field(50, ge=1, le=200)


def since_ts(s: str):
    import datetime
    d = day(s[:10], "since")
    if len(s) > 10:
        try:
            t = datetime.datetime.fromisoformat(s.replace("Z", "+00:00"))
            return t if t.tzinfo else t.replace(tzinfo=datetime.timezone.utc)
        except ValueError as ex:
            raise BadDate("since is a day or an ISO time.") from ex
    return datetime.datetime.combine(d, datetime.time.min, tzinfo=datetime.timezone.utc)


def prefix(action: str) -> str:
    return action.replace("%", "").replace("_", "\\_") + ("%" if action.endswith(".") else "")


@mcp.tool(name="record_history", annotations={"title": "A record's history", **RO})
async def record_history(params: HistoryIn) -> str:
    """Who did what to one record, in order: an engagement, a week, an entry, an expense, a usage charge, an invoice, a client — each row worded with the actor (a person, an agent, a client by the secure link, or the worker),
    with the before and after. Give entity_type and entity_id, or engagement_id / timesheet_id / invoice_id for everything that touched it. You see the rows the record's own rule admits."""
    w = Where().raw("l.action <> 'screen.view'")
    if params.engagement_id is not None:
        w.add("l.engagement_id = {}", params.engagement_id)
    elif params.timesheet_id is not None:
        w.add("l.timesheet_id = {}", params.timesheet_id)
    elif params.invoice_id is not None:
        w.add("l.invoice_id = {}", params.invoice_id)
    elif params.entity_type and params.entity_id is not None:
        w.add("l.entity_type = {}", params.entity_type).add("l.entity_id = {}", params.entity_id)
    else:
        return err("Give entity_type and entity_id, or engagement_id, timesheet_id or invoice_id.")
    try:
        if params.since:
            w.add("l.occurred_at >= {}", since_ts(params.since))
    except BadDate as ex:
        return err(str(ex))
    tz = await caller_tz(fetch)
    order = "DESC" if params.order == "newest" else "ASC"
    rows = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at {order}, l.activity_id {order} LIMIT {lim(params.limit, 100, 200)}", *w.args)
    return dumps({"timezone": tz, "events": localize(rows, tz, "occurred_at")})


@mcp.tool(name="actor_timeline", annotations={"title": "What a person or an agent did", **RO})
async def actor_timeline(params: TimelineIn) -> str:
    """What a person or an agent did here in a period — every row, newest first, with a count by action. Yourself by default; another's rows only where the view admits them (reports.view or time.approve on a staffed engagement; Billing, Finance and the admin see all)."""
    who = await me(fetch)
    target = who if params.member_id in (None, "", "me") else (int(params.member_id) if str(params.member_id).isdigit() else None)
    if target is None:
        return err("member_id is an id or 'me'.")
    try:
        f, t = day(params.from_, "from"), day(params.to, "to")
    except BadDate as ex:
        return err(str(ex))
    w = Where().add("l.actor_member_id = {}", target)
    if f:
        w.add("l.occurred_at >= {}::date", f)
    if t:
        w.add("l.occurred_at < {}::date + 1", t)
    if params.action:
        w.add("l.action LIKE {}", prefix(params.action))
    tz = await caller_tz(fetch)
    rows = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(params.limit, 100, 200)}", *w.args)
    by = await fetch(f"SELECT l.action, count(*)::int AS n FROM mcp_activity_log l WHERE {w.sql()} GROUP BY 1 ORDER BY n DESC, 1 LIMIT 60", *w.args)
    return dumps({"timezone": tz, "member_id": target, "by_action": by, "events": localize(rows, tz, "occurred_at")})


@mcp.tool(name="who_touched", annotations={"title": "Who acted on an engagement or an invoice", **RO})
async def who_touched(params: TouchedIn) -> str:
    """Who acted on an engagement or an invoice and how many times each, with the first and last time — and the last thing done. Give engagement_id or invoice_id. The record's own rule applies."""
    w = Where().raw("l.action <> 'screen.view'")
    if params.engagement_id is not None:
        w.add("l.engagement_id = {}", params.engagement_id)
    elif params.invoice_id is not None:
        w.add("l.invoice_id = {}", params.invoice_id)
    else:
        return err("Give engagement_id or invoice_id.")
    try:
        f, t = day(params.from_, "from"), day(params.to, "to")
    except BadDate as ex:
        return err(str(ex))
    if f:
        w.add("l.occurred_at >= {}::date", f)
    if t:
        w.add("l.occurred_at < {}::date + 1", t)
    tz = await caller_tz(fetch)
    people = await fetch(f"""SELECT l.actor_member_id, l.actor_name, l.actor_kind, (l.actor_client_id IS NOT NULL) AS is_client, l.source, count(*)::int AS actions, min(l.occurred_at) AS first_at, max(l.occurred_at) AS last_at
                               FROM mcp_activity_log l WHERE {w.sql()} GROUP BY 1, 2, 3, 4, 5 ORDER BY last_at DESC LIMIT {lim(params.limit, 50, 200)}""", *w.args)
    last = await fetch(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT 1", *w.args)
    return dumps({"timezone": tz, "last": localize(last, tz, "occurred_at")[0] if last else None, "everyone": localize(people, tz, "first_at", "last_at")})


@mcp.tool(name="recent_activity", annotations={"title": "What changed", **RO})
async def recent_activity(params: RecentIn) -> str:
    """What changed since a time across everything you may see — grouped by engagement, newest first, with a count by action."""
    try:
        s = since_ts(params.since)
    except BadDate as ex:
        return err(str(ex))
    w = Where().add("l.occurred_at >= {}", s).raw("l.action <> 'screen.view'")
    if params.engagement_id is not None:
        w.add("l.engagement_id = {}", params.engagement_id)
    tz = await caller_tz(fetch)
    rows = await fetch(f"SELECT {COLS}, e.number AS engagement_number FROM mcp_activity_log l LEFT JOIN mcp_engagements e ON e.engagement_id = l.engagement_id WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(params.limit, 100, 200)}", *w.args)
    localize(rows, tz, "occurred_at")
    groups: dict = {}
    for r in rows:
        g = groups.setdefault(r["engagement_id"], {"engagement_id": r["engagement_id"], "engagement_number": r["engagement_number"], "events": []})
        g["events"].append(r)
    by = await fetch(f"SELECT l.action, count(*)::int AS n FROM mcp_activity_log l WHERE {w.sql()} GROUP BY 1 ORDER BY n DESC, 1 LIMIT 60", *w.args)
    return dumps({"timezone": tz, "since": s, "by_action": by, "by_engagement": list(groups.values())})


@mcp.tool(name="share_reads", annotations={"title": "What siblings read of ours", **RO})
async def share_reads(params: ShareReadsIn) -> str:
    """Which sibling application read what of ours through the kernel, and when — the share.read rows: the tool, the argument keys, the rows answered. Billing and the admin."""
    if not (await has_right(fetch, "billing.write") or await has_right(fetch, "settings.manage")):
        return err("This needs the billing.write or settings.manage right.")
    try:
        f, t = day(params.from_, "from"), day(params.to, "to")
    except BadDate as ex:
        return err(str(ex))
    w = Where().raw("l.action = 'share.read'")
    if f:
        w.add("l.occurred_at >= {}::date", f)
    if t:
        w.add("l.occurred_at < {}::date + 1", t)
    if params.app:
        w.add("(l.after->>'app' = {} OR l.after->>'provider' = {})", params.app, params.app)
    tz = await caller_tz(fetch)
    rows = await fetch(f"SELECT {COLS}, l.after->>'tool' AS tool, l.after->>'direction' AS direction FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(params.limit, 50, 200)}", *w.args)
    return dumps({"timezone": tz, "reads": localize(rows, tz, "occurred_at")})


@mcp.tool(name="activity_search", annotations={"title": "Activity search (SQL)", **RO})
async def activity_search(params: SearchIn) -> str:
    """The long tail: one read-only SELECT over mcp_activity_log (activity_id, occurred_at, actor_member_id, actor_name, actor_kind, actor_client_id, source, action, entity_type, entity_id, engagement_id, client_id, invoice_id,
    timesheet_id, department_id, before, after, agent_run_id, request_id) and the fact views mcp_members, mcp_departments, mcp_engagements, mcp_clients, mcp_invoices, mcp_timesheets. Already scoped to what you did and what you may see.
    One statement, 5 s timeout, 200 rows."""
    return await db.run_search(await _pool(), params.sql)


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_ACTIVITY_DB_USER", "MCP_ACTIVITY_DB_PASSWORD", port=int(db.ENV["MCP_ACTIVITY_PORT"]), endpoint_name="Activity MCP")
