"""People, the vocabulary and rates (tool surface: find_members, find_departments, ct_settings, rate_cards, resolve_rate). Reads mcp_members, mcp_departments, the vocabulary views, mcp_rate_cards,
mcp_rate_card_rates and ct_resolve_rate() (guarded by ct_can_see_engagement and rates.view — the function itself reads base tables)."""
from __future__ import annotations

from pydantic import Field

from ct_common import RO, BadDate, Where, _Base, day, dumps, err, has_right, lim, match_words, need, plain, q_alone, today


class MembersIn(_Base):
    q: str | None = Field(None, description="Words of a name. `q` alone answers a plain list (member_id, display_name)")
    kind: str | None = Field(None, description="human or agent")
    department_id: int | None = Field(None, description="A practice the person belongs to")
    limit: int = Field(25, ge=1, le=100)


class DepartmentsIn(_Base):
    q: str | None = Field(None, description="Words of a practice's name. `q` alone answers a plain list (department_id, name)")
    limit: int = Field(25, ge=1, le=100)


class SettingsIn(_Base):
    q: str | None = Field(None, description="Resolves a consulting role, an activity type, an expense category or a usage provider by name; `q` alone answers a plain list carrying each row's own id field")
    part: str | None = Field(None, description="settings, roles, activities, categories, providers or tax — one part only")


class RateCardsIn(_Base):
    q: str | None = Field(None, description="Words of a card's name. `q` alone answers a plain list (rate_card_id, name)")
    rate_card_id: int | None = Field(None, description="One card in full, with its rate per consulting role")
    limit: int = Field(25, ge=1, le=100)


class ResolveRateIn(_Base):
    engagement_id: int
    member_id: int
    on_date: str | None = Field(None, description="YYYY-MM-DD; today by default")


PARTS = ("settings", "roles", "activities", "categories", "providers", "tax")


