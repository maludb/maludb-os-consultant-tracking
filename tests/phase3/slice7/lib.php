<?php
/**
 * Helpers for the slice 7 proofs (docs/build-specs/reports.md, "Proof"). Builds on slice 6's (so 5's … 1's). The cast: the owner (1), Priya (26, Consultant), Marco (27, the manager of Acme and Beta),
 * Bea (28, Billing), Ann (29, Finance), Dana (30, Consultant). The REPORT WORLD (derived by hand in every proof's comments) is slice 6's `billing_world()` plus:
 *   W1 = monday(3) (Sep 14 when the suites were written; every date below is an offset of it): Priya 4 h + 3 h at 180 (cost 70), Dana 5 h + 2 h at 125 (cost 55), the owner 4 h in a role the card does not price (no rate, no cost) — all approved;
 *        expenses 100.00 (hotel, receipt) and 40.00 (taxi) approved; usage 50.00 + 4.00 + 30.00 at cost (84.00).
 *   W2 = monday(2): Dana 2 h on Monday approved, then WRITTEN OFF (standard 250.00, cost 110.00).
 *   one more usage charge on Acme: hosting at cost 100.00 marked up 20 % → billable 120.00 (markup 20.00); period Sep 1–30.
 *   Acme invoice INV-00001 drafted by Marco THROUGH Sep 30 (the whole of the above that bills: time 1,260.00 + 875.00, expenses 140.00, usage 204.00 = 2,479.00), issued by Bea.
 *   the 40.00 taxi reimbursed (PAYRUN-RPT).
 *   Bea's own engagement "SMOKE rpt Beas eng" (Marco does not manage it): a fixed line of 300.00, INV-00002 issued by Bea.
 *   after the invoice: one more hosting charge, 60.00 at cost, Oct 1–5, approved and UNBILLED.
 *   W4 = monday(0) (this week): Priya 3 h on Monday approved (UNBILLED: 540.00) and a 20.00 taxi expense approved (UNBILLED).
 */
require dirname(__DIR__) . '/slice6/lib.php';

