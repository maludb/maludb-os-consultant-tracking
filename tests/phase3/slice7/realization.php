<?php
/**
 * Proof — realization (spec "Proof", 1) over the report world, derived by hand. Acme, the whole period:
 *   approved hours (approved or written off) = W1 18 (Priya 7, Dana 7, owner 4) + W2 2 (Dana, written off) + W4 3 (Priya) = 23; billed = 14 (Priya 7 + Dana 7 on INV-00001); written off 2; unbilled with a rate 3 (W4); no rate 4 (the owner);
 *   standard = 1,260 + 875 + 250 (the written-off 2 h at 125) + 540 (W4) = 2,925.00; billed value = 1,260 + 875 = 2,135.00; realization 14/23 = 60.9 % of the hours, 2,135/2,925 = 73.0 % of the value.
 * W1 alone: approved 18, billed 14, written off 0, unbilled 0, no rate 4, standard 2,135.00 (the owner's hours have none), billed 2,135.00 → 14/18 = 77.8 % and 100.0 %.
 * Engagements with no time in the period are not listed (Beta, Bea's own engagement).
 */
require __DIR__ . '/lib.php';
$w = report_world();
$owner = as_member(1); $priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29);
$client = (int) one('SELECT client_id FROM engagements WHERE id = :e', ['e' => $w['acme']]);
$other = (int) one("SELECT id FROM clients WHERE name = 'SMOKE Beta'");

echo "1. The whole period\n";
[$c, $d] = report_json($owner, 'realization', whole());
ok($c === 200 && count($d['rows']) === 1 && $d['rows'][0]['engagement_number'] === 'ENG-0001', 'one engagement has time: ENG-0001 (Beta and Bea\'s have none)');
$r = $d['rows'][0];
ok(same($r['approved_hours'], 23) && same($r['billed_hours'], 14) && same($r['written_off_hours'], 2) && same($r['unbilled_hours'], 3) && same($r['no_rate_hours'], 4), 'hours: 23 approved, 14 billed, 2 written off, 3 unbilled, 4 with no rate');
ok(same($r['standard_amount'], 2925) && same($r['billed_amount'], 2135) && same($r['realization_hours_pct'], 60.9) && same($r['realization_amount_pct'], 73.0), 'value: standard 2,925.00, billed 2,135.00 → 60.9 % of the hours, 73.0 % of the value');
$t = $d['totals'][0];
ok($t['currency'] === 'USD' && same($t['approved_hours'], 23) && same($t['standard_amount'], 2925) && same($t['realization_hours_pct'], 60.9) && same($t['realization_amount_pct'], 73.0), 'the totals row, USD: the same (one engagement)');
ok($d['cells']['rows'][0] === ['ENG-0001 SMOKE Time Acme', 'SMOKE Acme', '23 h', '14 h', '2 h', '3 h', '4 h', '2,925.00 USD', '2,135.00 USD', '60.9 %', '73.0 %'], 'the row as cells, every amount with its currency: ' . implode(' | ', $d['cells']['rows'][0]));
ok($d['cells']['header'] === ['Engagement', 'Client', 'Approved', 'Billed', 'Written off', 'Unbilled', 'No rate', 'Standard', 'Billed value', 'Realization (hours)', 'Realization (amount)'], 'the columns');

echo "2. A week, and the filters\n";
[$c, $d] = report_json($owner, 'realization', ['from' => $w['w1'], 'to' => day($w['w1'], 6)]);
$r = $d['rows'][0];
ok(same($r['approved_hours'], 18) && same($r['billed_hours'], 14) && same($r['written_off_hours'], 0) && same($r['unbilled_hours'], 0) && same($r['no_rate_hours'], 4) && same($r['standard_amount'], 2135) && same($r['billed_amount'], 2135) && same($r['realization_hours_pct'], 77.8) && same($r['realization_amount_pct'], 100.0), 'W1: 18 / 14 / 0 / 0 / 4 h, 2,135.00 against 2,135.00 → 77.8 % and 100.0 %');
[$c, $d] = report_json($owner, 'realization', whole() + ['engagement' => $w['beta']]);
ok($c === 200 && $d['rows'] === [] && $d['totals'] === [], 'engagement = Beta (no time): nothing, and no totals');
[$c, $d] = report_json($owner, 'realization', whole() + ['engagement' => $w['acme']]);
ok(count($d['rows']) === 1, 'engagement = Acme: its row');
[$c, $d] = report_json($owner, 'realization', whole() + ['client' => $client]);
ok(count($d['rows']) === 1 && $d['rows'][0]['client_name'] === 'SMOKE Acme', 'client = Acme: its row');
[$c, $d] = report_json($owner, 'realization', whole() + ['client' => $other]);
ok($d['rows'] === [], 'client = Beta: none');
[$c, $d] = report_json($owner, 'realization', ['from' => '2026-01-01', 'to' => '2026-01-31']);
ok($d['rows'] === [] && $d['cells']['rows'] === [], 'a period with no time: none');

echo "3. The page\n";
$b = page($owner, '/reports/realization?' . http_build_query(whole()))['body'];
ok(str_contains($b, 'id="report-table"') && str_contains($b, 'id="report-totals"') && str_contains($b, 'name="engagement"') && str_contains($b, 'name="client"') && str_contains($b, 'class="table-responsive"'), 'filters (engagement, client, period), table, totals');
ok(preg_match('#<span class="badge bg-soft-warning text-warning">60\.9 %</span>#', $b) === 1 && preg_match('#<span class="badge bg-soft-warning text-warning">73\.0 %</span>#', $b) === 1, 'a realization under 80 % is a warning (60.9 % and 73.0 %)');
$b1 = page($owner, '/reports/realization?' . http_build_query(['from' => $w['w1'], 'to' => day($w['w1'], 6)]))['body'];
ok(preg_match('#bg-soft-warning text-warning">77\.8 %#', $b1) === 1 && !str_contains($b1, '100.0 %</span>'), 'W1: 77.8 % is a warning, 100.0 % is plain');
ok(preg_match('#href="/engagements/' . $w['acme'] . '\?back=#', $b) === 1, 'the engagement is a link, carrying its way back');
ok(substr_count($b, '2,925.00 USD') >= 2 && substr_count($b, '2,135.00 USD') >= 2, 'every amount carries its currency, in the row and in the totals');
finish();