def register(mcp, fetch) -> None:
    @mcp.tool(name="find_members", annotations={"title": "Find a person or an agent", **RO})
    async def find_members(params: MembersIn) -> str:
        """A person or an AI agent by name: member_id, display_name, kind (human or agent), roles, job title, practice (primary and all), timezone — no email but your own.
        Call before engagement_member_save or engagement_save (manager). `q` alone answers a plain list (member_id, display_name)."""
        w = Where()
        match_words(w, params.q, "m.display_name")
        if params.kind in ("human", "agent"):
            w.add("m.member_kind = {}", params.kind)
        if params.department_id is not None:
            w.add("{} = ANY (COALESCE(m.department_ids, '{{}}'))", params.department_id)
        rows = await fetch(f"""SELECT m.member_id, m.display_name, m.member_kind, m.business_role, m.is_external, m.roles, m.job_title, m.timezone, m.email, m.department_ids, m.primary_department_id
                                 FROM mcp_members m WHERE {w.sql()} ORDER BY lower(m.display_name), m.member_id LIMIT {lim(params.limit)}""", *w.args)
        if q_alone(params):
            return plain(rows, "member_id", "display_name")
        return dumps({"members": rows})

    @mcp.tool(name="find_departments", annotations={"title": "Find a practice", **RO})
    async def find_departments(params: DepartmentsIn) -> str:
        """The practices (the kernel's departments) by name, with their manager. `q` alone answers a plain list (department_id, name)."""
        w = Where().raw("d.archived_at IS NULL")
        match_words(w, params.q, "d.name")
        rows = await fetch(f"""SELECT d.department_id, d.name, d.description, d.parent_id, d.manager_member_id,
                                      (SELECT m.display_name FROM mcp_members m WHERE m.member_id = d.manager_member_id) AS manager_name
                                 FROM mcp_departments d WHERE {w.sql()} ORDER BY lower(d.name), d.department_id LIMIT {lim(params.limit)}""", *w.args)
        if q_alone(params):
            return plain(rows, "department_id", "name")
        return dumps({"departments": rows})

    @mcp.tool(name="ct_settings", annotations={"title": "The settings and the vocabulary", **RO})
    async def ct_settings(params: SettingsIn) -> str:
        """Every legal value at once: the settings (week start, hours increment, receipt threshold, default markup, terms, grouping, thresholds, default capacity), the consulting roles, the activity types
        (with their billable default), the expense categories (with receipt and markup overrides), the usage providers and the tax rates. Call before setting any field. With `part` only that part;
        with `q` alone a flat list of the roles, activity types, categories and providers matching the words, each row carrying its OWN id field (consulting_role_id, activity_type_id, category_id, provider_id) and
        its label (title, label, label, name) — what the kernel resolves a name with. The numbering prefixes are not readable here (no view carries them)."""
        if params.part is not None and params.part not in PARTS:
            return err("part is one of " + ", ".join(PARTS) + ".")
        want = (lambda p: params.part in (None, p))

        def w_for(*cols):
            w = Where().raw("x.archived_at IS NULL")
            return match_words(w, params.q, *cols)

        out: dict = {}
        if want("roles"):
            w = w_for("x.title", "x.key")
            out["roles"] = await fetch(f"SELECT x.consulting_role_id, x.key, x.title, x.description, x.sort_order FROM mcp_consulting_roles x WHERE {w.sql()} ORDER BY x.sort_order, x.consulting_role_id", *w.args)
        if want("activities"):
            w = w_for("x.label", "x.key")
            out["activities"] = await fetch(f"SELECT x.activity_type_id, x.key, x.label, x.billable_default, x.sort_order FROM mcp_activity_types x WHERE {w.sql()} ORDER BY x.sort_order, x.activity_type_id", *w.args)
        if want("categories"):
            w = w_for("x.label", "x.key")
            out["categories"] = await fetch(f"SELECT x.category_id, x.key, x.label, x.receipt_required_above, x.default_markup_pct, x.billable_default, x.sort_order FROM mcp_expense_categories x WHERE {w.sql()} ORDER BY x.sort_order, x.category_id", *w.args)
        if want("providers"):
            w = w_for("x.name", "x.provider_key")
            out["providers"] = await fetch(f"SELECT x.provider_id, x.provider_key, x.name, x.sort_order FROM mcp_usage_providers x WHERE {w.sql()} ORDER BY x.sort_order, x.provider_id", *w.args)
        if params.q is None and want("tax"):
            out["tax_rates"] = await fetch("SELECT x.tax_rate_id, x.name, x.rate, x.is_default FROM mcp_tax_rates x WHERE x.archived_at IS NULL ORDER BY x.name")
        if params.q is None and want("settings"):
            rows = await fetch("SELECT * FROM mcp_settings")
            out["settings"] = rows[0] if rows else None
        if params.q is not None and params.part is None:
            # the resolver's call: one flat list, every row carrying its own id and label field
            flat = [{**r, "part": "roles"} for r in out.get("roles", [])] + [{**r, "part": "activities"} for r in out.get("activities", [])] \
                + [{**r, "part": "categories"} for r in out.get("categories", [])] + [{**r, "part": "providers"} for r in out.get("providers", [])]
            return dumps([{k: r[k] for k in ("consulting_role_id", "activity_type_id", "category_id", "provider_id", "title", "label", "name", "key", "part") if k in r} for r in flat])
        return dumps(out)

    @mcp.tool(name="rate_cards", annotations={"title": "Rate cards", **RO})
    async def rate_cards(params: RateCardsIn) -> str:
        """The rate cards and the bill rate per consulting role on each; one card in full by rate_card_id. Call before engagement_save (rate_card). Needs rates.view. `q` alone answers a plain list (rate_card_id, name)."""
        if not await has_right(fetch, "rates.view"):
            return need("rates.view", "Rate cards")
        w = Where()
        if params.rate_card_id is not None:
            w.add("c.rate_card_id = {}", params.rate_card_id)
        else:
            w.raw("c.archived_at IS NULL")
        match_words(w, params.q, "c.name")
        rows = await fetch(f"""SELECT c.rate_card_id, c.name, c.currency, c.effective_from, c.description, c.archived_at, c.rate_count FROM mcp_rate_cards c WHERE {w.sql()} ORDER BY lower(c.name), c.rate_card_id LIMIT {lim(params.limit)}""", *w.args)
        if q_alone(params):
            return plain(rows, "rate_card_id", "name")
        if params.rate_card_id is not None:
            if not rows:
                return err("No such rate card.")
            rates = await fetch("SELECT r.consulting_role_id, r.role_title, r.bill_rate FROM mcp_rate_card_rates r WHERE r.rate_card_id = $1 ORDER BY r.role_title", params.rate_card_id)
            return dumps({"rate_card": {**rows[0], "rates": rates}})
        return dumps({"rate_cards": rows})

    @mcp.tool(name="resolve_rate", annotations={"title": "Which rate applies", **RO})
    async def resolve_rate(params: ResolveRateIn) -> str:
        """What bill rate applies to a person on an engagement on a date, and why: bill_rate, rate_source (member_override, engagement_role, rate_card or none) and the consulting role. Needs rates.view and an engagement you may see."""
        if not await has_right(fetch, "rates.view"):
            return need("rates.view", "A bill rate")
        try:
            on = day(params.on_date, "on_date") or await today(fetch)
        except BadDate as ex:
            return err(str(ex))
        if not (await fetch("SELECT ct_can_see_engagement($1) AS ok", params.engagement_id))[0]["ok"]:
            return err("No such engagement among those you may see.")
        r = (await fetch("SELECT bill_rate, rate_source, consulting_role_id FROM ct_resolve_rate($1, $2, $3)", params.engagement_id, params.member_id, on))
        r = r[0] if r else {"bill_rate": None, "rate_source": "none", "consulting_role_id": None}
        role = None
        if r["consulting_role_id"] is not None:
            rr = await fetch("SELECT title FROM mcp_consulting_roles WHERE consulting_role_id = $1", r["consulting_role_id"])
            role = rr[0]["title"] if rr else None
        who = await fetch("SELECT display_name FROM mcp_members WHERE member_id = $1", params.member_id)
        return dumps({"engagement_id": params.engagement_id, "member_id": params.member_id, "member_name": who[0]["display_name"] if who else None, "on_date": on, **r, "role_title": role})
