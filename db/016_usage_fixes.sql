-- 016: three defects of db/011 found by slice 5's proofs (2026-10-05). Additive: a constraint replaced, two functions redefined.
--   1. A schedule with an end date could never make its final charge: ct_recurring_charge_run() deactivates it when the next
--      date passes the end date, and the row's CHECK refused that very update. The check now admits an inactive schedule.
--   2. The kernel stamps `generated_at` on every statement document, so a re-pull of an allocated month was always refused
--      against the real kernel. The hash now leaves out `generated_at` and `rolled_up_at` — the statement's substance only.
--   3. A CSV row's cost is the provider's fact: a token row is rarely an exact multiple of a 6-decimal unit cost. The guard now
--      keeps `cost_amount` for `csv_import` as it does for `kernel_ledger` and derives the unit cost from it.
BEGIN;

DO $$
DECLARE c record;
BEGIN
    FOR c IN SELECT conname FROM pg_constraint WHERE conrelid = 'recurring_charges'::regclass AND contype = 'c' AND pg_get_constraintdef(oid) LIKE '%end_date%' LOOP
        EXECUTE format('ALTER TABLE recurring_charges DROP CONSTRAINT %I', c.conname);
    END LOOP;
END$$;
ALTER TABLE recurring_charges ADD CONSTRAINT recurring_charges_end_after_next CHECK (end_date IS NULL OR end_date >= next_date OR NOT active);

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
    IF NEW.source NOT IN ('kernel_ledger', 'csv_import') THEN
        NEW.cost_amount := round(NEW.quantity * NEW.unit_cost, 2);        -- a charge typed or scheduled: the unit cost is the fact
    ELSIF NEW.quantity > 0 THEN
        NEW.unit_cost := round(NEW.cost_amount / NEW.quantity, 6);        -- db/016: a statement's or a file's cost is the fact (a token row is rarely an exact multiple)
    END IF;
    NEW.billable_amount := ct_billable_amount(NEW.cost_amount, NEW.quantity, NEW.pricing, NEW.markup_pct, NEW.resale_unit_price, NEW.unit);
    RETURN NEW;
END$$;

CREATE OR REPLACE FUNCTION ct_ai_statement_load(p_document jsonb) RETURNS ai_statements
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s ai_statements%ROWTYPE; h text; per text; ks text; n integer := 0; l jsonb;
BEGIN
    IF p_document->>'schema' IS DISTINCT FROM 'os.ledger-period/1' THEN
        RAISE EXCEPTION 'Not an os.ledger-period/1 document (schema %)', p_document->>'schema';
    END IF;
    per := p_document->>'period';
    ks := p_document->>'status';
    h := encode(sha256(convert_to((p_document - 'generated_at' - 'rolled_up_at')::text, 'UTF8')), 'hex');   -- db/016: the kernel stamps generated_at on every request; the hash is the statement's substance
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

COMMIT;
