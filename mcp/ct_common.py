"""Shared pieces for Consultant Tracking's tool modules: annotations, the input base, the where-builder, dates, rights, the resolver's plain list.
Every query runs as the asking member (db.fetch_scoped sets app.member_id from the verified bearer), so the mcp_* views and the ct_* report functions decide the
rows; nothing here filters by who the caller is, and a column a view blanked is passed through as the null it is — never filled."""
from __future__ import annotations

import datetime
import re
import zoneinfo

from pydantic import BaseModel, ConfigDict

import db

RO = {"readOnlyHint": True, "openWorldHint": False}
OPEN_INVOICE = ("issued", "sent", "viewed", "partially_paid")


class _Base(BaseModel):
    model_config = ConfigDict(str_strip_whitespace=True, extra="forbid", populate_by_name=True)


def dumps(obj) -> str:
    return db.to_json(obj)


def err(message: str, **extra) -> str:
    return dumps({"error": message, **extra})


def lim(n: int | None, default: int = 25, top: int = 100) -> int:
    return max(1, min(int(n or default), top))


class Where:
    """Builds `a = $1 AND b = ANY($2)` with positional parameters in order."""

    def __init__(self) -> None:
        self.parts: list[str] = []
        self.args: list = []

    def p(self, value) -> str:
        self.args.append(value)
        return f"${len(self.args)}"

    def add(self, sql: str, *values) -> "Where":
        """`sql` uses {} for each value in order, e.g. add('e.client_id = {}', 4)."""
        self.parts.append(sql.format(*[self.p(v) for v in values]))
        return self

    def raw(self, sql: str) -> "Where":
        self.parts.append(sql)
        return self

    def sql(self) -> str:
        return " AND ".join(self.parts) if self.parts else "TRUE"


def words(q: str | None) -> list[str]:
    return [w for w in re.split(r"\s+", (q or "").strip()) if w][:6]


def word_start(w: str) -> str:
    """A regex that matches a word beginning with w (names match at the start of a word)."""
    return "\\m" + re.escape(w[:40])


def match_words(w: Where, q: str | None, *columns: str) -> Where:
    """Every word of q must start a word in one of the columns."""
    for x in words(q):
        n = w.p(word_start(x))
        w.raw("(" + " OR ".join(f"{c} ~* {n}" for c in columns) + ")")
    return w


def q_alone(p) -> bool:
    """True when only `q` (and perhaps `limit`) was given — the kernel's entity resolver's call, answered as a plain list of {id, label} rows."""
    return bool(getattr(p, "q", None)) and set(p.model_fields_set) <= {"q", "limit"}


def plain(rows: list[dict], id_field: str, label_field: str) -> str:
    return dumps([{id_field: r[id_field], label_field: r[label_field]} for r in rows])


class BadDate(ValueError):
    pass


def day(s: str | datetime.date | None, what: str = "The date") -> datetime.date | None:
    """A YYYY-MM-DD string as a date (None stays None); anything else is a sentence."""
    if s is None or s == "":
        return None
    if isinstance(s, datetime.date):
        return s
    if not re.fullmatch(r"\d{4}-\d{2}-\d{2}", str(s)):
        raise BadDate(f"{what} is a day (2026-03-02).")
    try:
        return datetime.date.fromisoformat(str(s))
    except ValueError as ex:
        raise BadDate(f"{what} is a day (2026-03-02).") from ex


def period(frm: str | None, to: str | None, max_days: int = 800) -> tuple[datetime.date, datetime.date]:
    f, t = day(frm, "The first day"), day(to, "The last day")
    if f is None or t is None:
        raise BadDate("Give both from and to, as days (2026-03-01).")
    if t < f:
        raise BadDate("The period's start is after its end.")
    if (t - f).days > max_days:
        raise BadDate(f"A period is at most {max_days} days.")
    return f, t


async def has_right(fetch, right: str) -> bool:
    rows = await fetch("SELECT ct_has_right($1) AS ok", right)
    return bool(rows and rows[0]["ok"])


async def sees_all(fetch) -> bool:
    rows = await fetch("SELECT ct_sees_all() AS ok")
    return bool(rows and rows[0]["ok"])


async def receivables_ok(fetch) -> bool:
    """What clients owe, firm-wide: Billing, or a reader of every engagement (db/017) — the screens' derived right receivables.view."""
    rows = await fetch("SELECT (ct_has_right('billing.write') OR (ct_has_right('reports.view') AND ct_sees_all())) AS ok")
    return bool(rows and rows[0]["ok"])


async def me(fetch) -> int | None:
    rows = await fetch("SELECT nullif(current_setting('app.member_id', true), '')::bigint AS m")
    return rows[0]["m"] if rows else None


async def today(fetch) -> datetime.date:
    """Today in the firm's zone (mcp_settings.timezone), as the screens read it."""
    rows = await fetch("SELECT timezone FROM mcp_settings")
    tz = (rows[0]["timezone"] if rows else None) or "UTC"
    try:
        return datetime.datetime.now(zoneinfo.ZoneInfo(tz)).date()
    except Exception:  # noqa: BLE001
        return datetime.datetime.now(datetime.timezone.utc).date()


async def caller_tz(fetch) -> str:
    rows = await fetch("SELECT timezone FROM mcp_members WHERE member_id = (SELECT nullif(current_setting('app.member_id', true), '')::bigint)")
    tz = (rows[0]["timezone"] if rows else None) or "UTC"
    try:
        zoneinfo.ZoneInfo(tz)
    except Exception:  # noqa: BLE001
        return "UTC"
    return tz


def localize(rows: list[dict], tz: str, *fields: str) -> list[dict]:
    """Add `<field>_local` ('YYYY-MM-DDTHH:MM' in the caller's zone) beside each UTC timestamp."""
    z = zoneinfo.ZoneInfo(tz)
    for r in rows:
        for f in fields:
            v = r.get(f)
            if isinstance(v, datetime.datetime):
                r[f + "_local"] = v.astimezone(z).strftime("%Y-%m-%dT%H:%M")
    return rows


def esc_like(s: str) -> str:
    return "%" + s.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_") + "%"


NO_RIGHT = "You do not hold the right for that ({}); the views would show you nothing."


def need(right: str, what: str) -> str:
    return err(f"{what} needs the {right} right.")
