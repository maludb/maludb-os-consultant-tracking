-- 012: INVOICING (design §4, §6; D9, D10, D13): an invoice is ONE engagement's bill for a period — lines of kind time,
-- expense, usage, milestone, fixed, adjustment or discount — with the BACKING ITEMS each line bills (invoice_line_items:
-- a time entry, an expense, a usage charge or a milestone, each on at most ONE live line — the UNIQUE indexes are the
-- "billed once" rule; a void releases them). Numbered gapless on ISSUE; immutable once issued; a correction is a credit
-- note. Payments here are a RECORD OF STATUS (the ledger keeps the receipt — D10): paid is derived, never typed. The
-- client's door is the secure link (D9) and their claim ("I paid this on …") never records money until billing confirms.
BEGIN;

CREATE TABLE invoices (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number              text UNIQUE,                                             -- gapless, on issue
    client_id           bigint NOT NULL REFERENCES clients(id) ON DELETE RESTRICT,
    engagement_id       bigint NOT NULL REFERENCES engagements(id) ON DELETE RESTRICT,
    period_start        date,
    period_end          date,
    issue_date          date NOT NULL DEFAULT current_date,
    due_date            date NOT NULL,
    currency            char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    status              text NOT NULL DEFAULT 'draft'
                        CHECK (status IN ('draft', 'issued', 'sent', 'viewed', 'partially_paid', 'paid', 'void', 'written_off')),
    subtotal            numeric(14,2) NOT NULL DEFAULT 0,
    tax_total           numeric(14,2) NOT NULL DEFAULT 0,
    total               numeric(14,2) NOT NULL DEFAULT 0,
    amount_paid         numeric(14,2) NOT NULL DEFAULT 0 CHECK (amount_paid >= 0),
    amount_credited     numeric(14,2) NOT NULL DEFAULT 0 CHECK (amount_credited >= 0),
    balance_due         numeric(14,2) GENERATED ALWAYS AS (total - amount_paid - amount_credited) STORED,
    po_number           text,
    memo                text,                                                    -- to the client, on the invoice
    terms_text          text,
    internal_note       text,
    external_ref        text,                                                    -- what the ledger calls it, when it tells us
    department_id       bigint REFERENCES departments(id) ON DELETE SET NULL,
    nte_override_reason text,                                                    -- issued above the NTE cap, by billing, with this reason
    issued_at           timestamptz,
    issued_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    sent_at             timestamptz,
    viewed_at           timestamptz,
    paid_at             timestamptz,
    voided_at           timestamptz,
    voided_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    void_reason         text,
    written_off_at      timestamptz,
    written_off_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    write_off_reason    text,
    last_reminder_at    timestamptz,
    reminder_count      integer NOT NULL DEFAULT 0 CHECK (reminder_count >= 0),
    created_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (status = 'draft' OR number IS NOT NULL),
    CHECK (due_date >= issue_date),
    CHECK (period_end IS NULL OR period_start IS NULL OR period_end >= period_start)
);
CREATE INDEX invoices_client_idx ON invoices (client_id, issue_date DESC);
CREATE INDEX invoices_engagement_idx ON invoices (engagement_id, issue_date DESC);
CREATE INDEX invoices_status_due_idx ON invoices (status, due_date);
CREATE INDEX invoices_open_idx ON invoices (due_date) WHERE status IN ('issued', 'sent', 'viewed', 'partially_paid');
CREATE INDEX invoices_issue_idx ON invoices (issue_date);
CREATE INDEX invoices_dept_idx ON invoices (department_id);
CREATE INDEX invoices_issued_by_idx ON invoices (issued_by);
CREATE INDEX invoices_voided_by_idx ON invoices (voided_by);
CREATE INDEX invoices_written_off_by_idx ON invoices (written_off_by);
CREATE INDEX invoices_created_by_idx ON invoices (created_by);
CREATE TRIGGER invoices_touch BEFORE UPDATE ON invoices FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE invoice_lines (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id      bigint NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    sort_order      integer NOT NULL DEFAULT 0,
    kind            text NOT NULL CHECK (kind IN ('time', 'expense', 'usage', 'milestone', 'fixed', 'adjustment', 'discount')),
    description     text NOT NULL,
    quantity        numeric(14,4) NOT NULL DEFAULT 1 CHECK (quantity > 0),
    unit            text,                                                        -- 'hours', 'each', 'tokens (M)'
    unit_price      numeric(14,2) NOT NULL,
    tax_rate_id     bigint REFERENCES tax_rates(id) ON DELETE SET NULL,
    tax_rate        numeric(7,4) NOT NULL DEFAULT 0,
    line_subtotal   numeric(14,2) NOT NULL DEFAULT 0,
    line_tax        numeric(14,2) NOT NULL DEFAULT 0,
    line_total      numeric(14,2) NOT NULL DEFAULT 0,
    group_key       text,                                                        -- what grouped the items (a person, a role, a day …)
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (kind IN ('adjustment', 'discount') OR unit_price >= 0)
);
CREATE INDEX invoice_lines_invoice_idx ON invoice_lines (invoice_id, sort_order);
CREATE INDEX invoice_lines_tax_idx ON invoice_lines (tax_rate_id);
CREATE TRIGGER invoice_lines_touch BEFORE UPDATE ON invoice_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- THE "billed once" rule: an item sits on at most one live line. A void deletes the invoice's rows here (the lines stay as text).
CREATE TABLE invoice_line_items (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id       bigint NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    line_id          bigint NOT NULL REFERENCES invoice_lines(id) ON DELETE CASCADE,
    time_entry_id    bigint REFERENCES time_entries(id) ON DELETE RESTRICT,
    expense_id       bigint REFERENCES expenses(id) ON DELETE RESTRICT,
    usage_charge_id  bigint REFERENCES usage_charges(id) ON DELETE RESTRICT,
    milestone_id     bigint REFERENCES milestones(id) ON DELETE RESTRICT,
    amount           numeric(14,2) NOT NULL,                                     -- the item's share of the line
    created_at       timestamptz NOT NULL DEFAULT now(),
    CHECK ((time_entry_id IS NOT NULL)::int + (expense_id IS NOT NULL)::int + (usage_charge_id IS NOT NULL)::int + (milestone_id IS NOT NULL)::int = 1)
);
CREATE UNIQUE INDEX invoice_line_items_time_once ON invoice_line_items (time_entry_id) WHERE time_entry_id IS NOT NULL;
CREATE UNIQUE INDEX invoice_line_items_expense_once ON invoice_line_items (expense_id) WHERE expense_id IS NOT NULL;
CREATE UNIQUE INDEX invoice_line_items_usage_once ON invoice_line_items (usage_charge_id) WHERE usage_charge_id IS NOT NULL;
CREATE UNIQUE INDEX invoice_line_items_milestone_once ON invoice_line_items (milestone_id) WHERE milestone_id IS NOT NULL;
CREATE INDEX invoice_line_items_invoice_idx ON invoice_line_items (invoice_id);
CREATE INDEX invoice_line_items_line_idx ON invoice_line_items (line_id);

