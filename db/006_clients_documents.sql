-- 006: CLIENTS and their contacts (design §4), plus the two things every record carries: ATTACHMENTS (a receipt, a SOW, a
-- provider's invoice — under storage/attachments/<record_type>/<record_id>/, served through the gate, `client_visible`
-- when the engagement shares it on the secure link) and NOTES (internal, never on the link).
--
-- A client is the application's own record, never a member and never signed in: an invoice reaches them by email and the
-- secure link (db/012). `member_id` is nullable — a client who was also invited as an external member (for Help Desk, say).
BEGIN;

CREATE TABLE clients (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name                  text NOT NULL,                                          -- the short name on screens and cards
    legal_name            text,                                                   -- on the invoice
    code                  text CHECK (code ~ '^[A-Z0-9]{2,8}$'),                  -- a short code (Extended: inside invoice numbers)
    email                 citext,                                                 -- where invoices go when no contact says otherwise
    phone                 text,
    billing_address       text,
    tax_id                text,
    terms_days            integer CHECK (terms_days BETWEEN 0 AND 365),           -- NULL = the settings' default
    currency              char(3) CHECK (currency ~ '^[A-Z]{3}$'),                 -- NULL = the base currency; an engagement inherits it
    po_required           boolean NOT NULL DEFAULT false,                         -- an invoice needs the engagement's PO number
    default_rate_card_id  bigint,                                                 -- FK added in db/007
    member_id             bigint REFERENCES members(id) ON DELETE SET NULL,
    notes                 text,
    archived_at           timestamptz,
    created_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX clients_name_live ON clients (lower(name)) WHERE archived_at IS NULL;
CREATE UNIQUE INDEX clients_code_live ON clients (code) WHERE code IS NOT NULL AND archived_at IS NULL;
CREATE INDEX clients_name_trgm_idx ON clients USING gin (name gin_trgm_ops);
CREATE INDEX clients_email_idx ON clients (email);
CREATE INDEX clients_member_idx ON clients (member_id);
CREATE INDEX clients_created_by_idx ON clients (created_by);
CREATE TRIGGER clients_touch BEFORE UPDATE ON clients FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE client_contacts (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    client_id         bigint NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    name              text NOT NULL,
    email             citext,
    phone             text,
    role              text,                                                       -- 'Accounts payable', 'Sponsor', 'CTO'
    receives_invoices boolean NOT NULL DEFAULT false,                             -- on every invoice email
    is_primary        boolean NOT NULL DEFAULT false,
    archived_at       timestamptz,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX client_contacts_client_idx ON client_contacts (client_id);
CREATE UNIQUE INDEX client_contacts_one_primary ON client_contacts (client_id) WHERE is_primary AND archived_at IS NULL;
CREATE TRIGGER client_contacts_touch BEFORE UPDATE ON client_contacts FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ---------------------------------------------------------------------------------------------
-- Attachments and notes.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE attachments (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type     text NOT NULL CHECK (record_type IN ('client', 'engagement', 'milestone', 'expense', 'usage_charge', 'invoice', 'credit_note', 'usage_import')),
    record_id       bigint NOT NULL,
    filename        text NOT NULL,
    mime_type       text NOT NULL,
    byte_size       bigint NOT NULL CHECK (byte_size >= 0),
    sha256          text NOT NULL,
    storage_path    text NOT NULL,                                                -- under storage/attachments/, served through the gate
    client_visible  boolean NOT NULL DEFAULT false,                              -- a receipt the engagement shares on the secure link (db/012 decides)
    uploaded_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX attachments_record_idx ON attachments (record_type, record_id);
CREATE INDEX attachments_uploaded_by_idx ON attachments (uploaded_by);

CREATE TABLE notes (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type   text NOT NULL,
    record_id     bigint NOT NULL,
    member_id     bigint REFERENCES members(id) ON DELETE SET NULL,
    body          text NOT NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notes_record_idx ON notes (record_type, record_id, created_at DESC);
CREATE INDEX notes_member_idx ON notes (member_id);

GRANT SELECT, INSERT, UPDATE, DELETE ON clients, client_contacts, attachments, notes TO consultant_tracking_rw;

COMMIT;
