-- 009: TIME (design §6, D6) — the exemplar slice's tables. A TIMESHEET is one person's week (Monday, the settings'
-- week start); its ENTRIES are hours on an engagement on a day, in the settings' increment, with a client-visible note and
-- an internal one. The week is submitted by its person and approved or rejected by a manager; approval SNAPSHOTS the bill
-- rate (ct_resolve_rate, D6) and the cost rate onto each entry; an approved entry is LOCKED — a correction is a rejection
-- before invoicing, a write-off or a credit note after. Nobody approves their own week but a super-admin. "Billed" is not a
-- status here: an entry is billed when an invoice line item names it (db/012, UNIQUE) — one truth, never two.
--
-- Status moves happen ONLY through the functions below: the guard refuses a direct UPDATE of status (the functions set
-- ct.via_function for their transaction). PHP calls the functions; the database is the referee.
BEGIN;

CREATE TABLE timesheets (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id         bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    week_start        date NOT NULL,
    status            text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'submitted', 'approved', 'rejected')),
    total_hours       numeric(7,2) NOT NULL DEFAULT 0,
    billable_hours    numeric(7,2) NOT NULL DEFAULT 0,
    submitted_at      timestamptz,
    approved_at       timestamptz,                                               -- when the LAST entry was approved
    approved_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    rejected_at       timestamptz,
    rejected_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    rejection_reason  text,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now(),
    UNIQUE (member_id, week_start)
);
CREATE INDEX timesheets_status_idx ON timesheets (status, week_start DESC);
CREATE INDEX timesheets_week_idx ON timesheets (week_start DESC);
CREATE INDEX timesheets_approved_by_idx ON timesheets (approved_by);
CREATE INDEX timesheets_rejected_by_idx ON timesheets (rejected_by);
CREATE TRIGGER timesheets_touch BEFORE UPDATE ON timesheets FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE time_entries (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    timesheet_id       bigint NOT NULL REFERENCES timesheets(id) ON DELETE CASCADE,
    member_id          bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    engagement_id      bigint NOT NULL REFERENCES engagements(id) ON DELETE RESTRICT,
    entry_date         date NOT NULL,
    hours              numeric(6,2) NOT NULL CHECK (hours > 0 AND hours <= 24),
    activity_type_id   bigint REFERENCES activity_types(id) ON DELETE SET NULL,
    billable           boolean NOT NULL DEFAULT true,
    note               text,                                                     -- client-visible: goes on the invoice's backing detail
    internal_note      text,                                                     -- never leaves
    status             text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'submitted', 'approved', 'rejected', 'written_off')),
    bill_rate          numeric(12,2) CHECK (bill_rate >= 0),                     -- snapshot at approval; NULL = no rate (billable with no amount)
    rate_source        text CHECK (rate_source IN ('member_override', 'engagement_role', 'rate_card', 'none')),
    consulting_role_id bigint REFERENCES consulting_roles(id) ON DELETE SET NULL, -- the role the rate was priced by
    cost_rate          numeric(12,2) CHECK (cost_rate >= 0),                     -- snapshot at approval; cost.view only
    currency           char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),         -- the engagement's
    department_id      bigint REFERENCES departments(id) ON DELETE SET NULL,     -- the person's practice when logged
    approved_at        timestamptz,
    approved_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    written_off_at     timestamptz,
    written_off_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    written_off_reason text,
    timer_started_at   timestamptz,                                              -- when the entry came from a timer
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX time_entries_timesheet_idx ON time_entries (timesheet_id);
CREATE INDEX time_entries_member_date_idx ON time_entries (member_id, entry_date DESC);
CREATE INDEX time_entries_engagement_idx ON time_entries (engagement_id, entry_date DESC);
CREATE INDEX time_entries_status_idx ON time_entries (status);
CREATE INDEX time_entries_activity_idx ON time_entries (activity_type_id);
CREATE INDEX time_entries_role_idx ON time_entries (consulting_role_id);
CREATE INDEX time_entries_dept_idx ON time_entries (department_id);
CREATE INDEX time_entries_approved_by_idx ON time_entries (approved_by);
CREATE INDEX time_entries_written_off_by_idx ON time_entries (written_off_by);
CREATE TRIGGER time_entries_touch BEFORE UPDATE ON time_entries FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- One running timer per person.
CREATE TABLE time_timers (
    member_id          bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    engagement_id      bigint NOT NULL REFERENCES engagements(id) ON DELETE CASCADE,
    activity_type_id   bigint REFERENCES activity_types(id) ON DELETE SET NULL,
    note               text,
    started_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX time_timers_engagement_idx ON time_timers (engagement_id);
CREATE INDEX time_timers_activity_idx ON time_timers (activity_type_id);

-- ---------------------------------------------------------------------------------------------
-- Helpers
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_via_function() RETURNS boolean
    LANGUAGE sql STABLE AS $$ SELECT COALESCE(current_setting('ct.via_function', true), '') = '1' $$;

-- The week row for a person and a date, made open when missing.
CREATE OR REPLACE FUNCTION ct_timesheet_for(p_member_id bigint, p_date date) RETURNS timesheets
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t timesheets%ROWTYPE; ws date;
BEGIN
    ws := ct_week_start(p_date);
    SELECT * INTO t FROM timesheets WHERE member_id = p_member_id AND week_start = ws;
    IF t.id IS NULL THEN
        INSERT INTO timesheets (member_id, week_start) VALUES (p_member_id, ws) ON CONFLICT (member_id, week_start) DO NOTHING;
        SELECT * INTO t FROM timesheets WHERE member_id = p_member_id AND week_start = ws;
    END IF;
    RETURN t;
END$$;

CREATE OR REPLACE FUNCTION ct_timesheet_totals(p_timesheet_id bigint) RETURNS void
    LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
    UPDATE timesheets t SET total_hours = COALESCE((SELECT sum(hours) FROM time_entries e WHERE e.timesheet_id = t.id), 0),
                            billable_hours = COALESCE((SELECT sum(hours) FROM time_entries e WHERE e.timesheet_id = t.id AND e.billable), 0)
     WHERE t.id = p_timesheet_id;
$$;

-- ---------------------------------------------------------------------------------------------
-- The entry's rules: the week it falls in; the increment and the day's cap; staffed on an active engagement; locked once
-- approved; the status only through the functions.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_time_entry_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE s ct_settings%ROWTYPE; t timesheets%ROWTYPE; e engagements%ROWTYPE; day_total numeric;
BEGIN
    SELECT * INTO s FROM ct_settings WHERE id = 1;
    IF TG_OP = 'INSERT' THEN
        IF NEW.status NOT IN ('draft') THEN RAISE EXCEPTION 'A time entry is born a draft'; END IF;
        t := ct_timesheet_for(NEW.member_id, NEW.entry_date);
        IF t.status NOT IN ('open', 'rejected') THEN RAISE EXCEPTION 'The week of % is % — reopen it before adding time', NEW.entry_date, t.status; END IF;
        NEW.timesheet_id := t.id;
        IF t.status = 'rejected' THEN NEW.status := 'rejected'; END IF;
        NEW.department_id := COALESCE(NEW.department_id, ct_primary_department(NEW.member_id));
        NEW.bill_rate := NULL; NEW.rate_source := NULL; NEW.cost_rate := NULL; NEW.approved_at := NULL; NEW.approved_by := NULL;
    ELSE
        IF NEW.status <> OLD.status AND NOT ct_via_function() THEN
            RAISE EXCEPTION 'A time entry''s status changes through submit, approve, reject, reopen or write-off — never by hand';
        END IF;
        IF OLD.status IN ('approved', 'written_off') AND NOT ct_via_function() THEN
            RAISE EXCEPTION 'This time entry is % and locked; a correction is a credit note or a write-off', OLD.status;
        END IF;
        IF OLD.status = 'submitted' AND NOT ct_via_function() THEN
            RAISE EXCEPTION 'The week is submitted; reopen it to change an entry';
        END IF;
        IF NEW.timesheet_id <> OLD.timesheet_id OR NEW.member_id <> OLD.member_id THEN RAISE EXCEPTION 'An entry stays in its week and with its person'; END IF;
        IF ct_week_start(NEW.entry_date) <> (SELECT week_start FROM timesheets WHERE id = NEW.timesheet_id) THEN
            RAISE EXCEPTION 'An entry moves within its week only (delete it and log it in the other week)';
        END IF;
        IF ct_via_function() THEN RETURN NEW; END IF;      -- the functions write snapshots and statuses with their own checks
    END IF;
    SELECT * INTO e FROM engagements WHERE id = NEW.engagement_id;
    IF e.id IS NULL THEN RAISE EXCEPTION 'No such engagement'; END IF;
    IF NOT ct_can_log_on(NEW.engagement_id, NEW.member_id, NEW.entry_date) THEN
        RAISE EXCEPTION 'Not staffed on % on % (or the engagement is not active)', e.number, NEW.entry_date;
    END IF;
    NEW.currency := e.currency;
    IF round(NEW.hours / s.hours_increment) * s.hours_increment <> NEW.hours THEN
        RAISE EXCEPTION 'Hours are logged in steps of % (not %)', s.hours_increment, NEW.hours;
    END IF;
    SELECT COALESCE(sum(hours), 0) INTO day_total FROM time_entries WHERE member_id = NEW.member_id AND entry_date = NEW.entry_date AND status <> 'rejected'
       AND (TG_OP = 'INSERT' OR id <> OLD.id);
    IF day_total + NEW.hours > s.max_hours_per_day THEN
        RAISE EXCEPTION 'That would be % hours on %; the day''s cap is %', day_total + NEW.hours, NEW.entry_date, s.max_hours_per_day;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER time_entries_guard BEFORE INSERT OR UPDATE ON time_entries FOR EACH ROW EXECUTE FUNCTION ct_time_entry_guard();

CREATE OR REPLACE FUNCTION ct_time_entry_no_delete() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status NOT IN ('draft', 'rejected') THEN RAISE EXCEPTION 'A % time entry is never deleted', OLD.status; END IF;
    RETURN OLD;
END$$;
CREATE TRIGGER time_entries_no_delete BEFORE DELETE ON time_entries FOR EACH ROW EXECUTE FUNCTION ct_time_entry_no_delete();

CREATE OR REPLACE FUNCTION ct_time_entry_after() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM ct_timesheet_totals(COALESCE(NEW.timesheet_id, OLD.timesheet_id));
    RETURN NULL;
END$$;
CREATE TRIGGER time_entries_after AFTER INSERT OR UPDATE OR DELETE ON time_entries FOR EACH ROW EXECUTE FUNCTION ct_time_entry_after();

-- A week's own head: the status through the functions; the person and the week fixed.
CREATE OR REPLACE FUNCTION ct_timesheet_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.week_start <> ct_week_start(NEW.week_start) THEN RAISE EXCEPTION '% is not the start of a week', NEW.week_start; END IF;
        IF NEW.status <> 'open' THEN RAISE EXCEPTION 'A week is born open'; END IF;
        RETURN NEW;
    END IF;
    IF NEW.member_id <> OLD.member_id OR NEW.week_start <> OLD.week_start THEN RAISE EXCEPTION 'A week keeps its person and its dates'; END IF;
    IF NEW.status <> OLD.status AND NOT ct_via_function() THEN
        RAISE EXCEPTION 'A week''s status changes through submit, approve, reject or reopen — never by hand';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER timesheets_guard BEFORE INSERT OR UPDATE ON timesheets FOR EACH ROW EXECUTE FUNCTION ct_timesheet_guard();

-- ---------------------------------------------------------------------------------------------
-- The verbs. Each runs as the writer with the acting member in app.member_id; p_by names the actor for the record.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_timesheet_submit(p_timesheet_id bigint, p_by bigint) RETURNS timesheets
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t timesheets%ROWTYPE;
BEGIN
    SELECT * INTO t FROM timesheets WHERE id = p_timesheet_id FOR UPDATE;
    IF t.id IS NULL THEN RAISE EXCEPTION 'No such week'; END IF;
    IF t.member_id <> p_by AND NOT (SELECT ct_has_right('time.approve')) THEN RAISE EXCEPTION 'Only the person submits their own week'; END IF;
    IF t.status NOT IN ('open', 'rejected') THEN RAISE EXCEPTION 'The week of % is already %', t.week_start, t.status; END IF;
    IF t.week_start > current_date THEN RAISE EXCEPTION 'The week of % has not started', t.week_start; END IF;
    PERFORM set_config('ct.via_function', '1', true);
    UPDATE time_entries SET status = 'submitted' WHERE timesheet_id = t.id AND status IN ('draft', 'rejected');
    UPDATE timesheets SET status = 'submitted', submitted_at = now(), rejected_at = NULL, rejected_by = NULL, rejection_reason = NULL
     WHERE id = t.id RETURNING * INTO t;
    PERFORM set_config('ct.via_function', '', true);
    RETURN t;
END$$;

-- Reopen a submitted week (its person, or a manager) before anyone approved anything in it.
CREATE OR REPLACE FUNCTION ct_timesheet_reopen(p_timesheet_id bigint, p_by bigint) RETURNS timesheets
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t timesheets%ROWTYPE;
BEGIN
    SELECT * INTO t FROM timesheets WHERE id = p_timesheet_id FOR UPDATE;
    IF t.id IS NULL THEN RAISE EXCEPTION 'No such week'; END IF;
    IF t.status <> 'submitted' THEN RAISE EXCEPTION 'Only a submitted week is reopened (this one is %)', t.status; END IF;
    IF EXISTS (SELECT 1 FROM time_entries WHERE timesheet_id = t.id AND status = 'approved') THEN
        RAISE EXCEPTION 'Part of this week is approved; ask the manager to reject what must change';
    END IF;
    IF t.member_id <> p_by AND NOT (SELECT ct_has_right('time.approve')) THEN RAISE EXCEPTION 'Only the person or a manager reopens a week'; END IF;
    PERFORM set_config('ct.via_function', '1', true);
    UPDATE time_entries SET status = 'draft' WHERE timesheet_id = t.id AND status = 'submitted';
    UPDATE timesheets SET status = 'open', submitted_at = NULL WHERE id = t.id RETURNING * INTO t;
    PERFORM set_config('ct.via_function', '', true);
    RETURN t;
END$$;

-- Approve what the approver manages: the rate and the cost snapshotted per entry; the week approved when nothing is left.
-- Nobody approves their own week but a super-admin.
CREATE OR REPLACE FUNCTION ct_timesheet_approve(p_timesheet_id bigint, p_by bigint, OUT approved integer, OUT left_submitted integer, OUT week_status text)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t timesheets%ROWTYPE; r record; rr record;
BEGIN
    SELECT * INTO t FROM timesheets WHERE id = p_timesheet_id FOR UPDATE;
    IF t.id IS NULL THEN RAISE EXCEPTION 'No such week'; END IF;
    IF t.status <> 'submitted' THEN RAISE EXCEPTION 'The week of % is %, not submitted', t.week_start, t.status; END IF;
    IF t.member_id = p_by AND NOT EXISTS (SELECT 1 FROM members WHERE id = p_by AND business_role = 'super_admin' AND member_kind = 'human') THEN
        RAISE EXCEPTION 'Nobody approves their own time';
    END IF;
    IF NOT (SELECT ct_has_right('time.approve')) THEN RAISE EXCEPTION 'Approving time needs the Manager role'; END IF;
    approved := 0;
    PERFORM set_config('ct.via_function', '1', true);
    FOR r IN SELECT e.* FROM time_entries e WHERE e.timesheet_id = t.id AND e.status = 'submitted'
                AND e.engagement_id IN (SELECT ct_managed_engagement_ids()) ORDER BY e.entry_date, e.id LOOP
        SELECT * INTO rr FROM ct_resolve_rate(r.engagement_id, r.member_id, r.entry_date);
        UPDATE time_entries SET status = 'approved', approved_at = now(), approved_by = p_by,
                                bill_rate = CASE WHEN billable THEN rr.bill_rate END, rate_source = CASE WHEN billable THEN rr.rate_source ELSE 'none' END,
                                consulting_role_id = rr.consulting_role_id, cost_rate = ct_cost_rate(r.member_id, r.entry_date)
         WHERE id = r.id;
        approved := approved + 1;
    END LOOP;
    SELECT count(*) INTO left_submitted FROM time_entries WHERE timesheet_id = t.id AND status = 'submitted';
    IF left_submitted = 0 THEN
        UPDATE timesheets SET status = 'approved', approved_at = now(), approved_by = p_by WHERE id = t.id RETURNING status INTO week_status;
    ELSE
        week_status := 'submitted';
    END IF;
    PERFORM set_config('ct.via_function', '', true);
END$$;

-- Reject the week: what is still submitted returns to the person with the reason; approved entries stay approved.
CREATE OR REPLACE FUNCTION ct_timesheet_reject(p_timesheet_id bigint, p_by bigint, p_reason text) RETURNS timesheets
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t timesheets%ROWTYPE;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'Rejecting a week needs a reason the person can act on'; END IF;
    SELECT * INTO t FROM timesheets WHERE id = p_timesheet_id FOR UPDATE;
    IF t.id IS NULL THEN RAISE EXCEPTION 'No such week'; END IF;
    IF t.status <> 'submitted' THEN RAISE EXCEPTION 'The week of % is %, not submitted', t.week_start, t.status; END IF;
    IF t.member_id = p_by THEN RAISE EXCEPTION 'Reopen your own week instead of rejecting it'; END IF;
    IF NOT (SELECT ct_has_right('time.approve')) THEN RAISE EXCEPTION 'Rejecting time needs the Manager role'; END IF;
    IF NOT EXISTS (SELECT 1 FROM time_entries e WHERE e.timesheet_id = t.id AND e.status = 'submitted' AND e.engagement_id IN (SELECT ct_managed_engagement_ids()))
       AND NOT ct_is_admin() THEN
        RAISE EXCEPTION 'Nothing in this week is on an engagement you manage';
    END IF;
    PERFORM set_config('ct.via_function', '1', true);
    UPDATE time_entries SET status = 'rejected' WHERE timesheet_id = t.id AND status = 'submitted';
    UPDATE timesheets SET status = 'rejected', rejected_at = now(), rejected_by = p_by, rejection_reason = p_reason WHERE id = t.id RETURNING * INTO t;
    PERFORM set_config('ct.via_function', '', true);
    RETURN t;
END$$;

-- Write off an approved, unbilled entry (it will never be invoiced; realization shows it).
CREATE OR REPLACE FUNCTION ct_time_entry_write_off(p_entry_id bigint, p_by bigint, p_reason text) RETURNS time_entries
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE e time_entries%ROWTYPE;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'A write-off needs a reason'; END IF;
    SELECT * INTO e FROM time_entries WHERE id = p_entry_id FOR UPDATE;
    IF e.id IS NULL THEN RAISE EXCEPTION 'No such time entry'; END IF;
    IF e.status <> 'approved' THEN RAISE EXCEPTION 'Only an approved entry is written off (this one is %)', e.status; END IF;
    IF ct_item_billed('time_entry', e.id) THEN RAISE EXCEPTION 'This entry is on an invoice; credit the invoice instead'; END IF;
    IF NOT (SELECT ct_can_manage_engagement(e.engagement_id)) THEN RAISE EXCEPTION 'You do not manage this engagement'; END IF;
    PERFORM set_config('ct.via_function', '1', true);
    UPDATE time_entries SET status = 'written_off', written_off_at = now(), written_off_by = p_by, written_off_reason = p_reason WHERE id = e.id RETURNING * INTO e;
    PERFORM set_config('ct.via_function', '', true);
    RETURN e;
END$$;

-- "Is this item on a live invoice line?" — defined here as a stub so time can ask it; db/012 replaces the body.
CREATE OR REPLACE FUNCTION ct_item_billed(p_kind text, p_id bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT false $$;

-- The timer: start (one per person; a running one is stopped first), stop (an entry rounded UP to the increment, at least one step).
CREATE OR REPLACE FUNCTION ct_timer_start(p_member_id bigint, p_engagement_id bigint, p_activity_type_id bigint, p_note text) RETURNS time_timers
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t time_timers%ROWTYPE;
BEGIN
    IF EXISTS (SELECT 1 FROM time_timers WHERE member_id = p_member_id) THEN PERFORM ct_timer_stop(p_member_id); END IF;
    IF NOT ct_can_log_on(p_engagement_id, p_member_id, current_date) THEN RAISE EXCEPTION 'Not staffed on this engagement today (or it is not active)'; END IF;
    INSERT INTO time_timers (member_id, engagement_id, activity_type_id, note) VALUES (p_member_id, p_engagement_id, p_activity_type_id, p_note) RETURNING * INTO t;
    RETURN t;
END$$;

CREATE OR REPLACE FUNCTION ct_timer_stop(p_member_id bigint) RETURNS time_entries
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t time_timers%ROWTYPE; inc numeric; h numeric; e time_entries%ROWTYPE;
BEGIN
    SELECT * INTO t FROM time_timers WHERE member_id = p_member_id FOR UPDATE;
    IF t.member_id IS NULL THEN RAISE EXCEPTION 'No timer is running'; END IF;
    SELECT hours_increment INTO inc FROM ct_settings WHERE id = 1;
    h := GREATEST(inc, ceil((EXTRACT(EPOCH FROM (now() - t.started_at)) / 3600.0) / inc) * inc);
    DELETE FROM time_timers WHERE member_id = p_member_id;
    INSERT INTO time_entries (member_id, engagement_id, entry_date, hours, activity_type_id, note, timer_started_at, timesheet_id, currency)
    VALUES (p_member_id, t.engagement_id, (t.started_at AT TIME ZONE COALESCE((SELECT timezone FROM members WHERE id = p_member_id), 'UTC'))::date, h, t.activity_type_id, t.note, t.started_at,
            0, 'USD')          -- the guard fills timesheet_id and currency
    RETURNING * INTO e;
    RETURN e;
END$$;

REVOKE ALL ON FUNCTION ct_timesheet_for(bigint, date), ct_timesheet_totals(bigint), ct_timesheet_submit(bigint, bigint), ct_timesheet_reopen(bigint, bigint),
    ct_timesheet_approve(bigint, bigint), ct_timesheet_reject(bigint, bigint, text), ct_time_entry_write_off(bigint, bigint, text),
    ct_timer_start(bigint, bigint, bigint, text), ct_timer_stop(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ct_timesheet_for(bigint, date), ct_timesheet_totals(bigint), ct_timesheet_submit(bigint, bigint), ct_timesheet_reopen(bigint, bigint),
    ct_timesheet_approve(bigint, bigint), ct_timesheet_reject(bigint, bigint, text), ct_time_entry_write_off(bigint, bigint, text),
    ct_timer_start(bigint, bigint, bigint, text), ct_timer_stop(bigint) TO consultant_tracking_rw;
GRANT EXECUTE ON FUNCTION ct_via_function(), ct_item_billed(text, bigint) TO consultant_tracking_rw, consultant_tracking_records_ro;
GRANT SELECT, INSERT, UPDATE, DELETE ON timesheets, time_entries, time_timers TO consultant_tracking_rw;

COMMIT;