CREATE TABLE credit_notes (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number              text UNIQUE,
    client_id           bigint NOT NULL REFERENCES clients(id) ON DELETE RESTRICT,
    engagement_id       bigint REFERENCES engagements(id) ON DELETE SET NULL,
    invoice_id          bigint REFERENCES invoices(id) ON DELETE SET NULL,       -- the invoice it was raised against, if one
    issue_date          date NOT NULL DEFAULT current_date,
    currency            char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    status              text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'issued', 'void')),
    subtotal            numeric(14,2) NOT NULL DEFAULT 0,
    tax_total           numeric(14,2) NOT NULL DEFAULT 0,
    total               numeric(14,2) NOT NULL DEFAULT 0,
    amount_applied      numeric(14,2) NOT NULL DEFAULT 0 CHECK (amount_applied >= 0),
    balance             numeric(14,2) GENERATED ALWAYS AS (total - amount_applied) STORED,
    reason              text,
    external_ref        text,
    issued_at           timestamptz,
    issued_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    voided_at           timestamptz,
    voided_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    void_reason         text,
    created_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK (status = 'draft' OR number IS NOT NULL)
);
CREATE INDEX credit_notes_client_idx ON credit_notes (client_id, issue_date DESC);
CREATE INDEX credit_notes_engagement_idx ON credit_notes (engagement_id);
CREATE INDEX credit_notes_invoice_idx ON credit_notes (invoice_id);
CREATE INDEX credit_notes_issued_by_idx ON credit_notes (issued_by);
CREATE INDEX credit_notes_voided_by_idx ON credit_notes (voided_by);
CREATE INDEX credit_notes_created_by_idx ON credit_notes (created_by);
CREATE TRIGGER credit_notes_touch BEFORE UPDATE ON credit_notes FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE credit_note_lines (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    credit_note_id  bigint NOT NULL REFERENCES credit_notes(id) ON DELETE CASCADE,
    sort_order      integer NOT NULL DEFAULT 0,
    kind            text NOT NULL DEFAULT 'adjustment' CHECK (kind IN ('time', 'expense', 'usage', 'milestone', 'fixed', 'adjustment')),
    description     text NOT NULL,
    quantity        numeric(14,4) NOT NULL DEFAULT 1 CHECK (quantity > 0),
    unit_price      numeric(14,2) NOT NULL CHECK (unit_price >= 0),
    tax_rate_id     bigint REFERENCES tax_rates(id) ON DELETE SET NULL,
    tax_rate        numeric(7,4) NOT NULL DEFAULT 0,
    line_subtotal   numeric(14,2) NOT NULL DEFAULT 0,
    line_tax        numeric(14,2) NOT NULL DEFAULT 0,
    line_total      numeric(14,2) NOT NULL DEFAULT 0,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX credit_note_lines_note_idx ON credit_note_lines (credit_note_id, sort_order);
CREATE INDEX credit_note_lines_tax_idx ON credit_note_lines (tax_rate_id);
CREATE TRIGGER credit_note_lines_touch BEFORE UPDATE ON credit_note_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE credit_applications (
    credit_note_id  bigint NOT NULL REFERENCES credit_notes(id) ON DELETE RESTRICT,
    invoice_id      bigint NOT NULL REFERENCES invoices(id) ON DELETE RESTRICT,
    amount          numeric(14,2) NOT NULL CHECK (amount > 0),
    applied_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    applied_at      timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (credit_note_id, invoice_id)
);
CREATE INDEX credit_applications_invoice_idx ON credit_applications (invoice_id);
CREATE INDEX credit_applications_by_idx ON credit_applications (applied_by);

-- Money the firm learned of: a record of status, never a receipt (the ledger's).
CREATE TABLE invoice_payments (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id    bigint NOT NULL REFERENCES invoices(id) ON DELETE RESTRICT,
    paid_on       date NOT NULL DEFAULT current_date,
    amount        numeric(14,2) NOT NULL CHECK (amount > 0),
    currency      char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    method        text NOT NULL DEFAULT 'transfer' CHECK (method IN ('transfer', 'card', 'cheque', 'cash', 'other')),
    reference     text,
    source        text NOT NULL DEFAULT 'manual' CHECK (source IN ('manual', 'ledger', 'claim')),
    status        text NOT NULL DEFAULT 'recorded' CHECK (status IN ('recorded', 'void')),
    recorded_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    voided_at     timestamptz,
    voided_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    void_reason   text,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX invoice_payments_invoice_idx ON invoice_payments (invoice_id, paid_on DESC);
CREATE INDEX invoice_payments_recorded_by_idx ON invoice_payments (recorded_by);
CREATE INDEX invoice_payments_voided_by_idx ON invoice_payments (voided_by);
CREATE TRIGGER invoice_payments_touch BEFORE UPDATE ON invoice_payments FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- What a client said on the secure link (D9). Never money until billing confirms it into a payment.
CREATE TABLE payment_claims (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id   bigint NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    claimed_on   date NOT NULL,
    method       text NOT NULL CHECK (method IN ('transfer', 'card', 'cheque', 'cash', 'other')),
    reference    text,
    amount       numeric(14,2) CHECK (amount > 0),
    note         text,
    status       text NOT NULL DEFAULT 'submitted' CHECK (status IN ('submitted', 'confirmed', 'dismissed')),
    payment_id   bigint REFERENCES invoice_payments(id) ON DELETE SET NULL,
    decided_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at   timestamptz,
    ip_address   inet,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX payment_claims_invoice_idx ON payment_claims (invoice_id, created_at DESC);
CREATE INDEX payment_claims_open_idx ON payment_claims (created_at) WHERE status = 'submitted';
CREATE INDEX payment_claims_payment_idx ON payment_claims (payment_id);
CREATE INDEX payment_claims_decided_by_idx ON payment_claims (decided_by);

-- The client's door: /i/<48 hex>. Only the hash is stored; rotating makes a new row and dates the old; the link dies
-- link_lifetime_days after the invoice is paid (the worker sets expires_at).
CREATE TABLE invoice_links_secure (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id    bigint NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    token_hash    text NOT NULL UNIQUE,
    expires_at    timestamptz,
    rotated_at    timestamptz,
    last_used_at  timestamptz,
    view_count    integer NOT NULL DEFAULT 0,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX invoice_links_secure_invoice_idx ON invoice_links_secure (invoice_id) WHERE rotated_at IS NULL;

-- ---------------------------------------------------------------------------------------------
-- "Billed once" asked from anywhere (replaces db/009's stub).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_item_billed(p_kind text, p_id bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT EXISTS (SELECT 1 FROM invoice_line_items i
                    WHERE (p_kind = 'time_entry' AND i.time_entry_id = p_id) OR (p_kind = 'expense' AND i.expense_id = p_id)
                       OR (p_kind = 'usage_charge' AND i.usage_charge_id = p_id) OR (p_kind = 'milestone' AND i.milestone_id = p_id));
$$;

-- An engagement's currency is fixed once anything is invoiced.
CREATE OR REPLACE FUNCTION ct_engagement_currency_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.currency <> OLD.currency AND EXISTS (SELECT 1 FROM invoices WHERE engagement_id = OLD.id) THEN
        RAISE EXCEPTION 'Engagement % has invoices in %; its currency is fixed', OLD.number, OLD.currency;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER engagements_currency_guard BEFORE UPDATE OF currency ON engagements FOR EACH ROW EXECUTE FUNCTION ct_engagement_currency_guard();

-- ---------------------------------------------------------------------------------------------
-- Lines: money per line; changeable while the document is a draft only; the document recalculated after.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_invoice_line_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE st text; m record;
BEGIN
    SELECT status INTO st FROM invoices WHERE id = COALESCE(NEW.invoice_id, OLD.invoice_id);
    IF st IS DISTINCT FROM 'draft' AND NOT ct_via_function() THEN
        RAISE EXCEPTION 'The lines of an invoice change only while it is a draft (it is %)', st;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    IF NEW.tax_rate_id IS NOT NULL THEN SELECT rate INTO NEW.tax_rate FROM tax_rates WHERE id = NEW.tax_rate_id; ELSE NEW.tax_rate := 0; END IF;
    SELECT * INTO m FROM ct_line_money(NEW.quantity, NEW.unit_price, NEW.tax_rate);
    NEW.line_subtotal := m.line_subtotal; NEW.line_tax := m.line_tax; NEW.line_total := m.line_total;
    RETURN NEW;
END$$;
CREATE TRIGGER invoice_lines_before BEFORE INSERT OR UPDATE OR DELETE ON invoice_lines FOR EACH ROW EXECUTE FUNCTION ct_invoice_line_before();

-- The ONE place an invoice's totals, paid, credited and status are written.
CREATE OR REPLACE FUNCTION ct_invoice_recalculate(p_invoice_id bigint) RETURNS invoices
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE i invoices%ROWTYPE; s numeric(14,2); t numeric(14,2); paid numeric(14,2); cred numeric(14,2); ns text;
BEGIN
    SELECT * INTO i FROM invoices WHERE id = p_invoice_id FOR UPDATE;
    IF i.id IS NULL THEN RAISE EXCEPTION 'No such invoice'; END IF;
    SELECT COALESCE(sum(line_subtotal), 0), COALESCE(sum(line_tax), 0) INTO s, t FROM invoice_lines WHERE invoice_id = i.id;
    SELECT COALESCE(sum(p.amount), 0) INTO paid FROM invoice_payments p WHERE p.invoice_id = i.id AND p.status = 'recorded';
    SELECT COALESCE(sum(ca.amount), 0) INTO cred FROM credit_applications ca JOIN credit_notes c ON c.id = ca.credit_note_id AND c.status = 'issued' WHERE ca.invoice_id = i.id;
    ns := i.status;
    IF i.status IN ('issued', 'sent', 'viewed', 'partially_paid', 'paid') THEN
        IF i.total - paid - cred <= 0 AND i.total > 0 THEN ns := 'paid';
        ELSIF paid + cred > 0 THEN ns := 'partially_paid';
        ELSIF i.viewed_at IS NOT NULL THEN ns := 'viewed';
        ELSIF i.sent_at IS NOT NULL THEN ns := 'sent';
        ELSE ns := 'issued';
        END IF;
    END IF;
    PERFORM set_config('ct.via_function', '1', true);
    UPDATE invoices SET subtotal = CASE WHEN status = 'draft' THEN s ELSE subtotal END,
                        tax_total = CASE WHEN status = 'draft' THEN t ELSE tax_total END,
                        total = CASE WHEN status = 'draft' THEN s + t ELSE total END,
                        amount_paid = paid, amount_credited = cred, status = ns,
                        paid_at = CASE WHEN ns = 'paid' THEN COALESCE(paid_at, now()) ELSE NULL END
     WHERE id = i.id RETURNING * INTO i;
    PERFORM set_config('ct.via_function', '', true);
    RETURN i;
END$$;

CREATE OR REPLACE FUNCTION ct_invoice_line_after() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM ct_invoice_recalculate(COALESCE(NEW.invoice_id, OLD.invoice_id));
    RETURN NULL;
END$$;
CREATE TRIGGER invoice_lines_after AFTER INSERT OR UPDATE OR DELETE ON invoice_lines FOR EACH ROW EXECUTE FUNCTION ct_invoice_line_after();

-- An item joins a line of its own invoice, in its invoice's draft; it must be approved, billable, of the same engagement.
CREATE OR REPLACE FUNCTION ct_invoice_line_item_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE i invoices%ROWTYPE; l invoice_lines%ROWTYPE; eng bigint; ok boolean;
BEGIN
    SELECT * INTO i FROM invoices WHERE id = NEW.invoice_id;
    SELECT * INTO l FROM invoice_lines WHERE id = NEW.line_id;
    IF l.invoice_id <> NEW.invoice_id THEN RAISE EXCEPTION 'The line belongs to another invoice'; END IF;
    IF i.status <> 'draft' THEN RAISE EXCEPTION 'Items join a draft invoice only'; END IF;
    IF NEW.time_entry_id IS NOT NULL THEN
        SELECT engagement_id, status = 'approved' AND billable AND bill_rate IS NOT NULL INTO eng, ok FROM time_entries WHERE id = NEW.time_entry_id;
        IF NOT COALESCE(ok, false) THEN RAISE EXCEPTION 'Only an approved, billable, priced time entry is invoiced'; END IF;
    ELSIF NEW.expense_id IS NOT NULL THEN
        SELECT engagement_id, status = 'approved' AND billable INTO eng, ok FROM expenses WHERE id = NEW.expense_id;
        IF NOT COALESCE(ok, false) THEN RAISE EXCEPTION 'Only an approved, billable expense is invoiced'; END IF;
    ELSIF NEW.usage_charge_id IS NOT NULL THEN
        SELECT engagement_id, status = 'approved' AND billable INTO eng, ok FROM usage_charges WHERE id = NEW.usage_charge_id;
        IF NOT COALESCE(ok, false) THEN RAISE EXCEPTION 'Only an approved, billable usage charge is invoiced'; END IF;
    ELSE
        SELECT engagement_id, status = 'accepted' AND amount > 0 INTO eng, ok FROM milestones WHERE id = NEW.milestone_id;
        IF NOT COALESCE(ok, false) THEN RAISE EXCEPTION 'Only an accepted milestone with an amount is invoiced'; END IF;
    END IF;
    IF eng <> i.engagement_id THEN RAISE EXCEPTION 'That item belongs to another engagement'; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER invoice_line_items_guard BEFORE INSERT ON invoice_line_items FOR EACH ROW EXECUTE FUNCTION ct_invoice_line_item_guard();

CREATE OR REPLACE FUNCTION ct_invoice_line_item_no_update() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'A backing item is never changed; remove it from the draft and add it again';
END$$;
CREATE TRIGGER invoice_line_items_no_update BEFORE UPDATE ON invoice_line_items FOR EACH ROW EXECUTE FUNCTION ct_invoice_line_item_no_update();

CREATE OR REPLACE FUNCTION ct_invoice_line_item_delete_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE st text;
BEGIN
    SELECT status INTO st FROM invoices WHERE id = OLD.invoice_id;
    IF st IS NOT NULL AND st <> 'draft' AND NOT ct_via_function() THEN
        RAISE EXCEPTION 'An issued invoice keeps its backing items; void it to release them';
    END IF;
    RETURN OLD;
END$$;
CREATE TRIGGER invoice_line_items_delete_guard BEFORE DELETE ON invoice_line_items FOR EACH ROW EXECUTE FUNCTION ct_invoice_line_item_delete_guard();

-- An invoice's own head: a draft may change; an issued one keeps its client, engagement, dates, number and totals.
CREATE OR REPLACE FUNCTION ct_invoice_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE e engagements%ROWTYPE;
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'draft' THEN RAISE EXCEPTION 'An invoice is born a draft and issued afterwards'; END IF;
        NEW.number := NULL;
        SELECT * INTO e FROM engagements WHERE id = NEW.engagement_id;
        IF e.id IS NULL THEN RAISE EXCEPTION 'No such engagement'; END IF;
        IF e.client_id <> NEW.client_id THEN RAISE EXCEPTION 'Engagement % belongs to another client', e.number; END IF;
        NEW.currency := e.currency;
        NEW.department_id := COALESCE(NEW.department_id, e.department_id);
        NEW.po_number := COALESCE(NEW.po_number, e.po_number);
        RETURN NEW;
    END IF;
    IF OLD.status IN ('void', 'written_off') THEN
        IF row(NEW.last_reminder_at, NEW.reminder_count, NEW.viewed_at, NEW.external_ref, NEW.internal_note) IS DISTINCT FROM row(OLD.last_reminder_at, OLD.reminder_count, OLD.viewed_at, OLD.external_ref, OLD.internal_note)
           AND NEW.status = OLD.status THEN RETURN NEW; END IF;
        RAISE EXCEPTION 'Invoice % is % and cannot be changed', OLD.number, OLD.status;
    END IF;
    IF OLD.status <> 'draft' THEN
        IF row(NEW.client_id, NEW.engagement_id, NEW.issue_date, NEW.due_date, NEW.currency, NEW.subtotal, NEW.tax_total, NEW.total, NEW.number, NEW.period_start, NEW.period_end)
           IS DISTINCT FROM row(OLD.client_id, OLD.engagement_id, OLD.issue_date, OLD.due_date, OLD.currency, OLD.subtotal, OLD.tax_total, OLD.total, OLD.number, OLD.period_start, OLD.period_end) THEN
            RAISE EXCEPTION 'Invoice % is issued: its client, engagement, dates, number and totals cannot change (credit it, or void it)', OLD.number;
        END IF;
        IF NEW.status = 'draft' THEN RAISE EXCEPTION 'Invoice % is issued and cannot return to draft', OLD.number; END IF;
        IF NEW.status IN ('paid', 'partially_paid') AND NEW.status <> OLD.status AND NOT ct_via_function() THEN
            RAISE EXCEPTION 'Paid is derived from the payments recorded, never typed';
        END IF;
    ELSE
        IF NEW.status NOT IN ('draft') AND NOT ct_via_function() THEN RAISE EXCEPTION 'An invoice is issued through its function, never by setting the status'; END IF;
        IF NEW.engagement_id <> OLD.engagement_id THEN RAISE EXCEPTION 'A draft keeps its engagement (delete it and draft another)'; END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER invoices_guard BEFORE INSERT OR UPDATE ON invoices FOR EACH ROW EXECUTE FUNCTION ct_invoice_guard();

CREATE OR REPLACE FUNCTION ct_invoice_no_delete() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status <> 'draft' THEN RAISE EXCEPTION 'Invoice % is issued and is never deleted; void it', OLD.number; END IF;
    RETURN OLD;
END$$;
CREATE TRIGGER invoices_no_delete BEFORE DELETE ON invoices FOR EACH ROW EXECUTE FUNCTION ct_invoice_no_delete();

-- Credit note lines and head: the same shape.
CREATE OR REPLACE FUNCTION ct_credit_note_line_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE st text; m record;
BEGIN
    SELECT status INTO st FROM credit_notes WHERE id = COALESCE(NEW.credit_note_id, OLD.credit_note_id);
    IF st IS DISTINCT FROM 'draft' THEN RAISE EXCEPTION 'The lines of a credit note change only while it is a draft (it is %)', st; END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    IF NEW.tax_rate_id IS NOT NULL THEN SELECT rate INTO NEW.tax_rate FROM tax_rates WHERE id = NEW.tax_rate_id; ELSE NEW.tax_rate := 0; END IF;
    SELECT * INTO m FROM ct_line_money(NEW.quantity, NEW.unit_price, NEW.tax_rate);
    NEW.line_subtotal := m.line_subtotal; NEW.line_tax := m.line_tax; NEW.line_total := m.line_total;
    RETURN NEW;
END$$;
CREATE TRIGGER credit_note_lines_before BEFORE INSERT OR UPDATE OR DELETE ON credit_note_lines FOR EACH ROW EXECUTE FUNCTION ct_credit_note_line_before();

CREATE OR REPLACE FUNCTION ct_credit_note_recalculate(p_id bigint) RETURNS credit_notes
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c credit_notes%ROWTYPE; s numeric(14,2); t numeric(14,2); ap numeric(14,2);
BEGIN
    SELECT * INTO c FROM credit_notes WHERE id = p_id FOR UPDATE;
    IF c.id IS NULL THEN RAISE EXCEPTION 'No such credit note'; END IF;
    SELECT COALESCE(sum(line_subtotal), 0), COALESCE(sum(line_tax), 0) INTO s, t FROM credit_note_lines WHERE credit_note_id = c.id;
    SELECT COALESCE(sum(amount), 0) INTO ap FROM credit_applications WHERE credit_note_id = c.id;
    UPDATE credit_notes SET subtotal = CASE WHEN status = 'draft' THEN s ELSE subtotal END,
                            tax_total = CASE WHEN status = 'draft' THEN t ELSE tax_total END,
                            total = CASE WHEN status = 'draft' THEN s + t ELSE total END,
                            amount_applied = ap
     WHERE id = c.id RETURNING * INTO c;
    RETURN c;
END$$;

CREATE OR REPLACE FUNCTION ct_credit_note_line_after() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM ct_credit_note_recalculate(COALESCE(NEW.credit_note_id, OLD.credit_note_id));
    RETURN NULL;
END$$;
CREATE TRIGGER credit_note_lines_after AFTER INSERT OR UPDATE OR DELETE ON credit_note_lines FOR EACH ROW EXECUTE FUNCTION ct_credit_note_line_after();

CREATE OR REPLACE FUNCTION ct_credit_note_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'draft' THEN RAISE EXCEPTION 'A credit note is born a draft and issued afterwards'; END IF;
        NEW.number := NULL;
        IF NEW.invoice_id IS NOT NULL THEN
            SELECT client_id, engagement_id, currency INTO NEW.client_id, NEW.engagement_id, NEW.currency FROM invoices WHERE id = NEW.invoice_id;
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.status = 'void' THEN RAISE EXCEPTION 'Credit note % is void and cannot be changed', OLD.number; END IF;
    IF OLD.status = 'issued' THEN
        IF row(NEW.client_id, NEW.issue_date, NEW.currency, NEW.subtotal, NEW.tax_total, NEW.total, NEW.number)
           IS DISTINCT FROM row(OLD.client_id, OLD.issue_date, OLD.currency, OLD.subtotal, OLD.tax_total, OLD.total, OLD.number) THEN
            RAISE EXCEPTION 'Credit note % is issued and cannot change', OLD.number;
        END IF;
        IF NEW.status = 'draft' THEN RAISE EXCEPTION 'Credit note % is issued and cannot return to draft', OLD.number; END IF;
        IF NEW.status = 'void' AND OLD.amount_applied > 0 THEN RAISE EXCEPTION 'Credit note % is applied to an invoice; unapply it first', OLD.number; END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER credit_notes_guard BEFORE INSERT OR UPDATE ON credit_notes FOR EACH ROW EXECUTE FUNCTION ct_credit_note_guard();

-- Applications and payments never exceed what is owed.
CREATE OR REPLACE FUNCTION ct_credit_application_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE c credit_notes%ROWTYPE; i invoices%ROWTYPE; applied numeric(14,2); owed numeric(14,2);
BEGIN
    SELECT * INTO c FROM credit_notes WHERE id = NEW.credit_note_id;
    SELECT * INTO i FROM invoices WHERE id = NEW.invoice_id;
    IF c.status <> 'issued' THEN RAISE EXCEPTION 'Credit note % is %, not issued', COALESCE(c.number, c.id::text), c.status; END IF;
    IF i.status NOT IN ('issued', 'sent', 'viewed', 'partially_paid') THEN RAISE EXCEPTION 'Invoice % is % and takes no credit', COALESCE(i.number, i.id::text), i.status; END IF;
    IF c.client_id <> i.client_id THEN RAISE EXCEPTION 'Credit note % belongs to another client than invoice %', c.number, i.number; END IF;
    IF c.currency <> i.currency THEN RAISE EXCEPTION 'Credit note % and invoice % are in different currencies', c.number, i.number; END IF;
    SELECT COALESCE(sum(amount), 0) INTO applied FROM credit_applications WHERE credit_note_id = NEW.credit_note_id AND invoice_id <> NEW.invoice_id;
    IF applied + NEW.amount > c.total THEN RAISE EXCEPTION 'Credit note % is %; applying % more would exceed it', c.number, c.total, NEW.amount; END IF;
    SELECT COALESCE(sum(amount), 0) INTO applied FROM credit_applications WHERE invoice_id = NEW.invoice_id AND credit_note_id <> NEW.credit_note_id;
    owed := i.total - i.amount_paid - applied;
    IF NEW.amount > owed THEN RAISE EXCEPTION 'Invoice % is owed %; crediting % would exceed it', i.number, owed, NEW.amount; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER credit_applications_guard BEFORE INSERT OR UPDATE ON credit_applications FOR EACH ROW EXECUTE FUNCTION ct_credit_application_guard();

CREATE OR REPLACE FUNCTION ct_credit_application_after() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM ct_invoice_recalculate(COALESCE(NEW.invoice_id, OLD.invoice_id));
    PERFORM ct_credit_note_recalculate(COALESCE(NEW.credit_note_id, OLD.credit_note_id));
    RETURN NULL;
END$$;
CREATE TRIGGER credit_applications_after AFTER INSERT OR UPDATE OR DELETE ON credit_applications FOR EACH ROW EXECUTE FUNCTION ct_credit_application_after();

CREATE OR REPLACE FUNCTION ct_invoice_payment_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE i invoices%ROWTYPE; applied numeric(14,2);
BEGIN
    SELECT * INTO i FROM invoices WHERE id = NEW.invoice_id;
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'recorded' THEN RAISE EXCEPTION 'A payment is recorded when it is made'; END IF;
        IF i.status NOT IN ('issued', 'sent', 'viewed', 'partially_paid') THEN RAISE EXCEPTION 'Invoice % is % and takes no payment', COALESCE(i.number, i.id::text), i.status; END IF;
        IF NEW.currency <> i.currency THEN RAISE EXCEPTION 'A payment is recorded in the invoice''s currency (%)', i.currency; END IF;
        SELECT COALESCE(sum(amount), 0) INTO applied FROM invoice_payments WHERE invoice_id = NEW.invoice_id AND status = 'recorded';
        IF applied + NEW.amount > i.total - i.amount_credited THEN RAISE EXCEPTION 'Invoice % is owed %; recording % would overpay it', i.number, i.total - i.amount_credited - applied, NEW.amount; END IF;
        RETURN NEW;
    END IF;
    IF OLD.status = 'void' THEN RAISE EXCEPTION 'A void payment cannot change'; END IF;
    IF NEW.status = 'void' THEN NEW.voided_at := COALESCE(NEW.voided_at, now()); RETURN NEW; END IF;
    IF row(NEW.invoice_id, NEW.amount, NEW.currency, NEW.paid_on) IS DISTINCT FROM row(OLD.invoice_id, OLD.amount, OLD.currency, OLD.paid_on) THEN
        RAISE EXCEPTION 'A recorded payment is not edited: void it and record it again';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER invoice_payments_guard BEFORE INSERT OR UPDATE ON invoice_payments FOR EACH ROW EXECUTE FUNCTION ct_invoice_payment_guard();

CREATE OR REPLACE FUNCTION ct_invoice_payment_after() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM ct_invoice_recalculate(COALESCE(NEW.invoice_id, OLD.invoice_id));
    RETURN NULL;
END$$;
CREATE TRIGGER invoice_payments_after AFTER INSERT OR UPDATE ON invoice_payments FOR EACH ROW EXECUTE FUNCTION ct_invoice_payment_after();

CREATE OR REPLACE FUNCTION ct_invoice_payment_no_delete() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'A payment is never deleted; void it';
END$$;
CREATE TRIGGER invoice_payments_no_delete BEFORE DELETE ON invoice_payments FOR EACH ROW EXECUTE FUNCTION ct_invoice_payment_no_delete();

-- ---------------------------------------------------------------------------------------------
-- The secure link.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_secure_link_mint(p_invoice_id bigint) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE raw text;
BEGIN
    raw := encode(gen_random_bytes(24), 'hex');
    UPDATE invoice_links_secure SET rotated_at = now() WHERE invoice_id = p_invoice_id AND rotated_at IS NULL;
    INSERT INTO invoice_links_secure (invoice_id, token_hash) VALUES (p_invoice_id, encode(sha256(raw::bytea), 'hex'));
    RETURN raw;
END$$;

-- The invoice a presented token opens: live, not rotated, not expired; counts the view and stamps the invoice as viewed.
CREATE OR REPLACE FUNCTION ct_secure_link_invoice(p_raw text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE iid bigint;
BEGIN
    IF p_raw !~ '^[a-f0-9]{48}$' THEN RETURN NULL; END IF;
    UPDATE invoice_links_secure SET last_used_at = now(), view_count = view_count + 1
     WHERE token_hash = encode(sha256(p_raw::bytea), 'hex') AND rotated_at IS NULL AND (expires_at IS NULL OR expires_at > now())
    RETURNING invoice_id INTO iid;
    IF iid IS NOT NULL THEN
        PERFORM set_config('ct.via_function', '1', true);
        UPDATE invoices SET viewed_at = COALESCE(viewed_at, now()), status = CASE WHEN status IN ('issued', 'sent') THEN 'viewed' ELSE status END WHERE id = iid;
        PERFORM set_config('ct.via_function', '', true);
    END IF;
    RETURN iid;
END$$;

-- ---------------------------------------------------------------------------------------------
-- The writer's verbs.
-- ---------------------------------------------------------------------------------------------
-- Pull the engagement's approved, billable, unbilled items through its grouping into draft lines with their backing
-- items. Time with NO rate is skipped and counted (never invoiced at zero — D6). Returns the counts.
CREATE OR REPLACE FUNCTION ct_invoice_add_unbilled(p_invoice_id bigint, p_by bigint, p_through date DEFAULT current_date,
                                                   OUT time_lines integer, OUT time_entries_billed integer, OUT time_no_rate integer,
                                                   OUT expense_lines integer, OUT usage_lines integer, OUT milestone_lines integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE i invoices%ROWTYPE; e engagements%ROWTYPE; grouping text; g record; lid bigint; n integer; r record; tax bigint;
BEGIN
    SELECT * INTO i FROM invoices WHERE id = p_invoice_id FOR UPDATE;
    IF i.id IS NULL THEN RAISE EXCEPTION 'No such invoice'; END IF;
    IF i.status <> 'draft' THEN RAISE EXCEPTION 'Invoice % is issued; draft another', i.number; END IF;
    SELECT * INTO e FROM engagements WHERE id = i.engagement_id;
    grouping := COALESCE(e.time_grouping, (SELECT default_time_grouping FROM ct_settings WHERE id = 1));
    SELECT id INTO tax FROM tax_rates WHERE is_default AND archived_at IS NULL;
    SELECT COALESCE(max(sort_order), 0) INTO n FROM invoice_lines WHERE invoice_id = i.id;
    time_lines := 0; time_entries_billed := 0; expense_lines := 0; usage_lines := 0; milestone_lines := 0;
    SELECT count(*) INTO time_no_rate FROM time_entries t WHERE t.engagement_id = e.id AND t.status = 'approved' AND t.billable AND t.bill_rate IS NULL
       AND t.entry_date <= p_through AND NOT ct_item_billed('time_entry', t.id);
    -- time, grouped
    FOR g IN SELECT CASE grouping WHEN 'by_person' THEN t.member_id::text WHEN 'by_role' THEN COALESCE(t.consulting_role_id, 0)::text
                                 WHEN 'by_activity' THEN COALESCE(t.activity_type_id, 0)::text WHEN 'by_day' THEN t.entry_date::text ELSE 'all' END AS gk,
                    CASE grouping WHEN 'by_person' THEN (SELECT display_name FROM members WHERE id = t.member_id)
                                  WHEN 'by_role' THEN COALESCE((SELECT title FROM consulting_roles WHERE id = t.consulting_role_id), 'Consulting')
                                  WHEN 'by_activity' THEN COALESCE((SELECT label FROM activity_types WHERE id = t.activity_type_id), 'Consulting')
                                  WHEN 'by_day' THEN to_char(t.entry_date, 'Mon DD, YYYY') ELSE 'Professional services' END AS label,
                    t.bill_rate, sum(t.hours) AS hours, array_agg(t.id ORDER BY t.entry_date, t.id) AS ids
               FROM time_entries t
              WHERE t.engagement_id = e.id AND t.status = 'approved' AND t.billable AND t.bill_rate IS NOT NULL AND t.entry_date <= p_through
                AND NOT ct_item_billed('time_entry', t.id)
              GROUP BY 1, 2, t.bill_rate ORDER BY 1, t.bill_rate LOOP
        n := n + 1;
        INSERT INTO invoice_lines (invoice_id, sort_order, kind, description, quantity, unit, unit_price, tax_rate_id, group_key)
        VALUES (i.id, n, 'time', g.label || ' — consulting', g.hours, 'hours', g.bill_rate, NULL, g.gk) RETURNING id INTO lid;
        INSERT INTO invoice_line_items (invoice_id, line_id, time_entry_id, amount)
        SELECT i.id, lid, t.id, round(t.hours * t.bill_rate, 2) FROM time_entries t WHERE t.id = ANY (g.ids);
        time_lines := time_lines + 1; time_entries_billed := time_entries_billed + array_length(g.ids, 1);
    END LOOP;
    -- expenses, one line each (the receipt travels with it)
    FOR r IN SELECT x.* FROM expenses x WHERE x.engagement_id = e.id AND x.status = 'approved' AND x.billable AND x.expense_date <= p_through
                AND NOT ct_item_billed('expense', x.id) ORDER BY x.expense_date, x.id LOOP
        n := n + 1;
        INSERT INTO invoice_lines (invoice_id, sort_order, kind, description, quantity, unit, unit_price, tax_rate_id)
        VALUES (i.id, n, 'expense', (SELECT label FROM expense_categories WHERE id = r.category_id) || ' — ' || r.description || ' (' || to_char(r.expense_date, 'Mon DD') || ')',
                1, 'each', r.billable_amount, NULL) RETURNING id INTO lid;
        INSERT INTO invoice_line_items (invoice_id, line_id, expense_id, amount) VALUES (i.id, lid, r.id, r.billable_amount);
        expense_lines := expense_lines + 1;
    END LOOP;
    -- usage charges, one line each
    FOR r IN SELECT u.* FROM usage_charges u WHERE u.engagement_id = e.id AND u.status = 'approved' AND u.billable AND u.period_end <= p_through
                AND NOT ct_item_billed('usage_charge', u.id) ORDER BY u.period_start, u.id LOOP
        n := n + 1;
        INSERT INTO invoice_lines (invoice_id, sort_order, kind, description, quantity, unit, unit_price, tax_rate_id)
        VALUES (i.id, n, 'usage', r.description || ' (' || to_char(r.period_start, 'Mon DD') || '–' || to_char(r.period_end, 'Mon DD') || ')', 1, 'each', r.billable_amount, NULL) RETURNING id INTO lid;
        INSERT INTO invoice_line_items (invoice_id, line_id, usage_charge_id, amount) VALUES (i.id, lid, r.id, r.billable_amount);
        usage_lines := usage_lines + 1;
    END LOOP;
    -- accepted milestones
    FOR r IN SELECT m.* FROM milestones m WHERE m.engagement_id = e.id AND m.status = 'accepted' AND m.amount > 0 AND NOT ct_item_billed('milestone', m.id) ORDER BY m.sort_order, m.id LOOP
        n := n + 1;
        INSERT INTO invoice_lines (invoice_id, sort_order, kind, description, quantity, unit, unit_price, tax_rate_id)
        VALUES (i.id, n, 'milestone', 'Milestone: ' || r.name, 1, 'each', r.amount, NULL) RETURNING id INTO lid;
        INSERT INTO invoice_line_items (invoice_id, line_id, milestone_id, amount) VALUES (i.id, lid, r.id, r.amount);
        milestone_lines := milestone_lines + 1;
    END LOOP;
END$$;

-- Issue: the number (gapless), the PO rule, the NTE cap (an override needs a reason), the milestones marked.
CREATE OR REPLACE FUNCTION ct_invoice_issue(p_invoice_id bigint, p_by bigint, p_nte_override_reason text DEFAULT NULL) RETURNS invoices
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE i invoices%ROWTYPE; e engagements%ROWTYPE; c clients%ROWTYPE; issued_total numeric(14,2);
BEGIN
    SELECT * INTO i FROM invoices WHERE id = p_invoice_id FOR UPDATE;
    IF i.id IS NULL THEN RAISE EXCEPTION 'No such invoice'; END IF;
    IF i.status <> 'draft' THEN RAISE EXCEPTION 'Invoice % is already issued', i.number; END IF;
    IF NOT EXISTS (SELECT 1 FROM invoice_lines WHERE invoice_id = i.id) THEN RAISE EXCEPTION 'An invoice needs at least one line'; END IF;
    IF i.total <= 0 THEN RAISE EXCEPTION 'An invoice for nothing is not issued'; END IF;
    SELECT * INTO e FROM engagements WHERE id = i.engagement_id;
    SELECT * INTO c FROM clients WHERE id = i.client_id;
    IF c.po_required AND COALESCE(btrim(i.po_number), '') = '' THEN RAISE EXCEPTION '% requires a PO number on every invoice', c.name; END IF;
    IF e.kind = 'time_and_materials' AND e.nte_amount IS NOT NULL THEN
        SELECT COALESCE(sum(total), 0) INTO issued_total FROM invoices WHERE engagement_id = e.id AND status NOT IN ('draft', 'void');
        IF issued_total + i.total > e.nte_amount THEN
            IF COALESCE(btrim(p_nte_override_reason), '') = '' THEN
                RAISE EXCEPTION 'Issuing this % would take % to % against its not-to-exceed of %; billing may override with a reason',
                    i.total, e.number, issued_total + i.total, e.nte_amount;
            END IF;
            IF NOT (SELECT ct_has_right('billing.write')) THEN RAISE EXCEPTION 'Only Billing overrides a not-to-exceed cap'; END IF;
        END IF;
    END IF;
    PERFORM set_config('ct.via_function', '1', true);
    UPDATE invoices SET number = next_document_number('invoice'), status = 'issued', issued_at = now(), issued_by = p_by,
                        nte_override_reason = NULLIF(btrim(COALESCE(p_nte_override_reason, '')), '')
     WHERE id = i.id RETURNING * INTO i;
    UPDATE milestones SET status = 'invoiced' WHERE id IN (SELECT milestone_id FROM invoice_line_items WHERE invoice_id = i.id AND milestone_id IS NOT NULL);
    PERFORM set_config('ct.via_function', '', true);
    RETURN i;
END$$;

CREATE OR REPLACE FUNCTION ct_invoice_mark_sent(p_invoice_id bigint) RETURNS invoices
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE i invoices%ROWTYPE;
BEGIN
    SELECT * INTO i FROM invoices WHERE id = p_invoice_id FOR UPDATE;
    IF i.id IS NULL THEN RAISE EXCEPTION 'No such invoice'; END IF;
    IF i.status = 'draft' THEN RAISE EXCEPTION 'Issue the invoice before sending it'; END IF;
    PERFORM set_config('ct.via_function', '1', true);
    UPDATE invoices SET sent_at = COALESCE(sent_at, now()), status = CASE WHEN status = 'issued' THEN 'sent' ELSE status END WHERE id = i.id RETURNING * INTO i;
    PERFORM set_config('ct.via_function', '', true);
    RETURN i;
END$$;

-- Void: nothing paid or credited; the backing items are RELEASED (unbilled again); milestones back to accepted.
CREATE OR REPLACE FUNCTION ct_invoice_void(p_invoice_id bigint, p_by bigint, p_reason text) RETURNS invoices
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE i invoices%ROWTYPE;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'Voiding an invoice needs a reason'; END IF;
    SELECT * INTO i FROM invoices WHERE id = p_invoice_id FOR UPDATE;
    IF i.id IS NULL THEN RAISE EXCEPTION 'No such invoice'; END IF;
    IF i.status IN ('void', 'written_off') THEN RAISE EXCEPTION 'Invoice % is already %', i.number, i.status; END IF;
    IF i.status = 'draft' THEN RAISE EXCEPTION 'A draft is deleted, not voided'; END IF;
    IF i.amount_paid > 0 OR i.amount_credited > 0 THEN RAISE EXCEPTION 'Invoice % has payments or credits applied; void or unapply them first', i.number; END IF;
    PERFORM set_config('ct.via_function', '1', true);
    UPDATE milestones SET status = 'accepted' WHERE id IN (SELECT milestone_id FROM invoice_line_items WHERE invoice_id = i.id AND milestone_id IS NOT NULL);
    DELETE FROM invoice_line_items WHERE invoice_id = i.id;
    UPDATE invoices SET status = 'void', voided_at = now(), voided_by = p_by, void_reason = p_reason WHERE id = i.id RETURNING * INTO i;
    PERFORM set_config('ct.via_function', '', true);
    RETURN i;
END$$;

-- Write off the balance (the items stay billed; realization shows the loss).
CREATE OR REPLACE FUNCTION ct_invoice_write_off(p_invoice_id bigint, p_by bigint, p_reason text) RETURNS invoices
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE i invoices%ROWTYPE;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'A write-off needs a reason'; END IF;
    SELECT * INTO i FROM invoices WHERE id = p_invoice_id FOR UPDATE;
    IF i.id IS NULL THEN RAISE EXCEPTION 'No such invoice'; END IF;
    IF i.status NOT IN ('issued', 'sent', 'viewed', 'partially_paid') THEN RAISE EXCEPTION 'Invoice % is % and is not written off', i.number, i.status; END IF;
    IF i.balance_due <= 0 THEN RAISE EXCEPTION 'Invoice % is owed nothing', i.number; END IF;
    PERFORM set_config('ct.via_function', '1', true);
    UPDATE invoices SET status = 'written_off', written_off_at = now(), written_off_by = p_by, write_off_reason = p_reason WHERE id = i.id RETURNING * INTO i;
    PERFORM set_config('ct.via_function', '', true);
    RETURN i;
END$$;

CREATE OR REPLACE FUNCTION ct_credit_note_issue(p_id bigint, p_by bigint) RETURNS credit_notes
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c credit_notes%ROWTYPE;
BEGIN
    SELECT * INTO c FROM credit_notes WHERE id = p_id FOR UPDATE;
    IF c.id IS NULL THEN RAISE EXCEPTION 'No such credit note'; END IF;
    IF c.status <> 'draft' THEN RAISE EXCEPTION 'Credit note % is already issued', c.number; END IF;
    IF c.total <= 0 THEN RAISE EXCEPTION 'A credit note for nothing is not issued'; END IF;
    UPDATE credit_notes SET number = next_document_number('credit_note'), status = 'issued', issued_at = now(), issued_by = p_by WHERE id = c.id RETURNING * INTO c;
    RETURN c;
END$$;

-- A claim confirmed becomes a payment (source claim); dismissed is a decision too.
CREATE OR REPLACE FUNCTION ct_payment_claim_confirm(p_claim_id bigint, p_by bigint, p_amount numeric DEFAULT NULL) RETURNS invoice_payments
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE k payment_claims%ROWTYPE; i invoices%ROWTYPE; p invoice_payments%ROWTYPE;
BEGIN
    SELECT * INTO k FROM payment_claims WHERE id = p_claim_id FOR UPDATE;
    IF k.id IS NULL THEN RAISE EXCEPTION 'No such claim'; END IF;
    IF k.status <> 'submitted' THEN RAISE EXCEPTION 'The claim is already %', k.status; END IF;
    SELECT * INTO i FROM invoices WHERE id = k.invoice_id;
    INSERT INTO invoice_payments (invoice_id, paid_on, amount, currency, method, reference, source, recorded_by)
    VALUES (i.id, k.claimed_on, COALESCE(p_amount, k.amount, i.balance_due), i.currency, k.method, k.reference, 'claim', p_by) RETURNING * INTO p;
    UPDATE payment_claims SET status = 'confirmed', payment_id = p.id, decided_by = p_by, decided_at = now() WHERE id = k.id;
    RETURN p;
END$$;

REVOKE ALL ON FUNCTION ct_invoice_recalculate(bigint), ct_credit_note_recalculate(bigint), ct_secure_link_mint(bigint), ct_secure_link_invoice(text),
    ct_invoice_add_unbilled(bigint, bigint, date), ct_invoice_issue(bigint, bigint, text), ct_invoice_mark_sent(bigint), ct_invoice_void(bigint, bigint, text),
    ct_invoice_write_off(bigint, bigint, text), ct_credit_note_issue(bigint, bigint), ct_payment_claim_confirm(bigint, bigint, numeric) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ct_invoice_recalculate(bigint), ct_credit_note_recalculate(bigint), ct_secure_link_mint(bigint), ct_secure_link_invoice(text),
    ct_invoice_add_unbilled(bigint, bigint, date), ct_invoice_issue(bigint, bigint, text), ct_invoice_mark_sent(bigint), ct_invoice_void(bigint, bigint, text),
    ct_invoice_write_off(bigint, bigint, text), ct_credit_note_issue(bigint, bigint), ct_payment_claim_confirm(bigint, bigint, numeric) TO consultant_tracking_rw;
GRANT SELECT, INSERT, UPDATE, DELETE ON invoices, invoice_lines, invoice_line_items, credit_notes, credit_note_lines, credit_applications, invoice_payments,
    payment_claims, invoice_links_secure TO consultant_tracking_rw;

COMMIT;
