"""The six shares[] of maludb-os.json (design §8, K7): invoices_issued, credit_notes_issued, expenses_by_category, unbilled_summary, reimbursements_approved, engagement_list — the kernel's own token reaches them (and app_roles),
and so does any admitted member their right allows. ONE truth, two doors: the tools do not rebuild the documents, they ask the application's internal door (POST /api/v1/shares/read.php on APP_INTERNAL_PORT,
signed with ACTIONS_RELAY_KEY) to call the very functions in app/features/exports/documents.php that the exports page downloads. The kernel's call logs `share.read` there (source application, the tool, the argument keys, the row count)."""
from __future__ import annotations

import hashlib
import hmac
import json
import time

import httpx
from mcp.server.fastmcp.exceptions import ToolError
from pydantic import ConfigDict, Field

import db
from ct_common import RO, BadDate, Where, _Base, day, dumps, err, lim, plain, q_alone

SHARES = ("invoices_issued", "credit_notes_issued", "expenses_by_category", "unbilled_summary", "reimbursements_approved", "engagement_list")


class _ShareIn(_Base):
    # The kernel adds fields of its own to a share's call (scope_id for a scoped one); an unscoped share ignores what it does not know.
    model_config = ConfigDict(str_strip_whitespace=True, extra="ignore", populate_by_name=True)


class InvoicesIn(_ShareIn):
    from_: str = Field(..., alias="from", description="First day, YYYY-MM-DD")
    to: str = Field(..., description="Last day, YYYY-MM-DD")
    client_id: int | None = None


class CreditNotesIn(_ShareIn):
    q: str | None = Field(None, description="A credit note's number or words. `q` alone answers a plain list (credit_note_id, label)")
    from_: str | None = Field(None, alias="from", description="First day, YYYY-MM-DD")
    to: str | None = Field(None, description="Last day, YYYY-MM-DD")
    client_id: int | None = None
    limit: int = Field(25, ge=1, le=100)


class ExpensesIn(_ShareIn):
    from_: str = Field(..., alias="from", description="First day, YYYY-MM-DD")
    to: str = Field(..., description="Last day, YYYY-MM-DD")


class UnbilledIn(_ShareIn):
    as_of: str | None = Field(None, description="YYYY-MM-DD; today by default")
    engagement_id: int | None = None
    client_id: int | None = None


class ReimbursementsIn(_ShareIn):
    as_of: str | None = Field(None, description="YYYY-MM-DD; today by default")
    member_id: int | None = None


class EngagementListIn(_ShareIn):
    status: str | None = Field(None, description="draft, active (the default), on_hold, closed, cancelled or all")
    client_id: int | None = None
    client: int | None = None


async def relay(tool: str, arguments: dict) -> str:
    """The document, exactly as the PHP function built it (a JSON string), or a ToolError in its sentence."""
    key = db.ENV.get("ACTIONS_RELAY_KEY", "")
    port = db.ENV.get("APP_INTERNAL_PORT", "")
    if len(key) < 32 or not port:
        raise ToolError("The shared tools are not configured (ACTIONS_RELAY_KEY and APP_INTERNAL_PORT).")
    kernel = db.request_is_kernel.get()
    member = db.request_member_id.get()
    if not kernel and member is None:
        raise ToolError("Who is asking? No member is behind this call.")
    body = json.dumps({"tool": tool, "caller": "kernel" if kernel else "person", "member_id": None if kernel else member,
                       "arguments": {k: v for k, v in arguments.items() if v is not None}}, separators=(",", ":")).encode()
    ts = str(int(time.time()))
    sig = hmac.new(key.encode(), f"share:{ts}:{hashlib.sha256(body).hexdigest()}".encode(), hashlib.sha256).hexdigest()
    try:
        async with httpx.AsyncClient(timeout=30) as client:
            r = await client.post(f"http://127.0.0.1:{port}/api/v1/shares/read.php", content=body,
                                  headers={"Content-Type": "application/json", "X-Share-Time": ts, "X-Share-Signature": sig})
    except httpx.HTTPError as ex:
        raise ToolError("The application's own door could not be reached; nothing was shared.") from ex
    if r.status_code == 200:
        return r.text
    try:
        message = str(r.json().get("error") or "")
    except ValueError:
        message = ""
    raise ToolError(message or f"The document could not be built ({r.status_code}).")


