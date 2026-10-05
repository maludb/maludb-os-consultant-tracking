-- 002: activity memory -- the one funnel (memory.md §2). Exists before the first feature ships; it cannot be
-- backfilled. Every row is shipped to the tenant's one MaluDB as an `activity` episode by mcp/activity_ingest.py
-- (payload key "application": "consultant_tracking" first). A RECORD'S HISTORY IS THIS TABLE (design §6): there is no
-- audit table; `before`/`after` carry what the timeline shows, and every MONEY EVENT carries `amount` and `currency` in
-- `after`. A secure token is never here; a client's email never beyond the party card; a cost rate never at all.
BEGIN;

CREATE TABLE activity_log (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    occurred_at     timestamptz NOT NULL DEFAULT now(),
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    actor_client_id bigint,                        -- a client acting through the secure link (no FK: db/006 comes after)
    source          text NOT NULL DEFAULT 'web'
                    CHECK (source IN ('web', 'assistant', 'mcp', 'cron', 'agent', 'desk', 'webhook', 'portal', 'api', 'application')),
    action          text NOT NULL,                 -- entity.verb
    screen          text,
    route           text,                          -- "METHOD /path"
    entity_type     text,
    entity_id       bigint,
    before          jsonb,
    after           jsonb,                         -- changed fields only; amount + currency on every money event
    request_id      text,
    session_id      text,
    ip_address      inet,
    agent_run_id    bigint,                        -- the kernel's run id when an agent acted (no FK)
    department_id   bigint,                        -- the practice the event concerns
    engagement_id   bigint,                        -- denormalised for every engagement-scoped event (no FK — history outlives a record)
    client_id       bigint,                        -- the client the event concerns
    invoice_id      bigint,                        -- the invoice the event concerns (issue, send, view, remind, pay …)
    timesheet_id    bigint,                        -- the week the event concerns (an entry, a submit, an approval)
    kernel_request_id text,                        -- the kernel's request id for a call that crossed to it
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX activity_log_actor_idx   ON activity_log (actor_member_id, occurred_at DESC);
CREATE INDEX activity_log_entity_idx  ON activity_log (entity_type, entity_id, occurred_at DESC);
CREATE INDEX activity_log_action_idx  ON activity_log (action, occurred_at DESC);
CREATE INDEX activity_log_time_idx    ON activity_log (occurred_at DESC);
CREATE INDEX activity_log_request_idx ON activity_log (request_id);
CREATE INDEX activity_log_engagement_idx ON activity_log (engagement_id, occurred_at DESC);
CREATE INDEX activity_log_client_idx  ON activity_log (client_id, occurred_at DESC);
CREATE INDEX activity_log_invoice_idx ON activity_log (invoice_id, occurred_at DESC);
CREATE INDEX activity_log_timesheet_idx ON activity_log (timesheet_id, occurred_at DESC);

GRANT INSERT, SELECT ON activity_log TO consultant_tracking_rw;
REVOKE ALL ON activity_log FROM consultant_tracking_records_ro, consultant_tracking_activity_ro;

-- The ingest checkpoint: only ever moves forward.
CREATE TABLE activity_ingest_state (
    id          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    last_id     bigint NOT NULL DEFAULT 0,
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO activity_ingest_state (id, last_id) VALUES (1, 0) ON CONFLICT DO NOTHING;
GRANT SELECT, UPDATE ON activity_ingest_state TO consultant_tracking_rw;

COMMIT;
