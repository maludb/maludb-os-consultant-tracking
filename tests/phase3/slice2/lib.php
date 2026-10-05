<?php
/**
 * Helpers for the slice 2 proofs (docs/build-specs/engagements.md, "Proof"). Builds on slice 1's (and so Phase 2's). The cast: the owner (1, super-admin, IT),
 * Priya (26, Consultant, AI Strategy), Marco (27, Manager, AI Strategy), Bea (28, Billing), Ann (29, Finance), Dana (30, Consultant, ML Engineering), the Clerk (40, agent).
 */
require dirname(__DIR__) . '/slice1/lib.php';

/** A client with the Standard card priced for the proofs. Answers [client_id, card_id]. */
function fixture_client(string $name = 'SMOKE Acme', string $code = 'ACME'): array
{
    $card = card_id('SMOKE Standard');
    if ($card === null) {
        $card = (int) one("INSERT INTO rate_cards (name, currency, created_by) VALUES ('SMOKE Standard', 'USD', 1) RETURNING id");
        foreach (['principal' => 300, 'senior_consultant' => 225, 'consultant' => 180, 'analyst' => 125, 'project_manager' => 200] as $k => $r) {
            q('INSERT INTO rate_card_rates (rate_card_id, consulting_role_id, bill_rate) VALUES (:c, (SELECT id FROM consulting_roles WHERE key = :k), :r)', ['c' => $card, 'k' => $k, 'r' => $r]);
        }
    }
    $client = one('SELECT id FROM clients WHERE name = :n', ['n' => $name]);
    if ($client === false || $client === null) {
        $client = one('INSERT INTO clients (name, code, currency, default_rate_card_id, created_by) VALUES (:n, :c, \'USD\', :card, 1) RETURNING id', ['n' => $name, 'c' => $code, 'card' => $card]);
    }
    return [(int) $client, $card];
}
function role(string $key): int { return (int) one('SELECT id FROM consulting_roles WHERE key = :k', ['k' => $key]); }
/** A draft time entry by SQL (slice 3 brings the screens): hours on an engagement for a member on a date. */
function mkentry(int $engagement, int $member, string $date, float $hours, bool $billable = true): int
{
    return (int) one("INSERT INTO time_entries (member_id, engagement_id, entry_date, hours, billable, currency, timesheet_id) VALUES (:m, :e, :d, :h, :b, 'USD', (SELECT id FROM ct_timesheet_for(:m2, :d2))) RETURNING id",
        ['m' => $member, 'e' => $engagement, 'd' => $date, 'h' => $hours, 'b' => $billable ? 't' : 'f', 'm2' => $member, 'd2' => $date]);
}
