-- 008: ENGAGEMENTS (design §6, D5): the commercial agreement with a client — time-and-materials (hourly plus expenses, an
-- optional not-to-exceed cap) or fixed bid (a fee against milestones; retainers are ADMITTED by the kind column and REFUSED
-- by the guard until their Extended spec lands) — with the STAFFING (who, in what role, at what override, from when),
-- per-engagement ROLE RATES, MILESTONES, BUDGET ALERTS, the RATE RESOLUTION (D6) and WHO SEES WHAT (design §3):
--
--   an engagement is seen by: anyone ever staffed on it; its manager; an admin of its practice (the kernel's department);
--                             anyone holding Billing, Finance or admin (ct_sees_all); a super-admin.
--   an engagement is managed by: a holder of engagements.manage who is its manager or an admin of its practice, or who
--                             holds billing.write; the admin. Finance manages nothing.
BEGIN;

CREATE TABLE engagements (
    id                        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number                    text NOT NULL UNIQUE,                                   -- ENG-0001 at creation (D13)
    client_id                 bigint NOT NULL REFERENCES clients(id) ON DELETE RESTRICT,
    name                      text NOT NULL,
    kind                      text NOT NULL CHECK (kind IN ('time_and_materials', 'fixed_bid', 'retainer')),
    status                    text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active', 'on_hold', 'closed', 'cancelled')),
    start_date                date NOT NULL DEFAULT current_date,
    end_date                  date,
    currency                  char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),       -- the client's; fixed once anything is billed (db/012)
    po_number                 text,
    rate_card_id              bigint REFERENCES rate_cards(id) ON DELETE SET NULL,  -- NULL = the client's default card
    nte_amount                numeric(14,2) CHECK (nte_amount > 0),                  -- not-to-exceed (T&M): warns at drafting, refuses issuing above it
    budget_hours              numeric(10,2) CHECK (budget_hours > 0),
    budget_amount             numeric(14,2) CHECK (budget_amount >= 0),               -- the fee (fixed bid) or a soft budget (T&M)
    ai_budget_amount          numeric(14,2) CHECK (ai_budget_amount >= 0),            -- tokens and hosting, at cost
    expense_policy            jsonb NOT NULL DEFAULT '{}'::jsonb,                     -- {billable_default, markup_pct, requires_receipt_above}
    billing_cadence           text NOT NULL DEFAULT 'monthly' CHECK (billing_cadence IN ('weekly', 'biweekly', 'monthly', 'on_milestone', 'manual')),
    next_billing_date         date,
    time_grouping             text CHECK (time_grouping IN ('by_person', 'by_role', 'by_activity', 'by_day', 'single_line')),   -- NULL = the settings'
    share_receipts_with_client boolean,                                              -- NULL = the settings' default
    department_id             bigint REFERENCES departments(id) ON DELETE SET NULL,  -- the practice that owns it
    manager_member_id         bigint REFERENCES members(id) ON DELETE SET NULL,
    projects_ref              text,                                                  -- Projects' key, by hand until P1
    description               text,
    activated_at              timestamptz,
    closed_at                 timestamptz,
    closed_by                 bigint REFERENCES members(id) ON DELETE SET NULL,
    created_by                bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at                timestamptz NOT NULL DEFAULT now(),
    updated_at                timestamptz NOT NULL DEFAULT now(),
    CHECK (end_date IS NULL OR end_date >= start_date),
    CHECK (kind <> 'fixed_bid' OR nte_amount IS NULL)
);
CREATE INDEX engagements_client_idx ON engagements (client_id, status);
CREATE INDEX engagements_status_idx ON engagements (status, end_date);
CREATE INDEX engagements_manager_idx ON engagements (manager_member_id);
CREATE INDEX engagements_dept_idx ON engagements (department_id);
CREATE INDEX engagements_rate_card_idx ON engagements (rate_card_id);
CREATE INDEX engagements_billing_idx ON engagements (next_billing_date) WHERE status = 'active' AND billing_cadence NOT IN ('manual', 'on_milestone');
CREATE INDEX engagements_name_trgm_idx ON engagements USING gin (name gin_trgm_ops);
CREATE INDEX engagements_closed_by_idx ON engagements (closed_by);
CREATE INDEX engagements_created_by_idx ON engagements (created_by);
CREATE TRIGGER engagements_touch BEFORE UPDATE ON engagements FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Who is staffed, in what role, with what override, from when to when (a person may be re-staffed later in another role).
CREATE TABLE engagement_members (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    engagement_id       bigint NOT NULL REFERENCES engagements(id) ON DELETE CASCADE,
    member_id           bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    consulting_role_id  bigint NOT NULL REFERENCES consulting_roles(id) ON DELETE RESTRICT,
    bill_rate_override  numeric(12,2) CHECK (bill_rate_override >= 0),          -- this person on this engagement, per hour; NULL = the role's rate
    valid_from          date NOT NULL,
    valid_to            date,                                                    -- NULL = still staffed
    note                text,
    created_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (valid_to IS NULL OR valid_to > valid_from),
    EXCLUDE USING gist (engagement_id WITH =, member_id WITH =, daterange(valid_from, valid_to, '[)') WITH &&)
);
CREATE INDEX engagement_members_member_idx ON engagement_members (member_id, valid_from DESC);
CREATE INDEX engagement_members_engagement_idx ON engagement_members (engagement_id);
CREATE INDEX engagement_members_role_idx ON engagement_members (consulting_role_id);
CREATE INDEX engagement_members_created_by_idx ON engagement_members (created_by);
CREATE TRIGGER engagement_members_touch BEFORE UPDATE ON engagement_members FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A role's rate on THIS engagement, overriding the card.
CREATE TABLE engagement_role_rates (
    engagement_id       bigint NOT NULL REFERENCES engagements(id) ON DELETE CASCADE,
    consulting_role_id  bigint NOT NULL REFERENCES consulting_roles(id) ON DELETE RESTRICT,
    bill_rate           numeric(12,2) NOT NULL CHECK (bill_rate >= 0),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (engagement_id, consulting_role_id)
);
CREATE INDEX engagement_role_rates_role_idx ON engagement_role_rates (consulting_role_id);
CREATE TRIGGER engagement_role_rates_touch BEFORE UPDATE ON engagement_role_rates FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE milestones (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    engagement_id    bigint NOT NULL REFERENCES engagements(id) ON DELETE CASCADE,
    name             text NOT NULL,
    description      text,
    amount           numeric(14,2) NOT NULL DEFAULT 0 CHECK (amount >= 0),       -- billed on acceptance (fixed bid); 0 on a T&M deliverable
    due_date         date,
    status           text NOT NULL DEFAULT 'planned' CHECK (status IN ('planned', 'delivered', 'accepted', 'invoiced', 'cancelled')),
    sort_order       integer NOT NULL DEFAULT 0,
    delivered_at     timestamptz,
    accepted_at      timestamptz,
    accepted_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    acceptance_note  text,                                                       -- who at the client accepted, how (an attachment may carry the evidence)
    cancelled_at     timestamptz,
    cancel_reason    text,
    created_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX milestones_engagement_idx ON milestones (engagement_id, sort_order);
CREATE INDEX milestones_due_idx ON milestones (due_date) WHERE status IN ('planned', 'delivered');
CREATE INDEX milestones_accepted_by_idx ON milestones (accepted_by);
CREATE INDEX milestones_created_by_idx ON milestones (created_by);
CREATE TRIGGER milestones_touch BEFORE UPDATE ON milestones FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE budget_alerts (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    engagement_id    bigint NOT NULL REFERENCES engagements(id) ON DELETE CASCADE,
    kind             text NOT NULL CHECK (kind IN ('hours', 'amount', 'nte', 'ai')),
    threshold_pct    integer NOT NULL CHECK (threshold_pct BETWEEN 1 AND 1000),
    value_at         numeric(14,2) NOT NULL,                                    -- hours or money consumed when it crossed
    budget_at        numeric(14,2) NOT NULL,
    written_at       timestamptz NOT NULL DEFAULT now(),
    acknowledged_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    acknowledged_at  timestamptz,
    UNIQUE (engagement_id, kind, threshold_pct)
);
CREATE INDEX budget_alerts_open_idx ON budget_alerts (engagement_id) WHERE acknowledged_at IS NULL;
CREATE INDEX budget_alerts_acknowledged_by_idx ON budget_alerts (acknowledged_by);

-- Set by the writer's functions for their transaction: a status move the guards admit only through a function.
CREATE OR REPLACE FUNCTION ct_via_function() RETURNS boolean
    LANGUAGE sql STABLE AS $$ SELECT COALESCE(current_setting('ct.via_function', true), '') = '1' $$;

-- ---------------------------------------------------------------------------------------------
-- The engagement's own rules: numbered at creation; born a draft (or active — the form's "start now"); legal moves;
-- retainers refused until their spec; a fixed bid carries no NTE.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_engagement_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.kind = 'retainer' THEN
        RAISE EXCEPTION 'Retainers are not in this version: make it time-and-materials or fixed bid';
    END IF;
    IF TG_OP = 'INSERT' THEN
        NEW.number := COALESCE(NEW.number, next_document_number('engagement'));
        IF NEW.status NOT IN ('draft', 'active') THEN RAISE EXCEPTION 'An engagement is born a draft or active'; END IF;
        IF NEW.status = 'active' THEN NEW.activated_at := COALESCE(NEW.activated_at, now()); END IF;
        IF NEW.next_billing_date IS NULL AND NEW.billing_cadence NOT IN ('manual', 'on_milestone') THEN
            NEW.next_billing_date := CASE NEW.billing_cadence WHEN 'weekly' THEN ct_week_start(NEW.start_date) + 7
                                                              WHEN 'biweekly' THEN ct_week_start(NEW.start_date) + 14
                                                              ELSE (date_trunc('month', NEW.start_date::timestamp) + interval '1 month')::date END;
        END IF;
        RETURN NEW;
    END IF;
    IF NEW.number <> OLD.number THEN RAISE EXCEPTION 'Engagement % keeps its number', OLD.number; END IF;
    IF NEW.status <> OLD.status THEN
        IF OLD.status = 'cancelled' THEN RAISE EXCEPTION 'Engagement % is cancelled and stays so', OLD.number; END IF;
        IF OLD.status = 'draft' AND NEW.status NOT IN ('active', 'cancelled') THEN RAISE EXCEPTION 'A draft engagement becomes active or is cancelled'; END IF;
        IF OLD.status = 'active' AND NEW.status NOT IN ('on_hold', 'closed', 'cancelled') THEN RAISE EXCEPTION 'An active engagement goes on hold, closes or is cancelled'; END IF;
        IF OLD.status = 'on_hold' AND NEW.status NOT IN ('active', 'closed', 'cancelled') THEN RAISE EXCEPTION 'An engagement on hold resumes, closes or is cancelled'; END IF;
        IF OLD.status = 'closed' AND NEW.status <> 'active' THEN RAISE EXCEPTION 'A closed engagement can only be reopened (active)'; END IF;
        IF NEW.status = 'active' THEN NEW.activated_at := COALESCE(OLD.activated_at, now()); NEW.closed_at := NULL; NEW.closed_by := NULL; END IF;
        IF NEW.status = 'closed' THEN NEW.closed_at := COALESCE(NEW.closed_at, now()); END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER engagements_guard BEFORE INSERT OR UPDATE ON engagements FOR EACH ROW EXECUTE FUNCTION ct_engagement_guard();

