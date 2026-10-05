-- 010: EXPENSES (design §6, D7): a person's spend on an engagement, with a RECEIPT required to submit at or above the
-- threshold (the category's, else the engagement's policy, else the settings'), BILLABLE at the engagement's markup
-- (default 0 % — pass-through), and REIMBURSABLE when paid personally (HR pays it through the `reimbursements_approved`
-- share and this application marks it paid). Two independent lifecycles, the retired module's rule kept: approval
-- (status) and settlement (billed = an invoice line item names it, db/012; reimbursed = reimbursed_at). Tax is inside the
-- amount; one currency per expense (the engagement's), never converted. Status moves only through the functions.
BEGIN;

CREATE TABLE expenses (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number             text NOT NULL UNIQUE,                                     -- EXP-000001 at creation (D13)
    member_id          bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    engagement_id      bigint NOT NULL REFERENCES engagements(id) ON DELETE RESTRICT,
    expense_date       date NOT NULL,
    category_id        bigint NOT NULL REFERENCES expense_categories(id) ON DELETE RESTRICT,
    vendor_name        text,
    description        text NOT NULL,
    amount             numeric(14,2) NOT NULL CHECK (amount > 0),                -- gross, tax inside
    tax_amount         numeric(14,2) NOT NULL DEFAULT 0 CHECK (tax_amount >= 0 AND tax_amount <= amount),
    currency           char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    paid_with          text NOT NULL DEFAULT 'personal' CHECK (paid_with IN ('personal', 'company_card')),
    reimbursable       boolean GENERATED ALWAYS AS (paid_with = 'personal') STORED,
    billable           boolean NOT NULL DEFAULT true,
    markup_pct         numeric(6,2) NOT NULL DEFAULT 0 CHECK (markup_pct BETWEEN 0 AND 500),
    billable_amount    numeric(14,2) GENERATED ALWAYS AS (round(amount * (1 + markup_pct / 100), 2)) STORED,
    status             text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'submitted', 'approved', 'rejected')),
    submitted_at       timestamptz,
    approved_at        timestamptz,
    approved_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    rejected_at        timestamptz,
    rejected_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    rejection_reason   text,
    reimbursed_at      timestamptz,                                              -- set by HR's read-back (H2) or by hand
    reimbursed_ref     text,                                                     -- the pay run or the payment reference
    department_id      bigint REFERENCES departments(id) ON DELETE SET NULL,     -- the person's practice when recorded
    created_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX expenses_member_idx ON expenses (member_id, expense_date DESC);
CREATE INDEX expenses_engagement_idx ON expenses (engagement_id, expense_date DESC);
CREATE INDEX expenses_status_idx ON expenses (status);
CREATE INDEX expenses_category_idx ON expenses (category_id);
CREATE INDEX expenses_reimburse_idx ON expenses (member_id) WHERE status = 'approved' AND paid_with = 'personal' AND reimbursed_at IS NULL;
CREATE INDEX expenses_dept_idx ON expenses (department_id);
CREATE INDEX expenses_approved_by_idx ON expenses (approved_by);
CREATE INDEX expenses_rejected_by_idx ON expenses (rejected_by);
CREATE INDEX expenses_created_by_idx ON expenses (created_by);
CREATE TRIGGER expenses_touch BEFORE UPDATE ON expenses FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- The receipt threshold that applies to an expense: the category's override, else the engagement's policy, else the settings'.
CREATE OR REPLACE FUNCTION ct_receipt_threshold(p_category_id bigint, p_engagement_id bigint) RETURNS numeric
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((SELECT receipt_required_above FROM expense_categories WHERE id = p_category_id),
                    (SELECT (expense_policy->>'requires_receipt_above')::numeric FROM engagements WHERE id = p_engagement_id),
                    (SELECT receipt_threshold FROM ct_settings WHERE id = 1));
$$;

CREATE OR REPLACE FUNCTION ct_expense_has_receipt(p_expense_id bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT EXISTS (SELECT 1 FROM attachments WHERE record_type = 'expense' AND record_id = p_expense_id);
$$;

CREATE OR REPLACE FUNCTION ct_expense_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE e engagements%ROWTYPE; pol jsonb;
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.number := COALESCE(NEW.number, next_document_number('expense'));
        IF NEW.status <> 'draft' THEN RAISE EXCEPTION 'An expense is born a draft and submitted afterwards'; END IF;
        NEW.department_id := COALESCE(NEW.department_id, ct_primary_department(NEW.member_id));
        NEW.approved_at := NULL; NEW.approved_by := NULL; NEW.reimbursed_at := NULL;
    ELSE
        IF NEW.number <> OLD.number THEN RAISE EXCEPTION 'Expense % keeps its number', OLD.number; END IF;
        IF NEW.status <> OLD.status AND NOT ct_via_function() THEN
            RAISE EXCEPTION 'An expense''s status changes through submit, approve or reject — never by hand';
        END IF;
        IF NEW.reimbursed_at IS DISTINCT FROM OLD.reimbursed_at AND NOT ct_via_function() THEN
            RAISE EXCEPTION 'Reimbursement is recorded through its function';
        END IF;
        IF OLD.status = 'approved' AND NOT ct_via_function()
           AND row(NEW.member_id, NEW.engagement_id, NEW.expense_date, NEW.category_id, NEW.amount, NEW.tax_amount, NEW.currency, NEW.paid_with, NEW.billable, NEW.markup_pct, NEW.description)
               IS DISTINCT FROM row(OLD.member_id, OLD.engagement_id, OLD.expense_date, OLD.category_id, OLD.amount, OLD.tax_amount, OLD.currency, OLD.paid_with, OLD.billable, OLD.markup_pct, OLD.description) THEN
            RAISE EXCEPTION 'Expense % is approved and locked; reject it first, or credit the invoice', OLD.number;
        END IF;
        IF OLD.status = 'submitted' AND NOT ct_via_function()
           AND row(NEW.amount, NEW.tax_amount, NEW.engagement_id, NEW.expense_date, NEW.category_id, NEW.paid_with, NEW.billable, NEW.markup_pct)
               IS DISTINCT FROM row(OLD.amount, OLD.tax_amount, OLD.engagement_id, OLD.expense_date, OLD.category_id, OLD.paid_with, OLD.billable, OLD.markup_pct) THEN
            RAISE EXCEPTION 'Expense % is submitted; ask for it back (reject) to change it', OLD.number;
        END IF;
        IF NEW.member_id <> OLD.member_id THEN RAISE EXCEPTION 'An expense stays with its person'; END IF;
        IF ct_via_function() THEN RETURN NEW; END IF;
    END IF;
    SELECT * INTO e FROM engagements WHERE id = NEW.engagement_id;
    IF e.id IS NULL THEN RAISE EXCEPTION 'No such engagement'; END IF;
    IF NOT ct_can_log_on(NEW.engagement_id, NEW.member_id, NEW.expense_date) THEN
        RAISE EXCEPTION 'Not staffed on % on % (or the engagement is not active)', e.number, NEW.expense_date;
    END IF;
    NEW.currency := e.currency;
    IF TG_OP = 'INSERT' THEN
        pol := COALESCE(e.expense_policy, '{}'::jsonb);
        IF NEW.markup_pct = 0 THEN
            NEW.markup_pct := COALESCE((SELECT default_markup_pct FROM expense_categories WHERE id = NEW.category_id),
                                       (pol->>'markup_pct')::numeric, (SELECT default_markup_pct FROM ct_settings WHERE id = 1), 0);
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER expenses_guard BEFORE INSERT OR UPDATE ON expenses FOR EACH ROW EXECUTE FUNCTION ct_expense_guard();

CREATE OR REPLACE FUNCTION ct_expense_no_delete() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status NOT IN ('draft', 'rejected') THEN RAISE EXCEPTION 'Expense % is % and is never deleted', OLD.number, OLD.status; END IF;
    RETURN OLD;
END$$;
CREATE TRIGGER expenses_no_delete BEFORE DELETE ON expenses FOR EACH ROW EXECUTE FUNCTION ct_expense_no_delete();

-- ---------------------------------------------------------------------------------------------
-- The verbs.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_expense_submit(p_expense_id bigint, p_by bigint) RETURNS expenses
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE x expenses%ROWTYPE; th numeric;
BEGIN
    SELECT * INTO x FROM expenses WHERE id = p_expense_id FOR UPDATE;
    IF x.id IS NULL THEN RAISE EXCEPTION 'No such expense'; END IF;
    IF x.member_id <> p_by AND NOT (SELECT ct_has_right('expenses.approve')) THEN RAISE EXCEPTION 'Only the person submits their own expense'; END IF;
    IF x.status NOT IN ('draft', 'rejected') THEN RAISE EXCEPTION 'Expense % is already %', x.number, x.status; END IF;
    th := ct_receipt_threshold(x.category_id, x.engagement_id);
    IF x.amount >= th AND NOT ct_expense_has_receipt(x.id) THEN
        RAISE EXCEPTION 'A receipt is required at or above % — attach one to submit %', th, x.number;
    END IF;
    PERFORM set_config('ct.via_function', '1', true);
    UPDATE expenses SET status = 'submitted', submitted_at = now(), rejected_at = NULL, rejected_by = NULL, rejection_reason = NULL WHERE id = x.id RETURNING * INTO x;
    PERFORM set_config('ct.via_function', '', true);
    RETURN x;
END$$;

CREATE OR REPLACE FUNCTION ct_expense_approve(p_expense_id bigint, p_by bigint) RETURNS expenses
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE x expenses%ROWTYPE;
BEGIN
    SELECT * INTO x FROM expenses WHERE id = p_expense_id FOR UPDATE;
    IF x.id IS NULL THEN RAISE EXCEPTION 'No such expense'; END IF;
    IF x.status <> 'submitted' THEN RAISE EXCEPTION 'Expense % is %, not submitted', x.number, x.status; END IF;
    IF x.member_id = p_by AND NOT EXISTS (SELECT 1 FROM members WHERE id = p_by AND business_role = 'super_admin' AND member_kind = 'human') THEN
        RAISE EXCEPTION 'Nobody approves their own expense';
    END IF;
    IF NOT (SELECT ct_has_right('expenses.approve')) THEN RAISE EXCEPTION 'Approving expenses needs the Manager role'; END IF;
    IF NOT (SELECT ct_can_manage_engagement(x.engagement_id)) THEN RAISE EXCEPTION 'You do not manage the engagement of %', x.number; END IF;
    PERFORM set_config('ct.via_function', '1', true);
    UPDATE expenses SET status = 'approved', approved_at = now(), approved_by = p_by WHERE id = x.id RETURNING * INTO x;
    PERFORM set_config('ct.via_function', '', true);
    RETURN x;
END$$;

CREATE OR REPLACE FUNCTION ct_expense_reject(p_expense_id bigint, p_by bigint, p_reason text) RETURNS expenses
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE x expenses%ROWTYPE;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'Rejecting an expense needs a reason the person can act on'; END IF;
    SELECT * INTO x FROM expenses WHERE id = p_expense_id FOR UPDATE;
    IF x.id IS NULL THEN RAISE EXCEPTION 'No such expense'; END IF;
    IF x.status NOT IN ('submitted', 'approved') THEN RAISE EXCEPTION 'Expense % is %, not submitted or approved', x.number, x.status; END IF;
    IF x.status = 'approved' AND (ct_item_billed('expense', x.id) OR x.reimbursed_at IS NOT NULL) THEN
        RAISE EXCEPTION 'Expense % is billed or reimbursed; credit the invoice instead', x.number;
    END IF;
    IF NOT (SELECT ct_has_right('expenses.approve')) THEN RAISE EXCEPTION 'Rejecting expenses needs the Manager role'; END IF;
    IF NOT (SELECT ct_can_manage_engagement(x.engagement_id)) THEN RAISE EXCEPTION 'You do not manage the engagement of %', x.number; END IF;
    PERFORM set_config('ct.via_function', '1', true);
    UPDATE expenses SET status = 'rejected', rejected_at = now(), rejected_by = p_by, rejection_reason = p_reason, approved_at = NULL, approved_by = NULL
     WHERE id = x.id RETURNING * INTO x;
    PERFORM set_config('ct.via_function', '', true);
    RETURN x;
END$$;

-- Reimbursed: by HR's read-back (H2) or by hand (billing.write); approved personal-card expenses only.
CREATE OR REPLACE FUNCTION ct_expense_mark_reimbursed(p_expense_id bigint, p_by bigint, p_reference text) RETURNS expenses
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE x expenses%ROWTYPE;
BEGIN
    SELECT * INTO x FROM expenses WHERE id = p_expense_id FOR UPDATE;
    IF x.id IS NULL THEN RAISE EXCEPTION 'No such expense'; END IF;
    IF x.status <> 'approved' THEN RAISE EXCEPTION 'Expense % is not approved', x.number; END IF;
    IF NOT x.reimbursable THEN RAISE EXCEPTION 'Expense % was paid with the company card; nothing to reimburse', x.number; END IF;
    IF x.reimbursed_at IS NOT NULL THEN RAISE EXCEPTION 'Expense % is already reimbursed', x.number; END IF;
    PERFORM set_config('ct.via_function', '1', true);
    UPDATE expenses SET reimbursed_at = now(), reimbursed_ref = p_reference WHERE id = x.id RETURNING * INTO x;
    PERFORM set_config('ct.via_function', '', true);
    RETURN x;
END$$;

REVOKE ALL ON FUNCTION ct_expense_submit(bigint, bigint), ct_expense_approve(bigint, bigint), ct_expense_reject(bigint, bigint, text), ct_expense_mark_reimbursed(bigint, bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ct_expense_submit(bigint, bigint), ct_expense_approve(bigint, bigint), ct_expense_reject(bigint, bigint, text), ct_expense_mark_reimbursed(bigint, bigint, text) TO consultant_tracking_rw;
GRANT EXECUTE ON FUNCTION ct_receipt_threshold(bigint, bigint), ct_expense_has_receipt(bigint) TO consultant_tracking_rw, consultant_tracking_records_ro;
GRANT SELECT, INSERT, UPDATE, DELETE ON expenses TO consultant_tracking_rw;

COMMIT;
