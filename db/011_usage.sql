-- 011: AI USAGE AND HOSTING (design §5, §6, §8; D8) — the costs that make AI consulting different, as billable items.
-- A USAGE CHARGE is a quantity of a thing (tokens, API calls, GB, VM-hours, months of hosting) at a cost, priced at cost,
-- cost plus markup, or a RESALE price per unit (per million tokens when the unit is tokens). It comes from four SOURCES:
-- by hand; a provider's CSV usage export (usage_imports); a HOSTING SCHEDULE (recurring_charges) falling due; and the
-- KERNEL'S CLOSED MONTHLY AI STATEMENT (os.ledger-period/1, kept VERBATIM in ai_statements/ai_statement_lines, as General
-- Ledger keeps it) ALLOCATED BY RULES — a rule names a kernel agent, department or application (any subset), an
-- engagement and a share; the unallocated remainder stays on the statement, shown, never billed. Rounded once per piece;
-- the remainder absorbs the cents, so pieces + remainder = the statement, always.
--
-- "Billed" is not a status: an invoice line item names the charge (db/012). Status moves only through the functions.
BEGIN;

CREATE TABLE usage_imports (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    provider_id  bigint NOT NULL REFERENCES usage_providers(id) ON DELETE RESTRICT,
    file_name    text NOT NULL,
    mapping      jsonb NOT NULL DEFAULT '{}'::jsonb,                          -- the column mapping used
    row_count    integer NOT NULL DEFAULT 0,
    inserted     integer NOT NULL DEFAULT 0,
    skipped      integer NOT NULL DEFAULT 0,
    detail       text,
    created_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX usage_imports_provider_idx ON usage_imports (provider_id, created_at DESC);
CREATE INDEX usage_imports_created_by_idx ON usage_imports (created_by);

CREATE TABLE ai_statements (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    period         text NOT NULL UNIQUE CHECK (period ~ '^[0-9]{4}-[0-9]{2}$'),   -- the kernel's period, '2026-09'
    period_start   date NOT NULL,
    period_end     date NOT NULL,
    kernel_status  text NOT NULL CHECK (kernel_status IN ('open', 'closed')),
    document       jsonb NOT NULL,                                               -- the kernel's document, as sent
    document_hash  text NOT NULL,
    pulled_at      timestamptz NOT NULL DEFAULT now(),
    status         text NOT NULL DEFAULT 'pulled' CHECK (status IN ('pulled', 'allocated')),
    allocated_at   timestamptz,
    allocated_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    late_calls     integer NOT NULL DEFAULT 0,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ai_statements_allocated_by_idx ON ai_statements (allocated_by);
CREATE TRIGGER ai_statements_touch BEFORE UPDATE ON ai_statements FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE ai_statement_lines (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    statement_id       bigint NOT NULL REFERENCES ai_statements(id) ON DELETE CASCADE,
    line_no            integer NOT NULL,
    provider           text NOT NULL,
    model_id           text,
    model              text,
    kernel_department_id bigint,                                                 -- the kernel's ids, as the document carries them
    department         text,
    kernel_agent_id    bigint,
    agent              text,
    kernel_application_id bigint,
    application        text,
    calls              integer NOT NULL DEFAULT 0,
    late_calls         integer NOT NULL DEFAULT 0,
    input_tokens       bigint NOT NULL DEFAULT 0,
    output_tokens      bigint NOT NULL DEFAULT 0,
    cache_read_tokens  bigint NOT NULL DEFAULT 0,
    cache_write_tokens bigint NOT NULL DEFAULT 0,
    amount             numeric(16,4) NOT NULL DEFAULT 0,
    currency           char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    note               text,
    allocated_amount   numeric(14,2) NOT NULL DEFAULT 0,                        -- the sum of its pieces, to the cent
    UNIQUE (statement_id, line_no)
);
CREATE INDEX ai_statement_lines_provider_idx ON ai_statement_lines (statement_id, provider, currency);
CREATE INDEX ai_statement_lines_agent_idx ON ai_statement_lines (kernel_agent_id);

CREATE TABLE allocation_rules (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name                  text NOT NULL,
    kernel_agent_id       bigint,                                                 -- any subset; a line matches when every named id equals
    kernel_department_id  bigint,
    kernel_application_id bigint,
    engagement_id         bigint NOT NULL REFERENCES engagements(id) ON DELETE CASCADE,
    share_pct             numeric(6,2) NOT NULL DEFAULT 100 CHECK (share_pct > 0 AND share_pct <= 100),
    pricing               text NOT NULL DEFAULT 'cost' CHECK (pricing IN ('cost', 'markup', 'resale')),
    markup_pct            numeric(6,2) NOT NULL DEFAULT 0 CHECK (markup_pct BETWEEN 0 AND 500),
    resale_unit_price     numeric(14,6) CHECK (resale_unit_price >= 0),          -- per million tokens
    valid_from            date NOT NULL,
    valid_to              date,
    active                boolean NOT NULL DEFAULT true,
    created_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    CHECK (kernel_agent_id IS NOT NULL OR kernel_department_id IS NOT NULL OR kernel_application_id IS NOT NULL),
    CHECK (valid_to IS NULL OR valid_to > valid_from),
    CHECK (pricing <> 'resale' OR resale_unit_price IS NOT NULL)
);
CREATE INDEX allocation_rules_engagement_idx ON allocation_rules (engagement_id);
CREATE INDEX allocation_rules_active_idx ON allocation_rules (valid_from, valid_to) WHERE active;
CREATE INDEX allocation_rules_created_by_idx ON allocation_rules (created_by);
CREATE TRIGGER allocation_rules_touch BEFORE UPDATE ON allocation_rules FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE recurring_charges (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    engagement_id      bigint NOT NULL REFERENCES engagements(id) ON DELETE CASCADE,
    kind               text NOT NULL CHECK (kind IN ('model_usage', 'api', 'hosting', 'storage', 'other')),
    provider_id        bigint REFERENCES usage_providers(id) ON DELETE SET NULL,
    description        text NOT NULL,
    quantity           numeric(18,4) NOT NULL DEFAULT 1 CHECK (quantity > 0),
    unit               text NOT NULL DEFAULT 'months' CHECK (unit IN ('tokens', 'calls', 'gb', 'gb_month', 'vm_hours', 'months', 'units')),
    unit_cost          numeric(14,6) NOT NULL CHECK (unit_cost >= 0),
    pricing            text NOT NULL DEFAULT 'cost' CHECK (pricing IN ('cost', 'markup', 'resale')),
    markup_pct         numeric(6,2) NOT NULL DEFAULT 0 CHECK (markup_pct BETWEEN 0 AND 500),
    resale_unit_price  numeric(14,6) CHECK (resale_unit_price >= 0),
    cadence            text NOT NULL DEFAULT 'monthly' CHECK (cadence IN ('weekly', 'monthly', 'quarterly', 'yearly')),
    every_n            integer NOT NULL DEFAULT 1 CHECK (every_n BETWEEN 1 AND 24),
    next_date          date NOT NULL,
    end_date           date,
    last_run_at        timestamptz,
    run_count          integer NOT NULL DEFAULT 0,
    active             boolean NOT NULL DEFAULT true,
    created_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (end_date IS NULL OR end_date >= next_date),
    CHECK (pricing <> 'resale' OR resale_unit_price IS NOT NULL)
);
CREATE INDEX recurring_charges_engagement_idx ON recurring_charges (engagement_id);
CREATE INDEX recurring_charges_due_idx ON recurring_charges (next_date) WHERE active;
CREATE INDEX recurring_charges_provider_idx ON recurring_charges (provider_id);
CREATE INDEX recurring_charges_created_by_idx ON recurring_charges (created_by);
CREATE TRIGGER recurring_charges_touch BEFORE UPDATE ON recurring_charges FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE usage_charges (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number             text NOT NULL UNIQUE,                                     -- USG-000001 at creation (D13)
    engagement_id      bigint NOT NULL REFERENCES engagements(id) ON DELETE RESTRICT,
    period_start       date NOT NULL,
    period_end         date NOT NULL,
    kind               text NOT NULL CHECK (kind IN ('model_usage', 'api', 'hosting', 'storage', 'other')),
    provider_id        bigint REFERENCES usage_providers(id) ON DELETE SET NULL,
    description        text NOT NULL,
    quantity           numeric(18,4) NOT NULL DEFAULT 1 CHECK (quantity >= 0),
    unit               text NOT NULL DEFAULT 'units' CHECK (unit IN ('tokens', 'calls', 'gb', 'gb_month', 'vm_hours', 'months', 'units')),
    unit_cost          numeric(14,6) NOT NULL DEFAULT 0 CHECK (unit_cost >= 0),
    cost_amount        numeric(14,2) NOT NULL DEFAULT 0 CHECK (cost_amount >= 0),
    pricing            text NOT NULL DEFAULT 'cost' CHECK (pricing IN ('cost', 'markup', 'resale')),
    markup_pct         numeric(6,2) NOT NULL DEFAULT 0 CHECK (markup_pct BETWEEN 0 AND 500),
    resale_unit_price  numeric(14,6) CHECK (resale_unit_price >= 0),
    billable_amount    numeric(14,2) NOT NULL DEFAULT 0,                        -- written by the guard from the pricing
    billable           boolean NOT NULL DEFAULT true,
    currency           char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),         -- the engagement's
    source             text NOT NULL DEFAULT 'manual' CHECK (source IN ('manual', 'csv_import', 'kernel_ledger', 'schedule')),
    import_id          bigint REFERENCES usage_imports(id) ON DELETE SET NULL,
    statement_id       bigint REFERENCES ai_statements(id) ON DELETE SET NULL,
    schedule_id        bigint REFERENCES recurring_charges(id) ON DELETE SET NULL,
    source_ref         text,
    status             text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'approved', 'void')),
    approved_at        timestamptz,
    approved_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    voided_at          timestamptz,
    voided_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    void_reason        text,
    created_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (period_end >= period_start),
    CHECK (pricing <> 'resale' OR resale_unit_price IS NOT NULL)
);
CREATE INDEX usage_charges_engagement_idx ON usage_charges (engagement_id, period_start DESC);
CREATE INDEX usage_charges_status_idx ON usage_charges (status);
CREATE INDEX usage_charges_provider_idx ON usage_charges (provider_id);
CREATE INDEX usage_charges_statement_idx ON usage_charges (statement_id);
CREATE INDEX usage_charges_import_idx ON usage_charges (import_id);
CREATE INDEX usage_charges_schedule_idx ON usage_charges (schedule_id);
CREATE INDEX usage_charges_approved_by_idx ON usage_charges (approved_by);
CREATE INDEX usage_charges_voided_by_idx ON usage_charges (voided_by);
CREATE INDEX usage_charges_created_by_idx ON usage_charges (created_by);
CREATE TRIGGER usage_charges_touch BEFORE UPDATE ON usage_charges FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A piece of a statement line placed on an engagement by a rule, as a usage charge.
CREATE TABLE ai_statement_allocations (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    statement_id     bigint NOT NULL REFERENCES ai_statements(id) ON DELETE CASCADE,
    line_id          bigint NOT NULL REFERENCES ai_statement_lines(id) ON DELETE CASCADE,
    rule_id          bigint REFERENCES allocation_rules(id) ON DELETE SET NULL,
    usage_charge_id  bigint NOT NULL REFERENCES usage_charges(id) ON DELETE CASCADE,
    amount           numeric(14,2) NOT NULL,
    created_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX ai_statement_allocations_line_idx ON ai_statement_allocations (line_id);
CREATE INDEX ai_statement_allocations_charge_idx ON ai_statement_allocations (usage_charge_id);
CREATE INDEX ai_statement_allocations_rule_idx ON ai_statement_allocations (rule_id);
CREATE INDEX ai_statement_allocations_statement_idx ON ai_statement_allocations (statement_id);

-- ---------------------------------------------------------------------------------------------
-- The charge's rules: numbered at creation; money from the pricing; locked once approved; status through the functions.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_usage_charge_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE e engagements%ROWTYPE;
BEGIN
    IF TG_OP = 'INSERT' THEN
        NEW.number := COALESCE(NEW.number, next_document_number('usage_charge'));
        IF NEW.status <> 'draft' THEN RAISE EXCEPTION 'A usage charge is born a draft and approved afterwards'; END IF;
    ELSE
        IF NEW.number <> OLD.number THEN RAISE EXCEPTION 'Usage charge % keeps its number', OLD.number; END IF;
        IF NEW.status <> OLD.status AND NOT ct_via_function() THEN RAISE EXCEPTION 'A usage charge''s status changes through approve or void — never by hand'; END IF;
        IF OLD.status = 'void' THEN RAISE EXCEPTION 'Usage charge % is void and cannot change', OLD.number; END IF;
        IF OLD.status = 'approved' AND NOT ct_via_function()
           AND row(NEW.engagement_id, NEW.period_start, NEW.period_end, NEW.kind, NEW.provider_id, NEW.quantity, NEW.unit, NEW.unit_cost, NEW.cost_amount, NEW.pricing, NEW.markup_pct, NEW.resale_unit_price, NEW.billable, NEW.description)
               IS DISTINCT FROM row(OLD.engagement_id, OLD.period_start, OLD.period_end, OLD.kind, OLD.provider_id, OLD.quantity, OLD.unit, OLD.unit_cost, OLD.cost_amount, OLD.pricing, OLD.markup_pct, OLD.resale_unit_price, OLD.billable, OLD.description) THEN
            RAISE EXCEPTION 'Usage charge % is approved and locked; void it and make another', OLD.number;
        END IF;
        IF ct_via_function() THEN RETURN NEW; END IF;
    END IF;
    SELECT * INTO e FROM engagements WHERE id = NEW.engagement_id;
    IF e.id IS NULL THEN RAISE EXCEPTION 'No such engagement'; END IF;
    IF e.status NOT IN ('active', 'on_hold') THEN RAISE EXCEPTION 'Engagement % is %; a charge needs an active one', e.number, e.status; END IF;
    NEW.currency := e.currency;
    IF NEW.source <> 'kernel_ledger' THEN
        NEW.cost_amount := round(NEW.quantity * NEW.unit_cost, 2);
    ELSIF NEW.quantity > 0 THEN
        NEW.unit_cost := round(NEW.cost_amount / NEW.quantity, 6);
    END IF;
    NEW.billable_amount := ct_billable_amount(NEW.cost_amount, NEW.quantity, NEW.pricing, NEW.markup_pct, NEW.resale_unit_price, NEW.unit);
    RETURN NEW;
END$$;
CREATE TRIGGER usage_charges_guard BEFORE INSERT OR UPDATE ON usage_charges FOR EACH ROW EXECUTE FUNCTION ct_usage_charge_guard();

CREATE OR REPLACE FUNCTION ct_usage_charge_no_delete() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status <> 'draft' THEN RAISE EXCEPTION 'Usage charge % is % and is never deleted; void it', OLD.number, OLD.status; END IF;
    RETURN OLD;
END$$;
CREATE TRIGGER usage_charges_no_delete BEFORE DELETE ON usage_charges FOR EACH ROW EXECUTE FUNCTION ct_usage_charge_no_delete();

CREATE OR REPLACE FUNCTION ct_usage_charge_approve(p_id bigint, p_by bigint) RETURNS usage_charges
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE u usage_charges%ROWTYPE;
BEGIN
    SELECT * INTO u FROM usage_charges WHERE id = p_id FOR UPDATE;
    IF u.id IS NULL THEN RAISE EXCEPTION 'No such usage charge'; END IF;
    IF u.status <> 'draft' THEN RAISE EXCEPTION 'Usage charge % is already %', u.number, u.status; END IF;
    IF NOT (SELECT ct_has_right('usage.approve')) THEN RAISE EXCEPTION 'Approving usage charges needs the Manager role'; END IF;
    IF NOT (SELECT ct_can_manage_engagement(u.engagement_id)) THEN RAISE EXCEPTION 'You do not manage the engagement of %', u.number; END IF;
    PERFORM set_config('ct.via_function', '1', true);
    UPDATE usage_charges SET status = 'approved', approved_at = now(), approved_by = p_by WHERE id = u.id RETURNING * INTO u;
    PERFORM set_config('ct.via_function', '', true);
    RETURN u;
END$$;

CREATE OR REPLACE FUNCTION ct_usage_charge_void(p_id bigint, p_by bigint, p_reason text) RETURNS usage_charges
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE u usage_charges%ROWTYPE;
BEGIN
    IF COALESCE(btrim(p_reason), '') = '' THEN RAISE EXCEPTION 'Voiding a usage charge needs a reason'; END IF;
    SELECT * INTO u FROM usage_charges WHERE id = p_id FOR UPDATE;
    IF u.id IS NULL THEN RAISE EXCEPTION 'No such usage charge'; END IF;
    IF u.status = 'void' THEN RAISE EXCEPTION 'Usage charge % is already void', u.number; END IF;
    IF ct_item_billed('usage_charge', u.id) THEN RAISE EXCEPTION 'Usage charge % is on an invoice; credit the invoice instead', u.number; END IF;
    PERFORM set_config('ct.via_function', '1', true);
    UPDATE usage_charges SET status = 'void', voided_at = now(), voided_by = p_by, void_reason = p_reason WHERE id = u.id RETURNING * INTO u;
    PERFORM set_config('ct.via_function', '', true);
    -- its pieces leave the statement's allocation so the remainder shows again
    UPDATE ai_statement_lines l SET allocated_amount = allocated_amount - a.amount FROM ai_statement_allocations a WHERE a.line_id = l.id AND a.usage_charge_id = u.id;
    DELETE FROM ai_statement_allocations WHERE usage_charge_id = u.id;
    RETURN u;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Hosting schedules: the date after this one; run one (a draft charge for the period it covers).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_recurring_next(p_date date, p_cadence text, p_every integer) RETURNS date
    LANGUAGE sql IMMUTABLE AS $$
    SELECT (p_date + CASE p_cadence WHEN 'weekly' THEN (p_every || ' weeks')::interval
                                    WHEN 'monthly' THEN (p_every || ' months')::interval
                                    WHEN 'quarterly' THEN (p_every * 3 || ' months')::interval
                                    ELSE (p_every || ' years')::interval END)::date;
$$;

CREATE OR REPLACE FUNCTION ct_recurring_charge_run(p_schedule_id bigint, p_by bigint) RETURNS usage_charges
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r recurring_charges%ROWTYPE; u usage_charges%ROWTYPE; nd date;
BEGIN
    SELECT * INTO r FROM recurring_charges WHERE id = p_schedule_id FOR UPDATE;
    IF r.id IS NULL THEN RAISE EXCEPTION 'No such schedule'; END IF;
    IF NOT r.active THEN RAISE EXCEPTION 'The schedule is not active'; END IF;
    IF r.next_date > current_date THEN RAISE EXCEPTION 'The schedule is not due until %', r.next_date; END IF;
    nd := ct_recurring_next(r.next_date, r.cadence, r.every_n);
    INSERT INTO usage_charges (engagement_id, period_start, period_end, kind, provider_id, description, quantity, unit, unit_cost, pricing, markup_pct, resale_unit_price,
                               currency, source, schedule_id, created_by)
    VALUES (r.engagement_id, r.next_date, nd - 1, r.kind, r.provider_id, r.description, r.quantity, r.unit, r.unit_cost, r.pricing, r.markup_pct, r.resale_unit_price,
            'USD', 'schedule', r.id, p_by)
    RETURNING * INTO u;
    UPDATE recurring_charges SET next_date = nd, last_run_at = now(), run_count = run_count + 1,
                                 active = CASE WHEN end_date IS NOT NULL AND nd > end_date THEN false ELSE active END
     WHERE id = r.id;
    RETURN u;
END$$;

-- ---------------------------------------------------------------------------------------------
-- The kernel's statement: load (verbatim; a changed document for an allocated month is refused), allocate by rules.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ct_ai_statement_load(p_document jsonb) RETURNS ai_statements
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s ai_statements%ROWTYPE; h text; per text; ks text; n integer := 0; l jsonb;
BEGIN
    IF p_document->>'schema' IS DISTINCT FROM 'os.ledger-period/1' THEN
        RAISE EXCEPTION 'Not an os.ledger-period/1 document (schema %)', p_document->>'schema';
    END IF;
    per := p_document->>'period';
    ks := p_document->>'status';
    h := encode(sha256(convert_to(p_document::text, 'UTF8')), 'hex');
    SELECT * INTO s FROM ai_statements WHERE period = per FOR UPDATE;
    IF s.id IS NOT NULL AND s.status <> 'pulled' AND s.document_hash <> h THEN
        RAISE EXCEPTION 'Statement % was allocated from a different document (hash % now %); the charges do not follow a changed statement', per, s.document_hash, h;
    END IF;
    IF s.id IS NULL THEN
        INSERT INTO ai_statements (period, period_start, period_end, kernel_status, document, document_hash)
        VALUES (per, (p_document->>'period_start')::date, (p_document->>'period_end')::date, ks, p_document, h)
        RETURNING * INTO s;
    ELSIF s.document_hash <> h OR s.kernel_status <> ks THEN
        UPDATE ai_statements SET kernel_status = ks, document = p_document, document_hash = h, pulled_at = now() WHERE id = s.id RETURNING * INTO s;
    ELSE
        UPDATE ai_statements SET pulled_at = now() WHERE id = s.id RETURNING * INTO s;
        RETURN s;
    END IF;
    DELETE FROM ai_statement_lines WHERE statement_id = s.id;
    FOR l IN SELECT * FROM jsonb_array_elements(COALESCE(p_document->'lines', '[]'::jsonb)) LOOP
        n := n + 1;
        INSERT INTO ai_statement_lines (statement_id, line_no, provider, model_id, model, kernel_department_id, department, kernel_agent_id, agent, kernel_application_id, application,
                                        calls, late_calls, input_tokens, output_tokens, cache_read_tokens, cache_write_tokens, amount, currency, note)
        VALUES (s.id, n, l->>'provider', l->>'model_id', l->>'model', (l->>'department_id')::bigint, l->>'department', (l->>'agent_id')::bigint, l->>'agent',
                (l->>'application_id')::bigint, l->>'application', COALESCE((l->>'calls')::int, 0), COALESCE((l->>'late_calls')::int, 0),
                COALESCE((l->>'input_tokens')::bigint, 0), COALESCE((l->>'output_tokens')::bigint, 0),
                COALESCE((l->>'cache_read_tokens')::bigint, 0), COALESCE((l->>'cache_write_tokens')::bigint, 0),
                COALESCE((l->>'amount')::numeric, 0), COALESCE(l->>'currency', 'USD'), l->>'note');
    END LOOP;
    UPDATE ai_statements SET late_calls = (SELECT COALESCE(sum(late_calls), 0) FROM ai_statement_lines WHERE statement_id = s.id) WHERE id = s.id RETURNING * INTO s;
    RETURN s;
END$$;

-- Allocate a CLOSED month by the rules valid in it: per line, per matching rule, a piece = amount × share, rounded once;
-- pieces of one engagement × provider × currency × pricing become ONE draft usage charge (quantity = the tokens of the
-- pieces' lines, pro rata). A piece a rule already placed on an APPROVED charge is left as it is; the month's draft
-- charges are deleted and remade (re-allocation after a rule changed). Returns how many charges were made.
CREATE OR REPLACE FUNCTION ct_ai_statement_allocate(p_statement_id bigint, p_by bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s ai_statements%ROWTYPE; l record; r record; piece numeric(14,2); made integer := 0; g record; cid bigint; pid bigint;
        tok numeric; share_sum numeric; prov text;
BEGIN
    SELECT * INTO s FROM ai_statements WHERE id = p_statement_id FOR UPDATE;
    IF s.id IS NULL THEN RAISE EXCEPTION 'No such statement'; END IF;
    IF s.kernel_status <> 'closed' THEN RAISE EXCEPTION 'Statement % is still open in the OS; only a closed month is allocated', s.period; END IF;
    -- void the month's draft charges (their pieces go with them) so the rules are applied afresh
    PERFORM set_config('ct.via_function', '1', true);
    FOR g IN SELECT id FROM usage_charges WHERE statement_id = s.id AND status = 'draft' LOOP
        DELETE FROM ai_statement_allocations WHERE usage_charge_id = g.id;
        DELETE FROM usage_charges WHERE id = g.id;
    END LOOP;
    PERFORM set_config('ct.via_function', '', true);
    UPDATE ai_statement_lines l2 SET allocated_amount = COALESCE((SELECT sum(a.amount) FROM ai_statement_allocations a WHERE a.line_id = l2.id), 0) WHERE l2.statement_id = s.id;
    IF to_regclass('pg_temp.ct_alloc_pieces') IS NULL THEN
        CREATE TEMP TABLE ct_alloc_pieces (line_id bigint, rule_id bigint, engagement_id bigint, provider text, currency char(3),
                                           pricing text, markup_pct numeric, resale_unit_price numeric, amount numeric(14,2), tokens numeric) ON COMMIT DROP;
    END IF;
    DELETE FROM ct_alloc_pieces;
    FOR l IN SELECT * FROM ai_statement_lines WHERE statement_id = s.id AND amount <> 0 LOOP
        SELECT COALESCE(sum(share_pct), 0) INTO share_sum FROM allocation_rules ar
         WHERE ar.active AND ar.valid_from <= s.period_end AND (ar.valid_to IS NULL OR ar.valid_to > s.period_start)
           AND (ar.kernel_agent_id IS NULL OR ar.kernel_agent_id = l.kernel_agent_id)
           AND (ar.kernel_department_id IS NULL OR ar.kernel_department_id = l.kernel_department_id)
           AND (ar.kernel_application_id IS NULL OR ar.kernel_application_id = l.kernel_application_id);
        IF share_sum > 100 THEN RAISE EXCEPTION 'The rules for line % (%, %) share out % %% — more than the whole; fix the rules', l.line_no, l.provider, COALESCE(l.agent, l.department, l.application), share_sum; END IF;
        FOR r IN SELECT * FROM allocation_rules ar
                  WHERE ar.active AND ar.valid_from <= s.period_end AND (ar.valid_to IS NULL OR ar.valid_to > s.period_start)
                    AND (ar.kernel_agent_id IS NULL OR ar.kernel_agent_id = l.kernel_agent_id)
                    AND (ar.kernel_department_id IS NULL OR ar.kernel_department_id = l.kernel_department_id)
                    AND (ar.kernel_application_id IS NULL OR ar.kernel_application_id = l.kernel_application_id) LOOP
            -- a piece this rule already placed on an APPROVED charge stays as it is
            IF EXISTS (SELECT 1 FROM ai_statement_allocations a JOIN usage_charges u ON u.id = a.usage_charge_id
                        WHERE a.line_id = l.id AND a.rule_id = r.id AND u.status = 'approved') THEN CONTINUE; END IF;
            piece := round(l.amount * r.share_pct / 100, 2);
            IF piece = 0 THEN CONTINUE; END IF;
            tok := round((l.input_tokens + l.output_tokens + l.cache_read_tokens + l.cache_write_tokens) * r.share_pct / 100);
            INSERT INTO ct_alloc_pieces VALUES (l.id, r.id, r.engagement_id, l.provider, l.currency, r.pricing, r.markup_pct, r.resale_unit_price, piece, tok);
        END LOOP;
    END LOOP;
    FOR g IN SELECT engagement_id, provider, currency, pricing, markup_pct, resale_unit_price, sum(amount) AS amt, sum(tokens) AS tok, count(*) AS n
               FROM ct_alloc_pieces GROUP BY engagement_id, provider, currency, pricing, markup_pct, resale_unit_price ORDER BY engagement_id, provider LOOP
        pid := ct_usage_provider(g.provider);
        INSERT INTO usage_charges (engagement_id, period_start, period_end, kind, provider_id, description, quantity, unit, cost_amount, pricing, markup_pct, resale_unit_price,
                                   currency, source, statement_id, source_ref, created_by)
        VALUES (g.engagement_id, s.period_start, s.period_end, 'model_usage', pid,
                'Model usage ' || s.period || ' — ' || g.provider || ' (the OS statement, ' || g.n || ' pieces by rule)', g.tok, 'tokens', g.amt, g.pricing, g.markup_pct, g.resale_unit_price,
                'USD', 'kernel_ledger', s.id, s.period, p_by)
        RETURNING id INTO cid;
        INSERT INTO ai_statement_allocations (statement_id, line_id, rule_id, usage_charge_id, amount)
        SELECT s.id, p.line_id, p.rule_id, cid, p.amount FROM ct_alloc_pieces p
         WHERE p.engagement_id = g.engagement_id AND p.provider = g.provider AND p.currency = g.currency AND p.pricing = g.pricing
           AND p.markup_pct = g.markup_pct AND p.resale_unit_price IS NOT DISTINCT FROM g.resale_unit_price;
        made := made + 1;
    END LOOP;
    UPDATE ai_statement_lines l2 SET allocated_amount = COALESCE((SELECT sum(a.amount) FROM ai_statement_allocations a WHERE a.line_id = l2.id), 0) WHERE l2.statement_id = s.id;
    UPDATE ai_statements SET status = 'allocated', allocated_at = now(), allocated_by = p_by WHERE id = s.id;
    RETURN made;
END$$;

-- The month's state in one row: the statement's total, what is allocated, the remainder (U2).
CREATE OR REPLACE FUNCTION ct_ai_statement_summary(p_statement_id bigint)
    RETURNS TABLE (provider text, currency char(3), statement_amount numeric(14,2), allocated_amount numeric(14,2), remainder numeric(14,2), lines integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT l.provider, l.currency, round(sum(l.amount), 2), sum(l.allocated_amount), round(sum(l.amount), 2) - sum(l.allocated_amount), count(*)::int
      FROM ai_statement_lines l WHERE l.statement_id = p_statement_id AND ct_has_right('usage.write')
     GROUP BY l.provider, l.currency ORDER BY 1, 2;
$$;

REVOKE ALL ON FUNCTION ct_usage_charge_approve(bigint, bigint), ct_usage_charge_void(bigint, bigint, text), ct_recurring_charge_run(bigint, bigint),
    ct_ai_statement_load(jsonb), ct_ai_statement_allocate(bigint, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ct_usage_charge_approve(bigint, bigint), ct_usage_charge_void(bigint, bigint, text), ct_recurring_charge_run(bigint, bigint),
    ct_ai_statement_load(jsonb), ct_ai_statement_allocate(bigint, bigint) TO consultant_tracking_rw;
GRANT EXECUTE ON FUNCTION ct_recurring_next(date, text, integer), ct_ai_statement_summary(bigint) TO consultant_tracking_rw, consultant_tracking_records_ro;
GRANT SELECT, INSERT, UPDATE, DELETE ON usage_imports, ai_statements, ai_statement_lines, allocation_rules, recurring_charges, usage_charges, ai_statement_allocations TO consultant_tracking_rw;

COMMIT;