CREATE OR REPLACE FUNCTION ct_engagement_no_delete() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status <> 'draft' THEN RAISE EXCEPTION 'Engagement % was active and is never deleted; close or cancel it', OLD.number; END IF;
    RETURN OLD;
END$$;
CREATE TRIGGER engagements_no_delete BEFORE DELETE ON engagements FOR EACH ROW EXECUTE FUNCTION ct_engagement_no_delete();

-- A staffing names a person the mirror admits; a milestone's moves.
CREATE OR REPLACE FUNCTION ct_milestone_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'planned' THEN RAISE EXCEPTION 'A milestone is born planned'; END IF;
        RETURN NEW;
    END IF;
    IF ct_via_function() THEN RETURN NEW; END IF;      -- invoicing marks it invoiced; a void returns it to accepted (db/012)
    IF OLD.status = 'invoiced' AND row(NEW.status, NEW.amount, NEW.name) IS DISTINCT FROM row(OLD.status, OLD.amount, OLD.name) THEN
        RAISE EXCEPTION 'Milestone "%" is invoiced and cannot change (credit the invoice)', OLD.name;
    END IF;
    IF OLD.status = 'cancelled' AND NEW.status <> 'cancelled' THEN RAISE EXCEPTION 'Milestone "%" is cancelled', OLD.name; END IF;
    IF NEW.status <> OLD.status THEN
        IF NEW.status = 'delivered' AND OLD.status <> 'planned' THEN RAISE EXCEPTION 'Only a planned milestone is delivered'; END IF;
        IF NEW.status = 'accepted' AND OLD.status NOT IN ('planned', 'delivered') THEN RAISE EXCEPTION 'Only a planned or delivered milestone is accepted'; END IF;
        IF NEW.status = 'invoiced' AND OLD.status <> 'accepted' THEN RAISE EXCEPTION 'Only an accepted milestone is invoiced'; END IF;
        IF NEW.status = 'planned' AND OLD.status NOT IN ('delivered') THEN RAISE EXCEPTION 'Only a delivered milestone goes back to planned'; END IF;
        IF NEW.status = 'delivered' THEN NEW.delivered_at := COALESCE(NEW.delivered_at, now()); END IF;
        IF NEW.status = 'accepted' THEN NEW.accepted_at := COALESCE(NEW.accepted_at, now()); IF NEW.accepted_by IS NULL THEN RAISE EXCEPTION 'Accepting a milestone names who accepted it'; END IF; END IF;
        IF NEW.status = 'cancelled' THEN NEW.cancelled_at := COALESCE(NEW.cancelled_at, now()); END IF;
    END IF;
    IF OLD.status IN ('accepted') AND NEW.amount <> OLD.amount THEN RAISE EXCEPTION 'An accepted milestone''s amount is fixed'; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER milestones_guard BEFORE INSERT OR UPDATE ON milestones FOR EACH ROW EXECUTE FUNCTION ct_milestone_guard();

