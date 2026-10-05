-- 013: TALKING to people (the in-app bell, preferences, the outbox — email through MaluMail and texts through the kernel's
-- K6 as independent rows, Help Desk's pattern as General Ledger copied it) and DISPATCHING to agents (design §5).
BEGIN;

CREATE TABLE notifications (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind         text NOT NULL CHECK (kind IN ('timesheet_submitted', 'timesheet_approved', 'timesheet_rejected', 'missing_time', 'expense_submitted',
                                               'expense_approved', 'expense_rejected', 'expense_reimbursed', 'usage_drafted', 'budget_alert',
                                               'invoice_drafted', 'invoice_viewed', 'claim_submitted', 'invoice_overdue', 'invoice_paid',
                                               'morning_note', 'ai_statement_allocated', 'milestone_delivered', 'mentioned', 'agent_drafted')),
    record_type  text,
    record_id    bigint,
    title        text NOT NULL,
    body         text,
    read_at      timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notifications_member_idx ON notifications (member_id, created_at DESC) WHERE read_at IS NULL;
CREATE INDEX notifications_record_idx ON notifications (record_type, record_id);

CREATE TABLE notification_prefs (
    member_id      bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    email_enabled  boolean NOT NULL DEFAULT true,
    text_enabled   boolean NOT NULL DEFAULT false,                -- K6: the kernel keeps the opt-out; this is the person's choice here
    kinds          text[] NOT NULL DEFAULT '{timesheet_rejected,missing_time,expense_approved,expense_rejected,budget_alert,claim_submitted,invoice_viewed,morning_note}',
    text_kinds     text[] NOT NULL DEFAULT '{missing_time,expense_approved}',
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER notification_prefs_touch BEFORE UPDATE ON notification_prefs FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- One row per recipient AND channel; a K6 refusal is a SKIP with the code and the email still goes. A client gets email
-- only: an invoice, a reminder, a statement — each carrying the secure link, never a note or a cost.
CREATE TABLE notification_outbox (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel        text NOT NULL CHECK (channel IN ('email', 'text')),
    member_id      bigint REFERENCES members(id) ON DELETE CASCADE,
    client_id      bigint REFERENCES clients(id) ON DELETE CASCADE,
    to_email       citext,                                                 -- the client's address at send time
    kind           text NOT NULL,
    record_type    text,
    record_id      bigint,
    dedupe_key     text,
    subject        text,
    body           text NOT NULL,
    body_html      text,
    status         text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'skipped', 'failed')),
    attempts       integer NOT NULL DEFAULT 0,
    detail         text,
    provider_ref   text,
    send_after     timestamptz NOT NULL DEFAULT now(),
    sent_at        timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now(),
    CHECK ((member_id IS NULL) <> (client_id IS NULL)),
    CHECK (channel = 'email' OR member_id IS NOT NULL)
);
CREATE UNIQUE INDEX notification_outbox_dedupe ON notification_outbox (dedupe_key) WHERE dedupe_key IS NOT NULL;
CREATE INDEX notification_outbox_queue_idx ON notification_outbox (status, send_after) WHERE status = 'queued';
CREATE INDEX notification_outbox_member_idx ON notification_outbox (member_id, created_at DESC);
CREATE INDEX notification_outbox_client_idx ON notification_outbox (client_id, created_at DESC);
CREATE INDEX notification_outbox_record_idx ON notification_outbox (record_type, record_id);

-- What was handed to an agent (design §5): a duty's pick-up is the agent's own; a dispatch is the application calling the
-- kernel's chat endpoint for one turn, or — K8/K16, owed — the wake call.
CREATE TABLE agent_dispatches (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type      text,
    record_id        bigint,
    agent_member_id  bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind             text NOT NULL CHECK (kind IN ('timesheet_submitted', 'budget_crossed', 'statement_closed', 'invoice_viewed', 'claim_submitted', 'ask')),
    via              text NOT NULL CHECK (via IN ('chat', 'wake', 'duty')),
    acting_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    run_id           bigint,
    request_id       text,
    status           text NOT NULL DEFAULT 'sent' CHECK (status IN ('sent', 'answered', 'refused', 'failed', 'awaiting_approval')),
    reply_excerpt    text,                                                 -- at most 200 characters
    detail           text,
    attempts         integer NOT NULL DEFAULT 1,
    created_at       timestamptz NOT NULL DEFAULT now(),
    answered_at      timestamptz
);
CREATE INDEX agent_dispatches_record_idx ON agent_dispatches (record_type, record_id, created_at DESC);
CREATE INDEX agent_dispatches_agent_idx ON agent_dispatches (agent_member_id, created_at DESC);
CREATE INDEX agent_dispatches_acting_idx ON agent_dispatches (acting_member_id);

GRANT SELECT, INSERT, UPDATE, DELETE ON notifications, notification_prefs, notification_outbox, agent_dispatches TO consultant_tracking_rw;

COMMIT;
