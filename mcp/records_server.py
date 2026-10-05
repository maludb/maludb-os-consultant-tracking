"""consultant_tracking_records_mcp — Consultant Tracking's record-memory server (PostgreSQL, the mcp_* views and the ct_* report functions, read-only).

Tool surface: docs/consultant-tracking-mcp-tool-surface.md (spec: docs/build-specs/mcp-servers.md). Every tool reads as the asking member, so a person sees what the screens show them: their own time and expenses, the
engagements they are staffed on or manage, a bill rate only with rates.view, a cost only with cost.view (a Manager's on their own engagements), an invoice only with invoices.draft or as Billing, Finance or the admin — the views and
the report functions decide, the server only trims. An agent sees only the tools the kernel granted it (server_common). The kernel's own token reaches app_roles and the six shared tools.
"""
from __future__ import annotations

from mcp.server.fastmcp import FastMCP
from pydantic import Field

import db
from ct_common import RO, _Base, dumps

mcp = FastMCP("consultant_tracking_records_mcp")


async def _pool():
    return await db.get_pool(db.ENV["MCP_RECORDS_DB_USER"], db.ENV["MCP_RECORDS_DB_PASSWORD"])


async def fetch(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


class SearchIn(_Base):
    sql: str = Field(..., description="A single read-only SELECT over the mcp_* views (see below).")


@mcp.tool(name="records_search", annotations={"title": "Records search (SQL)", **RO})
async def records_search(params: SearchIn) -> str:
    """The long tail: one read-only SELECT over Consultant Tracking's views — people and vocabulary (mcp_members, mcp_departments, mcp_settings, mcp_consulting_roles, mcp_activity_types, mcp_expense_categories,
    mcp_usage_providers, mcp_tax_rates), rates (mcp_rate_cards, mcp_rate_card_rates, mcp_member_cost_rates, mcp_member_capacity), clients and engagements (mcp_clients, mcp_client_contacts, mcp_engagements,
    mcp_engagement_members, mcp_engagement_role_rates, mcp_milestones, mcp_budget_alerts), time (mcp_timesheets, mcp_time_entries, mcp_time_timers), expenses (mcp_expenses), usage (mcp_usage_charges, mcp_usage_imports,
    mcp_allocation_rules, mcp_recurring_charges, mcp_ai_statements, mcp_ai_statement_lines, mcp_ai_statement_allocations), billing (mcp_invoices, mcp_invoice_lines, mcp_invoice_line_items, mcp_credit_notes,
    mcp_credit_note_lines, mcp_credit_applications, mcp_invoice_payments, mcp_payment_claims), mcp_attachments, mcp_notes, mcp_notifications, mcp_agent_dispatches, mcp_my_tokens. Already scoped to what you may see:
    a bill rate is null without rates.view, a cost without cost.view. Times are UTC (timestamptz). One statement, 5 s timeout, 200 rows."""
    return await db.run_search(await _pool(), params.sql)


@mcp.tool(name="app_roles", annotations={"title": "Consultant Tracking's roles and rights", **RO})
async def app_roles() -> str:
    """The roles Consultant Tracking offers and the rights each gives — what the Business OS kernel reads to let a super-admin grant them (schema os.app-roles/1, maludb-os-integration 0.4.x). The catalogue is not about
    anyone, so the kernel's own token may read it; so may any person or agent."""
    pool = await _pool()
    async with pool.acquire() as con:
        rights = await con.fetch("SELECT right_key, description FROM ct_rights ORDER BY sort_order, right_key")
        roles = await con.fetch("SELECT role_key, name, description, capability, is_admin, sort_order, rights FROM mcp_app_roles ORDER BY sort_order")
    return dumps({
        "schema": "os.app-roles/1",
        "rights": [{"key": r["right_key"], "description": r["description"]} for r in rights],
        "roles": [{"key": r["role_key"], "name": r["name"], "description": r["description"], "capability": r["capability"], "is_admin": r["is_admin"], "rights": list(r["rights"])} for r in roles],
    })


import ct_billing  # noqa: E402
import ct_engagements  # noqa: E402
import ct_expenses  # noqa: E402
import ct_people  # noqa: E402
import ct_shares  # noqa: E402
import ct_time  # noqa: E402
import ct_usage  # noqa: E402

ct_people.register(mcp, fetch)
ct_engagements.register(mcp, fetch)
ct_time.register(mcp, fetch)
ct_expenses.register(mcp, fetch)
ct_usage.register(mcp, fetch)
ct_billing.register(mcp, fetch)
ct_shares.register(mcp, fetch)


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_RECORDS_DB_USER", "MCP_RECORDS_DB_PASSWORD", port=int(db.ENV["MCP_RECORDS_PORT"]), endpoint_name="Records MCP")
