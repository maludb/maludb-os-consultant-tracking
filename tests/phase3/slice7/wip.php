<?php
/**
 * Proof — unbilled work / WIP (spec "Proof", 1). ct_unbilled over the report world, by hand. As of today, Acme: time 3 h at 180 = 540.00 (W4), 4 hours with no rate (the owner's, not valued), expenses 20.00 (the W4 taxi),
 * usage 60.00 (the hosting charge after the invoice), milestones 0 → total 540 + 20 + 60 = 620.00; the oldest item is the owner's no-rate entry on Wednesday of W1.
 * As of Tuesday of W1 (before that Wednesday): no approved item that old is unbilled — the engagement drops out. As of that Wednesday: only the 4 no-rate hours — total 0.00, no-rate 4 h, oldest that day.
 */
require __DIR__ . '/lib.php';
$w = report_world();
$owner = as_member(1); $priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29);
$client = (int) one('SELECT client_id FROM engagements WHERE id = :e', ['e' => $w['acme']]);
$other = (int) one("SELECT id FROM clients WHERE name = 'SMOKE Beta'");
$wed = day($w['w1'], 2);

echo "1. As of today\n";
[$c, $d] = report_json($owner, 'wip');
ok($c === 200 && count($d['rows']) === 1 && $d['rows'][0]['engagement_number'] === 'ENG-0001', 'one engagement has unbilled work: ENG-0001');
$r = $d['rows'][0];
ok(same($r['time_hours'], 3) && same($r['time_amount'], 540) && same($r['time_no_rate_hours'], 4) && same($r['expense_amount'], 20) && same($r['usage_amount'], 60) && same($r['milestone_amount'], 0) && same($r['total_amount'], 620) && $r['oldest_item'] === $wed, 'time 3 h = 540.00, no-rate 4 h, expenses 20.00, usage 60.00, milestones 0 → 620.00; oldest ' . $wed);
ok($d['totals'][0]['currency'] === 'USD' && same($d['totals'][0]['total_amount'], 620) && same($d['totals'][0]['time_no_rate_hours'], 4), 'the totals row: 620.00 USD');
ok($d['cells']['rows'][0] === ['ENG-0001 SMOKE Time Acme', 'SMOKE Acme', '3 h', '540.00 USD', '4 h', '20.00 USD', '60.00 USD', '0.00 USD', '620.00 USD', $wed], 'the row as cells: ' . implode(' | ', $d['cells']['rows'][0]));

echo "2. As of an earlier day, and the filters\n";
[$c, $d] = report_json($owner, 'wip', ['as_of' => day($w['w1'], 1)]);
ok($d['rows'] === [], 'as of Tuesday of W1: nothing was approved and unbilled that long ago (the billed hours are on INV-00001)');
[$c, $d] = report_json($owner, 'wip', ['as_of' => $wed]);
$r = $d['rows'][0] ?? [];
ok(count($d['rows']) === 1 && same($r['total_amount'], 0) && same($r['time_no_rate_hours'], 4) && $r['oldest_item'] === $wed, 'as of that Wednesday: only the 4 no-rate hours — total 0.00 — and the engagement stays listed because of them');
ok($d['filters']['as_of'] === $wed, 'the date asked is echoed');
[$c, $d] = report_json($owner, 'wip', ['client' => $client]);
ok(count($d['rows']) === 1, 'client = Acme: its row');
[$c, $d] = report_json($owner, 'wip', ['client' => $other]);
ok($d['rows'] === [], 'client = Beta: none');
[$c, $d] = report_json($owner, 'wip', ['engagement' => $w['beta']]);
ok($d['rows'] === [], 'engagement = Beta: none');

echo "3. The page and who sees\n";
$b = page($owner, '/reports/wip')['body'];
ok(str_contains($b, 'id="report-table"') && str_contains($b, 'name="as_of"') && str_contains($b, 'name="client"') && !str_contains($b, 'name="from"'), 'an "as of" date and the client, no period');
ok(str_contains($b, '/billing/unbilled/' . $w['acme']), 'the engagement opens its unbilled items (/billing/unbilled/{id})');
ok(str_contains($b, '620.00 USD') && str_contains($b, '540.00 USD'), 'amounts with their currency');
[$c, $d] = report_json($marco, 'wip');
ok($c === 200 && count($d['rows']) === 1 && same($d['rows'][0]['total_amount'], 620), 'Marco sees Acme\'s unbilled work (he manages it)');
[$c, $d] = report_json($ann, 'wip');
ok($c === 200 && same($d['totals'][0]['total_amount'], 620), 'Finance sees it too');
[$c, $d] = report_json($priya, 'wip');
ok($c === 403, 'Priya is refused: 403');
finish();