def register(mcp, fetch) -> None:
    @mcp.tool(name="invoices_issued", annotations={"title": "Invoices issued (share)", **RO})
    async def invoices_issued(params: InvoicesIn) -> str:
        """A shared tool (the ledger's source import): invoices issued — not draft, not void — in a period, their lines summarised by kind (time, expense, usage, milestone, fixed, adjustment), with client, engagement number, dates, terms,
        currency, subtotal, tax, total and external_ref; the document os.ct-invoices/1. The Business OS kernel's token over an approved connection, or Billing and Finance (billing.write or reports.view)."""
        return await relay("invoices_issued", {"from": params.from_, "to": params.to, "client_id": params.client_id})

    @mcp.tool(name="credit_notes_issued", annotations={"title": "Credit notes issued (share)", **RO})
    async def credit_notes_issued(params: CreditNotesIn) -> str:
        """A shared tool: credit notes issued in a period, the same shape as invoices (amounts positive — the reader negates), the document os.ct-credit-notes/1. The kernel's token, or Billing (billing.write).
        `q` alone resolves a credit note by number or words: a plain list (credit_note_id, label)."""
        if q_alone(params):
            w = Where()
            for x in params.q.split()[:6]:
                n = w.p("%" + x.replace("%", "").replace("_", "\\_") + "%")
                w.raw(f"(n.number ILIKE {n} OR n.client_name ILIKE {n} OR COALESCE(n.invoice_number, '') ILIKE {n})")
            rows = await fetch(f"""SELECT n.credit_note_id, n.number || ' — ' || n.client_name || COALESCE(', against ' || n.invoice_number, '') AS label FROM mcp_credit_notes n
                                    WHERE {w.sql()} ORDER BY n.created_at DESC LIMIT {lim(params.limit)}""", *w.args)
            return plain(rows, "credit_note_id", "label")
        if not params.from_ or not params.to:
            return err("Give both from and to, as days (2026-03-01).")
        return await relay("credit_notes_issued", {"from": params.from_, "to": params.to, "client_id": params.client_id})

    @mcp.tool(name="expenses_by_category", annotations={"title": "Company-card expenses (share)", **RO})
    async def expenses_by_category(params: ExpensesIn) -> str:
        """A shared tool (the ledger's import): approved company-card expenses by month, category and engagement, with tax and the billable flag — no person named; the document os.ct-expenses/1.
        The kernel's token, or billing.write or reports.view."""
        return await relay("expenses_by_category", {"from": params.from_, "to": params.to})

    @mcp.tool(name="unbilled_summary", annotations={"title": "Unbilled work (share)", **RO})
    async def unbilled_summary(params: UnbilledIn) -> str:
        """A shared tool (the work-in-progress accrual): what is approved and unbilled at bill value per engagement as of a date — time, expenses, usage, milestones, the total — or for one engagement or one client; the document os.ct-wip/1.
        The kernel's token, or invoices.draft, reports.view or billing.write (a Consultant sees no bill value)."""
        return await relay("unbilled_summary", {"as_of": params.as_of, "engagement_id": params.engagement_id, "client_id": params.client_id})

    @mcp.tool(name="reimbursements_approved", annotations={"title": "Reimbursements owed (share, people)", **RO})
    async def reimbursements_approved(params: ReimbursementsIn) -> str:
        """A shared tool about PEOPLE (what HR pays in a pay run): approved personal-card expenses not yet reimbursed, per kernel member id, with expense number, date, amount and description; the document os.ct-reimbursements/1.
        The kernel's token over a people connection a super-admin approved, or billing.write."""
        return await relay("reimbursements_approved", {"as_of": params.as_of, "member_id": params.member_id})

    @mcp.tool(name="engagement_list", annotations={"title": "Active engagements (share)", **RO})
    async def engagement_list(params: EngagementListIn) -> str:
        """A shared tool (Projects, Help Desk): the engagements — active unless told — with number, name, the client's short name, kind, status, dates and the manager's name; no money; the document os.ct-engagements/1.
        The kernel's token, or any member for the engagements they may see."""
        return await relay("engagement_list", {"status": params.status, "client_id": params.client_id if params.client_id is not None else params.client})
