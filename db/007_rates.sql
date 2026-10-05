-- 007: RATES (design §6). A RATE CARD is a price list: a bill rate per consulting role in one currency; an engagement
-- picks one (or its client's default). A person's COST RATE is the most guarded thing here (it reveals pay): the admin's
-- table, read only under cost.view and never in a log payload or on the secure link. CAPACITY is hours a week, for
-- utilization. Cost rates and capacity are dated ranges that never overlap for one person (EXCLUDE, btree_gist).
BEGIN;

CREATE TABLE rate_cards (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name            text NOT NULL,
    currency        char(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    effective_from  date NOT NULL DEFAULT current_date,
    description     text,
    archived_at     timestamptz,
    created_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX rate_cards_name_live ON rate_cards (lower(name)) WHERE archived_at IS NULL;
CREATE INDEX rate_cards_created_by_idx ON rate_cards (created_by);
CREATE TRIGGER rate_cards_touch BEFORE UPDATE ON rate_cards FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE rate_card_rates (
    rate_card_id        bigint NOT NULL REFERENCES rate_cards(id) ON DELETE CASCADE,
    consulting_role_id  bigint NOT NULL REFERENCES consulting_roles(id) ON DELETE RESTRICT,
    bill_rate           numeric(12,2) NOT NULL CHECK (bill_rate >= 0),           -- per hour
    updated_at          timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (rate_card_id, consulting_role_id)
);
CREATE INDEX rate_card_rates_role_idx ON rate_card_rates (consulting_role_id);
CREATE TRIGGER rate_card_rates_touch BEFORE UPDATE ON rate_card_rates FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

ALTER TABLE clients ADD CONSTRAINT clients_default_rate_card_fk FOREIGN KEY (default_rate_card_id) REFERENCES rate_cards(id) ON DELETE SET NULL;
CREATE INDEX clients_rate_card_idx ON clients (default_rate_card_id);

-- cost.view only (db/015 shows it to nobody else; db/009 snapshots it onto an approved entry for the margin functions).
CREATE TABLE member_cost_rates (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    hourly_cost  numeric(12,2) NOT NULL CHECK (hourly_cost >= 0),               -- in the base currency
    valid_from   date NOT NULL,
    valid_to     date,                                                           -- NULL = open
    note         text,
    created_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    CHECK (valid_to IS NULL OR valid_to > valid_from),
    EXCLUDE USING gist (member_id WITH =, daterange(valid_from, valid_to, '[)') WITH &&)
);
CREATE INDEX member_cost_rates_member_idx ON member_cost_rates (member_id, valid_from DESC);
CREATE INDEX member_cost_rates_created_by_idx ON member_cost_rates (created_by);
CREATE TRIGGER member_cost_rates_touch BEFORE UPDATE ON member_cost_rates FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE member_capacity (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id       bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    hours_per_week  numeric(5,2) NOT NULL CHECK (hours_per_week BETWEEN 0 AND 168),
    valid_from      date NOT NULL,
    valid_to        date,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK (valid_to IS NULL OR valid_to > valid_from),
    EXCLUDE USING gist (member_id WITH =, daterange(valid_from, valid_to, '[)') WITH &&)
);
CREATE INDEX member_capacity_member_idx ON member_capacity (member_id, valid_from DESC);
CREATE TRIGGER member_capacity_touch BEFORE UPDATE ON member_capacity FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A person's cost per hour on a date (NULL when none is set), and their capacity (the settings' default when none).
CREATE OR REPLACE FUNCTION ct_cost_rate(p_member_id bigint, p_date date) RETURNS numeric
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT hourly_cost FROM member_cost_rates
     WHERE member_id = p_member_id AND valid_from <= p_date AND (valid_to IS NULL OR valid_to > p_date)
     ORDER BY valid_from DESC LIMIT 1;
$$;
CREATE OR REPLACE FUNCTION ct_capacity(p_member_id bigint, p_date date) RETURNS numeric
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((SELECT hours_per_week FROM member_capacity
                      WHERE member_id = p_member_id AND valid_from <= p_date AND (valid_to IS NULL OR valid_to > p_date)
                      ORDER BY valid_from DESC LIMIT 1),
                    (SELECT capacity_hours_per_week FROM ct_settings WHERE id = 1));
$$;

-- The rate table is the admin's: only the writer may read it directly; the records role reaches cost through the
-- margin functions (db/014), which test cost.view themselves.
REVOKE ALL ON FUNCTION ct_cost_rate(bigint, date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ct_cost_rate(bigint, date) TO consultant_tracking_rw;
GRANT EXECUTE ON FUNCTION ct_capacity(bigint, date) TO consultant_tracking_rw, consultant_tracking_records_ro;
GRANT SELECT, INSERT, UPDATE, DELETE ON rate_cards, rate_card_rates, member_cost_rates, member_capacity TO consultant_tracking_rw;

COMMIT;