-- ---------------------------------------------------------------------------------------------
-- The rate resolution (D6): a person's override on the engagement → the engagement's role rate → the rate card
-- (the engagement's, else the client's default) → none. Answered for a date, from the staffing valid then.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_resolve_rate(p_engagement_id bigint, p_member_id bigint, p_date date,
                                           OUT bill_rate numeric, OUT rate_source text, OUT consulting_role_id bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE st engagement_members%ROWTYPE; e engagements%ROWTYPE; card bigint;
BEGIN
    SELECT * INTO e FROM engagements WHERE id = p_engagement_id;
    IF e.id IS NULL THEN RETURN; END IF;
    SELECT * INTO st FROM engagement_members
     WHERE engagement_id = p_engagement_id AND member_id = p_member_id AND valid_from <= p_date AND (valid_to IS NULL OR valid_to > p_date)
     ORDER BY valid_from DESC LIMIT 1;
    IF st.id IS NULL THEN
        -- not staffed on that date: the latest staffing names the role, so a manager's correction can still price it
        SELECT * INTO st FROM engagement_members WHERE engagement_id = p_engagement_id AND member_id = p_member_id ORDER BY valid_from DESC LIMIT 1;
        IF st.id IS NULL THEN rate_source := 'none'; RETURN; END IF;
    END IF;
    consulting_role_id := st.consulting_role_id;
    IF st.bill_rate_override IS NOT NULL THEN bill_rate := st.bill_rate_override; rate_source := 'member_override'; RETURN; END IF;
    SELECT r.bill_rate INTO bill_rate FROM engagement_role_rates r WHERE r.engagement_id = p_engagement_id AND r.consulting_role_id = st.consulting_role_id;
    IF bill_rate IS NOT NULL THEN rate_source := 'engagement_role'; RETURN; END IF;
    card := COALESCE(e.rate_card_id, (SELECT default_rate_card_id FROM clients WHERE id = e.client_id));
    IF card IS NOT NULL THEN
        SELECT r.bill_rate INTO bill_rate FROM rate_card_rates r WHERE r.rate_card_id = card AND r.consulting_role_id = st.consulting_role_id;
        IF bill_rate IS NOT NULL THEN rate_source := 'rate_card'; RETURN; END IF;
    END IF;
    rate_source := 'none';
END$$;

-- ---------------------------------------------------------------------------------------------
-- Who sees what (design §3). Uncorrelated sets the views test once per statement; one function PHP's gates ask.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_visible_engagement_ids() RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT app_is_active_member() THEN RETURN; END IF;
    IF ct_sees_all() THEN
        RETURN QUERY SELECT id FROM engagements;
        RETURN;
    END IF;
    RETURN QUERY
        SELECT em.engagement_id FROM engagement_members em WHERE em.member_id = app_current_member_id()
        UNION SELECT e.id FROM engagements e WHERE e.manager_member_id = app_current_member_id()
        UNION SELECT e.id FROM engagements e WHERE e.department_id IN (SELECT app_admin_department_ids());
END$$;

CREATE OR REPLACE FUNCTION ct_can_see_engagement(p_engagement_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT app_is_active_member() THEN RETURN false; END IF;
    RETURN ct_sees_all() OR p_engagement_id IN (SELECT ct_visible_engagement_ids());
END$$;

-- Managed: engagements.manage AND (its manager, or an admin of its practice, or billing.write); the admin always; Finance never.
CREATE OR REPLACE FUNCTION ct_managed_engagement_ids() RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT app_is_active_member() OR NOT ct_has_right('engagements.manage') THEN RETURN; END IF;
    IF ct_is_admin() OR ct_has_right('billing.write') THEN
        RETURN QUERY SELECT id FROM engagements;
        RETURN;
    END IF;
    RETURN QUERY
        SELECT e.id FROM engagements e WHERE e.manager_member_id = app_current_member_id()
        UNION SELECT e.id FROM engagements e WHERE e.department_id IN (SELECT app_admin_department_ids());
END$$;

CREATE OR REPLACE FUNCTION ct_can_manage_engagement(p_engagement_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN p_engagement_id IN (SELECT ct_managed_engagement_ids());
END$$;

-- May this person log time or an expense on this engagement on this date: active, and staffed then (a manager of it may too).
CREATE OR REPLACE FUNCTION ct_can_log_on(p_engagement_id bigint, p_member_id bigint, p_date date) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE e engagements%ROWTYPE;
BEGIN
    SELECT * INTO e FROM engagements WHERE id = p_engagement_id;
    IF e.id IS NULL OR e.status <> 'active' THEN RETURN false; END IF;
    IF p_date < e.start_date THEN RETURN false; END IF;
    RETURN EXISTS (SELECT 1 FROM engagement_members em WHERE em.engagement_id = p_engagement_id AND em.member_id = p_member_id
                     AND em.valid_from <= p_date AND (em.valid_to IS NULL OR em.valid_to > p_date))
        OR e.manager_member_id = p_member_id;
END$$;

-- A person's primary department (the practice a time entry is counted in), else their first live one.
CREATE OR REPLACE FUNCTION ct_primary_department(p_member_id bigint) RETURNS bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT dm.department_id FROM department_members dm JOIN departments d ON d.id = dm.department_id AND d.archived_at IS NULL
     WHERE dm.member_id = p_member_id AND dm.left_at IS NULL ORDER BY dm.is_primary DESC, dm.joined_at NULLS LAST, dm.department_id LIMIT 1;
$$;

GRANT EXECUTE ON FUNCTION ct_resolve_rate(bigint, bigint, date), ct_visible_engagement_ids(), ct_can_see_engagement(bigint), ct_managed_engagement_ids(),
    ct_can_manage_engagement(bigint), ct_can_log_on(bigint, bigint, date), ct_primary_department(bigint)
    TO consultant_tracking_rw, consultant_tracking_records_ro, consultant_tracking_activity_ro;
GRANT SELECT, INSERT, UPDATE, DELETE ON engagements, engagement_members, engagement_role_rates, milestones, budget_alerts TO consultant_tracking_rw;

COMMIT;
