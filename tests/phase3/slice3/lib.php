<?php
/**
 * Helpers for the slice 3 proofs (docs/build-specs/time-core.md, "Proof"). Builds on slice 2's. The world: Acme T&M (ENG) with Priya, Marco and Dana staffed, a Beta fixed bid
 * with Priya, the Standard card (Consultant 180, Analyst 125), Priya's cost 70 and Dana's 55. The cast: the owner (1), Priya (26), Marco (27, the manager), Bea (28), Ann (29), Dana (30).
 */
require dirname(__DIR__) . '/slice2/lib.php';

/** The slice-3 world, made once per database: [acme engagement, beta engagement]. */
function time_world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    [$acme, $card] = fixture_client();
    [$beta, ] = fixture_client('SMOKE Beta', 'BETA');
    $marco = as_member(27);
    $e1 = one("SELECT id FROM engagements WHERE name = 'SMOKE Time Acme'");
    if ($e1 === false || $e1 === null) {
        [$c, $b] = act($marco, '/engagements/save.php', ['client' => $acme, 'name' => 'SMOKE Time Acme', 'kind' => 'time_and_materials', 'start_date' => '2026-01-05', 'budget_hours' => '400', 'budget_amount' => '60000', 'start_now' => 'yes']);
        $e1 = (int) $b['record_id'];
        [$c, $b] = act($marco, '/engagements/save.php', ['client' => $beta, 'name' => 'SMOKE Time Beta', 'kind' => 'fixed_bid', 'start_date' => '2026-01-05', 'budget_amount' => '20000', 'start_now' => 'yes']);
        $e2 = (int) $b['record_id'];
        foreach ([[$e1, 26, 'consultant'], [$e1, 30, 'analyst'], [$e2, 26, 'consultant']] as [$e, $m, $r]) {
            act($marco, '/engagements/staffing/save.php', ['engagement' => $e, 'member' => $m, 'role' => role($r), 'valid_from' => '2026-01-05']);
        }
        q("INSERT INTO member_cost_rates (member_id, hourly_cost, valid_from, created_by) VALUES (26, 70, '2026-01-01', 1), (30, 55, '2026-01-01', 1) ON CONFLICT DO NOTHING");
    } else {
        $e1 = (int) $e1;
        $e2 = (int) one("SELECT id FROM engagements WHERE name = 'SMOKE Time Beta'");
    }
    return $w = [$e1, $e2];
}
/** The Monday of a week N weeks ago (ct_week_start). */
function monday(int $weeksAgo = 0): string { return (string) one('SELECT ct_week_start(current_date) - CAST(:d AS integer)', ['d' => 7 * $weeksAgo]); }
function day(string $monday, int $offset): string { return (new DateTimeImmutable($monday))->modify('+' . $offset . ' days')->format('Y-m-d'); }
/** Log an entry over HTTP as a person: [status, body]. */
function log_time(string $jar, int $engagement, string $date, string $hours, array $extra = []): array { return act($jar, '/time/entries/save.php', ['engagement' => $engagement, 'date' => $date, 'hours' => $hours] + $extra); }
function week_id(int $member, string $monday): ?int { $v = one('SELECT id FROM timesheets WHERE member_id = :m AND week_start = :w', ['m' => $member, 'w' => $monday]); return $v === false || $v === null ? null : (int) $v; }