/** The report world, made once per database; returns ['acme', 'beta', 'beas', 'w1', 'w2', 'w4', 'invoice'] (the dates and the ids). */
function report_world(): array
{
    static $w = null;
    if ($w !== null) { return $w; }
    $bw = billing_world();
    $acme = $bw['acme']; $beta = $bw['beta'];
    $marco = as_member(27); $priya = as_member(26); $dana = as_member(30); $bea = as_member(28);
    $w1 = monday(3); $w2 = monday(2); $w4 = monday(0);
    if ((int) one("SELECT count(*) FROM engagements WHERE name = 'SMOKE rpt Beas eng'") === 0) {
        // W2: Dana's two hours, approved, written off
        [$c, $b] = log_time($dana, $acme, day($w2, 0), '2', ['note' => 'SMOKE rpt rework']);
        if ($c !== 200) { fwrite(STDERR, "W2 log failed: " . json_encode($b) . "\n"); }
        act($dana, '/time/weeks/submit.php', ['week' => $w2]);
        [$c, $b] = act($marco, '/time/weeks/approve.php', ['timesheet' => week_id(30, $w2)]);
        if ($c !== 200) { fwrite(STDERR, "W2 approve failed: " . json_encode($b) . "\n"); }
        $e = (int) one("SELECT id FROM time_entries WHERE timesheet_id = :t", ['t' => week_id(30, $w2)]);
        [$c, $b] = act($marco, '/time/entries/write-off.php', ['entry' => $e, 'reason' => 'SMOKE rpt goodwill']);
        if ($c !== 200) { fwrite(STDERR, "write-off failed: " . json_encode($b) . "\n"); }
        // the marked-up hosting charge
        [$c, $b] = act($marco, '/usage/save.php', ['engagement' => $acme, 'kind' => 'hosting', 'description' => 'SMOKE rpt marked-up VM', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'unit_cost' => '100', 'pricing' => 'markup', 'markup_pct' => '20']);
        $u = (int) ($b['record_id'] ?? 0);
        [$c, $b] = act($marco, '/usage/approve.php', ['charge' => $u]);
        if ($c !== 200) { fwrite(STDERR, "usage approve failed: " . json_encode($b) . "\n"); }
        // INV-00001, through Sep 30
        $through = max('2026-09-30', day($w2, 6));
        [$c, $b] = draft($marco, $acme, ['period_start' => '2026-09-01', 'period_end' => $through]);
        $inv = (int) ($b['record_id'] ?? 0);
        [$c, $b] = act($bea, '/invoices/issue.php', ['invoice' => $inv]);
        if ($c !== 200) { fwrite(STDERR, "issue failed: " . json_encode($b) . "\n"); }
        // Bea's own engagement and invoice
        [$c, $b] = act($bea, '/engagements/save.php', ['client' => (int) one("SELECT id FROM clients WHERE name = 'SMOKE Beta'"), 'name' => 'SMOKE rpt Beas eng', 'kind' => 'time_and_materials', 'start_date' => '2026-01-05', 'manager' => 28, 'start_now' => 'yes']);
        $beas = (int) $b['record_id'];
        [$c, $b] = draft($bea, $beas, ['add_unbilled' => 'no']);
        $bd = (int) $b['record_id'];
        act($bea, '/invoices/lines/save.php', ['invoice' => $bd, 'kind' => 'fixed', 'description' => 'SMOKE rpt fee', 'unit_price' => '300']);
        [$c, $b] = act($bea, '/invoices/issue.php', ['invoice' => $bd]);
        if ($c !== 200) { fwrite(STDERR, "issue 2 failed: " . json_encode($b) . "\n"); }
        // the 40.00 taxi (personal card) reimbursed by Bea
        [$c, $b] = act($bea, '/expenses/reimburse.php', ['expense' => $bw['expenses'][1], 'reference' => 'PAYRUN-RPT']);
        if ($c !== 200) { fwrite(STDERR, "reimburse failed: " . json_encode($b) . "\n"); }
        // an approved, UNBILLED hosting charge at cost (60.00), after the invoice
        [$c, $b] = act($marco, '/usage/save.php', ['engagement' => $acme, 'kind' => 'hosting', 'description' => 'SMOKE rpt new VM', 'period_start' => '2026-10-01', 'period_end' => '2026-10-05', 'unit_cost' => '60', 'pricing' => 'cost']);
        [$c, $b] = act($marco, '/usage/approve.php', ['charge' => (int) ($b['record_id'] ?? 0)]);
        if ($c !== 200) { fwrite(STDERR, "usage 2 approve failed: " . json_encode($b) . "\n"); }
        // W4: this week's unbilled work
        [$c, $b] = log_time($priya, $acme, day($w4, 0), '3', ['note' => 'SMOKE rpt this week']);
        if ($c !== 200) { fwrite(STDERR, "W4 log failed: " . json_encode($b) . "\n"); }
        act($priya, '/time/weeks/submit.php', ['week' => $w4]);
        [$c, $b] = act($marco, '/time/weeks/approve.php', ['timesheet' => week_id(26, $w4)]);
        if ($c !== 200) { fwrite(STDERR, "W4 approve failed: " . json_encode($b) . "\n"); }
        [$c, $b] = record_expense($priya, $acme, '20.00', ['description' => 'SMOKE rpt taxi', 'date' => day($w4, 0), 'submit' => '1']);
        [$c, $b2] = act($marco, '/expenses/approve.php', ['expense' => (int) ($b['record_id'] ?? 0)]);
        if ($c !== 200) { fwrite(STDERR, "W4 expense approve failed: " . json_encode($b2) . "\n"); }
    }
    return $w = ['acme' => $acme, 'beta' => $beta, 'beas' => (int) one("SELECT id FROM engagements WHERE name = 'SMOKE rpt Beas eng'"), 'w1' => $w1, 'w2' => $w2, 'w4' => $w4,
                 'invoice' => (int) one("SELECT id FROM invoices WHERE engagement_id = :e ORDER BY id LIMIT 1", ['e' => $acme])];
}

/** A report page as JSON for a jar: [code, data]. */
function report_json(string $jar, string $report, array $q = []): array { return screen($jar, '/reports/' . $report . ($q ? '?' . http_build_query($q) : '')); }
/** A row of a report's rows by a key's value. */
function row_of(array $data, string $key, string $value): ?array { foreach ($data['rows'] as $r) { if ((string) ($r[$key] ?? '') === $value) { return $r; } } return null; }
/** Two numeric strings equal as numbers (a sum over no rows is '0', not '0.00'). */
function same($a, $b): bool { return abs((float) $a - (float) $b) < 0.0001; }
/** The period that holds the whole world (Sep 1 to the end of the week after this one). */
function whole(): array { return ['from' => '2026-09-01', 'to' => (new DateTimeImmutable(monday(0)))->modify('+6 days')->format('Y-m-d')]; }
