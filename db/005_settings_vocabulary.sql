-- 005: the firm's SETTINGS (one row), DOCUMENT NUMBERING (D13), and the VOCABULARY the records are made of: consulting
-- roles (titles a rate is priced by), activity types (what the hours were), expense categories (with their receipt and
-- markup rules), usage providers (the kernel's provider keys), tax rates.
--
-- Numbering is a TABLE updated transactionally, not a SEQUENCE (General Ledger's rule, kept): a rolled-back issue returns
-- the number, which is what makes an invoice's sequence gapless. Invoices and credit notes take their number on ISSUE;
-- engagements, expenses and usage charges at creation (an internal reference, a hole explains nothing). Prefixes are settings.
BEGIN;

CREATE TABLE ct_settings (
    id                          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    firm_name                   text,                                             -- on the invoice header
    firm_address                text,
    firm_tax_id                 text,
    base_currency               char(3) NOT NULL DEFAULT 'USD' CHECK (base_currency ~ '^[A-Z]{3}$'),   -- costs are kept in it
    timezone                    text NOT NULL DEFAULT 'UTC',
    week_start_dow              smallint NOT NULL DEFAULT 1 CHECK (week_start_dow BETWEEN 0 AND 6),    -- 1 = Monday (D6); ISO dow
    hours_increment             numeric(4,2) NOT NULL DEFAULT 0.25 CHECK (hours_increment IN (0.05, 0.10, 0.25, 0.50, 1.00)),
    max_hours_per_day           numeric(4,2) NOT NULL DEFAULT 16 CHECK (max_hours_per_day BETWEEN 1 AND 24),
    receipt_threshold           numeric(14,2) NOT NULL DEFAULT 75.00 CHECK (receipt_threshold >= 0),   -- D7: a receipt at or above it
    default_markup_pct          numeric(6,2) NOT NULL DEFAULT 0 CHECK (default_markup_pct BETWEEN 0 AND 500),   -- D7: pass-through
    default_terms_days          integer NOT NULL DEFAULT 30 CHECK (default_terms_days BETWEEN 0 AND 365),
    default_time_grouping       text NOT NULL DEFAULT 'by_person' CHECK (default_time_grouping IN ('by_person', 'by_role', 'by_activity', 'by_day', 'single_line')),
    share_receipts_default      boolean NOT NULL DEFAULT true,                    -- D9: receipts on the secure link
    link_lifetime_days          integer NOT NULL DEFAULT 180 CHECK (link_lifetime_days BETWEEN 1 AND 3650),   -- D9
    reminder_days               integer[] NOT NULL DEFAULT '{7,21,45}',           -- days past due a reminder is drafted
    budget_thresholds_pct       integer[] NOT NULL DEFAULT '{50,80,100}',         -- an alert at each crossing
    ai_budget_threshold_pct     integer NOT NULL DEFAULT 80 CHECK (ai_budget_threshold_pct BETWEEN 1 AND 100),   -- D8
    capacity_hours_per_week     numeric(5,2) NOT NULL DEFAULT 40 CHECK (capacity_hours_per_week BETWEEN 1 AND 168),
    ai_statement_poll           boolean NOT NULL DEFAULT true,                    -- the worker pulls the kernel's closed months
    morning_note_member_id      bigint REFERENCES members(id) ON DELETE SET NULL, -- who receives the Billing Clerk's note (D11)
    missing_time_nudge_dow      smallint NOT NULL DEFAULT 1 CHECK (missing_time_nudge_dow BETWEEN 0 AND 6),   -- Monday
    invoice_footer              text,
    updated_at                  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO ct_settings (id) VALUES (1) ON CONFLICT DO NOTHING;
CREATE TRIGGER ct_settings_touch BEFORE UPDATE ON ct_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION ct_base_currency() RETURNS char(3)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT base_currency FROM ct_settings WHERE id = 1 $$;

-- The Monday (the settings' week start) of the week a date falls in.
CREATE OR REPLACE FUNCTION ct_week_start(p_date date) RETURNS date
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT (p_date - ((EXTRACT(ISODOW FROM p_date)::int - (SELECT CASE week_start_dow WHEN 0 THEN 7 ELSE week_start_dow END FROM ct_settings WHERE id = 1) + 7) % 7))::date;
$$;

-- ---------------------------------------------------------------------------------------------
-- Numbering
-- ---------------------------------------------------------------------------------------------
CREATE TABLE document_sequences (
    kind        text PRIMARY KEY CHECK (kind IN ('engagement', 'invoice', 'credit_note', 'expense', 'usage_charge')),
    prefix      text NOT NULL CHECK (prefix ~ '^[A-Z][A-Z0-9]{0,7}-?$'),
    next_value  bigint NOT NULL DEFAULT 1 CHECK (next_value > 0),
    padding     smallint NOT NULL DEFAULT 5 CHECK (padding BETWEEN 1 AND 12),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO document_sequences (kind, prefix, padding) VALUES
    ('engagement', 'ENG-', 4), ('invoice', 'INV-', 5), ('credit_note', 'CN-', 5), ('expense', 'EXP-', 6), ('usage_charge', 'USG-', 6)
ON CONFLICT DO NOTHING;

-- Row lock via UPDATE ... RETURNING: concurrent callers serialize; a rolled-back caller returns the number.
CREATE OR REPLACE FUNCTION next_document_number(p_kind text) RETURNS text
    LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
    UPDATE document_sequences
       SET next_value = next_value + 1, updated_at = now()
     WHERE kind = p_kind
    RETURNING prefix || lpad((next_value - 1)::text, padding, '0');
$$;
REVOKE ALL ON FUNCTION next_document_number(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION next_document_number(text) TO consultant_tracking_rw;

-- ---------------------------------------------------------------------------------------------
-- The vocabulary (seeded; renameable; archived, never deleted once used).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE consulting_roles (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key          text NOT NULL CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    title        text NOT NULL,
    description  text,
    sort_order   integer NOT NULL DEFAULT 0,
    archived_at  timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX consulting_roles_key_live ON consulting_roles (key) WHERE archived_at IS NULL;
CREATE TRIGGER consulting_roles_touch BEFORE UPDATE ON consulting_roles FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
INSERT INTO consulting_roles (key, title, sort_order) VALUES
 ('principal', 'Principal', 1), ('senior_consultant', 'Senior Consultant', 2), ('consultant', 'Consultant', 3),
 ('analyst', 'Analyst', 4), ('project_manager', 'Project Manager', 5);

CREATE TABLE activity_types (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key               text NOT NULL CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    label             text NOT NULL,
    billable_default  boolean NOT NULL DEFAULT true,
    sort_order        integer NOT NULL DEFAULT 0,
    archived_at       timestamptz,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX activity_types_key_live ON activity_types (key) WHERE archived_at IS NULL;
CREATE TRIGGER activity_types_touch BEFORE UPDATE ON activity_types FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
INSERT INTO activity_types (key, label, billable_default, sort_order) VALUES
 ('discovery', 'Discovery', true, 1), ('design', 'Design', true, 2), ('build', 'Build', true, 3), ('review', 'Review', true, 4),
 ('meeting', 'Meeting', true, 5), ('training', 'Training', true, 6), ('travel', 'Travel', false, 7), ('admin', 'Admin', false, 8);

CREATE TABLE expense_categories (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key                    text NOT NULL CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    label                  text NOT NULL,
    receipt_required_above numeric(14,2) CHECK (receipt_required_above >= 0),  -- NULL = the settings' threshold
    default_markup_pct     numeric(6,2) CHECK (default_markup_pct BETWEEN 0 AND 500),   -- NULL = the engagement's policy
    billable_default       boolean NOT NULL DEFAULT true,
    sort_order             integer NOT NULL DEFAULT 0,
    archived_at            timestamptz,
    created_at             timestamptz NOT NULL DEFAULT now(),
    updated_at             timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX expense_categories_key_live ON expense_categories (key) WHERE archived_at IS NULL;
CREATE TRIGGER expense_categories_touch BEFORE UPDATE ON expense_categories FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
INSERT INTO expense_categories (key, label, sort_order) VALUES
 ('travel', 'Travel', 1), ('lodging', 'Lodging', 2), ('meals', 'Meals', 3), ('software', 'Software', 4),
 ('hosting', 'Hosting', 5), ('model_usage', 'Model usage', 6), ('hardware', 'Hardware', 7), ('other', 'Other', 8);

CREATE TABLE usage_providers (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    provider_key  text NOT NULL CHECK (provider_key ~ '^[a-z][a-z0-9_]{0,39}$'),   -- matches the kernel's statement `provider`
    name          text NOT NULL,
    csv_mapping   jsonb,                                                            -- a saved column mapping for the provider's usage export (slice 5)
    sort_order    integer NOT NULL DEFAULT 0,
    archived_at   timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX usage_providers_key_live ON usage_providers (provider_key) WHERE archived_at IS NULL;
CREATE TRIGGER usage_providers_touch BEFORE UPDATE ON usage_providers FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
INSERT INTO usage_providers (provider_key, name, sort_order) VALUES
 ('anthropic', 'Anthropic', 1), ('openai', 'OpenAI', 2), ('google', 'Google', 3), ('aws', 'AWS', 4), ('azure', 'Azure', 5),
 ('gcp', 'Google Cloud', 6), ('vercel', 'Vercel', 7), ('other', 'Other', 99);

-- The provider for a kernel key, made on first sight (the statement allocation, db/011).
CREATE OR REPLACE FUNCTION ct_usage_provider(p_key text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE pid bigint; k text;
BEGIN
    k := lower(regexp_replace(COALESCE(p_key, 'other'), '[^A-Za-z0-9_]', '_', 'g'));
    IF k !~ '^[a-z]' THEN k := 'p_' || k; END IF;
    SELECT id INTO pid FROM usage_providers WHERE provider_key = k AND archived_at IS NULL;
    IF pid IS NULL THEN
        INSERT INTO usage_providers (provider_key, name, sort_order) VALUES (k, initcap(replace(k, '_', ' ')), 50) RETURNING id INTO pid;
    END IF;
    RETURN pid;
END$$;

CREATE TABLE tax_rates (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name         text NOT NULL,
    rate         numeric(7,4) NOT NULL CHECK (rate >= 0 AND rate < 100),
    is_default   boolean NOT NULL DEFAULT false,
    archived_at  timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX tax_rates_name_live ON tax_rates (lower(name)) WHERE archived_at IS NULL;
CREATE UNIQUE INDEX tax_rates_one_default ON tax_rates ((true)) WHERE is_default AND archived_at IS NULL;
CREATE TRIGGER tax_rates_touch BEFORE UPDATE ON tax_rates FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
INSERT INTO tax_rates (name, rate, is_default) VALUES ('No tax', 0, true);

-- A document line's money, rounded per line: subtotal = qty × price; tax = subtotal × rate / 100; total.
CREATE OR REPLACE FUNCTION ct_line_money(p_qty numeric, p_price numeric, p_rate numeric,
                                         OUT line_subtotal numeric, OUT line_tax numeric, OUT line_total numeric)
    LANGUAGE sql IMMUTABLE AS $$
    SELECT round(p_qty * p_price, 2),
           round(round(p_qty * p_price, 2) * COALESCE(p_rate, 0) / 100, 2),
           round(p_qty * p_price, 2) + round(round(p_qty * p_price, 2) * COALESCE(p_rate, 0) / 100, 2);
$$;

-- A bill-value from a cost and a pricing: cost, cost plus markup, or a resale price per unit (D8).
CREATE OR REPLACE FUNCTION ct_billable_amount(p_cost numeric, p_quantity numeric, p_pricing text, p_markup_pct numeric, p_resale_unit_price numeric, p_unit text)
    RETURNS numeric LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE p_pricing
             WHEN 'cost' THEN round(COALESCE(p_cost, 0), 2)
             WHEN 'markup' THEN round(COALESCE(p_cost, 0) * (1 + COALESCE(p_markup_pct, 0) / 100), 2)
             WHEN 'resale' THEN round(COALESCE(p_quantity, 0) * COALESCE(p_resale_unit_price, 0) / CASE WHEN p_unit = 'tokens' THEN 1000000 ELSE 1 END, 2)
             ELSE round(COALESCE(p_cost, 0), 2) END;
$$;

GRANT SELECT, UPDATE ON ct_settings TO consultant_tracking_rw;
GRANT SELECT, INSERT, UPDATE, DELETE ON document_sequences, consulting_roles, activity_types, expense_categories, usage_providers, tax_rates TO consultant_tracking_rw;
REVOKE ALL ON FUNCTION ct_usage_provider(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ct_usage_provider(text) TO consultant_tracking_rw;
GRANT EXECUTE ON FUNCTION ct_base_currency(), ct_week_start(date), ct_line_money(numeric, numeric, numeric), ct_billable_amount(numeric, numeric, text, numeric, numeric, text)
    TO consultant_tracking_rw, consultant_tracking_records_ro;

COMMIT;
