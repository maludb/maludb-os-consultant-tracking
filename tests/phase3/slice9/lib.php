<?php
/**
 * Helpers for the slice 9 proofs (docs/build-specs/home-admin-tokens.md, "Proof"). Builds on slice 8's (so 7's … 1's): the REPORT WORLD and the EXPORTS WORLD are the data (Acme's INV-00001 unpaid, a credit note, 620.00 unbilled, company-card
 * expenses…). This slice adds the expert to the mirror (member 41, an agent holding the manager role — the fixture holds the Clerk, member 40, billing), the Clerk's footprints (a dispatch, a morning note, an activity row), and a month of the OS statement.
 */
require dirname(__DIR__) . '/slice8/lib.php';

/** The expert (member 41) in the mirror, as the directory feed would put it — once. */
function expert_member(): int
{
    if ((int) one('SELECT count(*) FROM members WHERE id = 41') === 0) {
        q("INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles, job_title, timezone, directory_updated_at, synced_at)
           VALUES (41, 'agent', 'SMOKE Expert', NULL, 'user', false, 'active', 'write', '{manager}', 'Consulting expert', 'UTC', now(), now())");
    }
    return 41;
}

/** Home world: the expert, the Clerk's dispatch and note and one of the agents' log rows, and a pulled, allocated OS month for Billing's home. Once per database. */
function home_world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    $r = report_world(); exports_world(); expert_member();
    if ((int) one("SELECT count(*) FROM agent_dispatches WHERE detail = 'SMOKE s9'") === 0) {
        q("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, run_id, request_id, status, attempts, reply_excerpt, detail, answered_at) VALUES ('engagement', :e, 40, 'budget_crossed', 'chat', 27, 91, 'ct-s9-1', 'answered', 2, 'Looked at it', 'SMOKE s9', now())", ['e' => $r['acme']]);
        q("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, request_id, status, attempts, detail) VALUES ('engagement', :e, 40, 'claim_submitted', 'chat', 28, 'ct-s9-2', 'failed', 1, 'SMOKE s9')", ['e' => $r['acme']]);
        q("INSERT INTO notifications (member_id, kind, title, body) VALUES (28, 'morning_note', 'The morning note, SMOKE s9', 'Drafted 1 invoice.')");
        q("INSERT INTO activity_log (occurred_at, actor_member_id, source, action, entity_type, entity_id) VALUES (now() - interval '1 hour', 40, 'agent', 'morning_note.send', 'notification', 1)");
    }
    return $r;
}

/** A screen as JSON under a signed action token: [status, the data (or the error body), raw]. */
function screen_token(array $headers, string $path): array
{
    $r = req('GET', $path, ['headers' => array_merge(JSONH, $headers)]);
    $j = json_decode($r['body'], true) ?? [];
    return [(int) $r['code'], $j['data'] ?? $j, $r];
}

/** The OS's months, pulled from the fake kernel (poll switched on for the pull, then off) — once per database. */
function pulled_month(): void
{
    if ((int) one('SELECT count(*) FROM ai_statements') > 0) { return; }
    q('UPDATE ct_settings SET ai_statement_poll = true WHERE id = 1');
    set_ledger(ledger_fixture());
    [$code, $rep] = worker('statement', hours_from_now(0));
    q('UPDATE ct_settings SET ai_statement_poll = false WHERE id = 1');
}
