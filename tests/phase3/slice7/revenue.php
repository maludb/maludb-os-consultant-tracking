<?php
/**
 * Proof — revenue by each grouping (spec "Proof", 1). INV-00001 (Acme, issued today) has lines: time 1,260.00 (Priya 7 h at 180) + 875.00 (Dana 7 h at 125), expenses 100.00 + 40.00, usage 50.00 + 4.00 + 30.00 + 120.00 = 2,479.00, no tax;
 * INV-00002 (Bea's engagement "SMOKE rpt Beas eng", client SMOKE Beta) is one fixed line of 300.00. Both issued today. Practice: Acme belongs to its creator Marco's AI Strategy, Bea's to Accounting.
 *   by client:     SMOKE Acme 1 invoice 2,479.00 · SMOKE Beta 1 invoice 300.00 · total 2 invoices 2,779.00
 *   by engagement: ENG-0001 2,479.00 · ENG-0003 300.00
 *   by kind:       time 2,135.00 · expense 140.00 · usage 204.00 · fixed 300.00 (an invoice counts under every kind it holds: the total row leaves the invoice count blank)
 *   by practice:   AI Strategy 2,479.00 · Accounting 300.00
 *   by month:      this month 2 invoices 2,779.00
 */
require __DIR__ . '/lib.php';
$w = report_world();
$owner = as_member(1); $priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29);
$p = whole();

echo "1. Each grouping\n";
[$c, $d] = report_json($owner, 'revenue', $p + ['by' => 'client']);
ok($c === 200 && $d['filters']['by'] === 'client' && count($d['rows']) === 2, 'by client: two clients');
$a = row_of($d, 'group_label', 'SMOKE Acme'); $b = row_of($d, 'group_label', 'SMOKE Beta');
ok($a['invoices'] === 1 && same($a['subtotal'], 2479) && same($a['tax'], 0) && same($a['total'], 2479) && $b['invoices'] === 1 && same($b['total'], 300), 'Acme 1 invoice 2,479.00; Beta 1 invoice 300.00; no tax');
$t = $d['totals'][0];
ok($t['currency'] === 'USD' && $t['invoices'] === 2 && same($t['subtotal'], 2779) && same($t['tax'], 0) && same($t['total'], 2779), 'totals: 2 invoices, 2,779.00 USD');
ok($d['cells']['header'] === ['Client', 'Invoices', 'Subtotal', 'Tax', 'Total'] && $d['cells']['totals'][0] === ['Total', '2', '2,779.00 USD', '0.00 USD', '2,779.00 USD'], 'columns and the totals as cells');
[$c, $d] = report_json($owner, 'revenue', $p);
ok($d['filters']['by'] === 'client' && count($d['rows']) === 2, 'no grouping asked: by client');
[$c, $d] = report_json($owner, 'revenue', $p + ['by' => 'engagement']);
ok(count($d['rows']) === 2 && same(row_of($d, 'group_label', 'ENG-0001 SMOKE Time Acme')['total'], 2479) && same(row_of($d, 'group_label', 'ENG-0003 SMOKE rpt Beas eng')['total'], 300) && $d['cells']['header'][0] === 'Engagement', 'by engagement: ENG-0001 2,479.00 · ENG-0003 300.00');
[$c, $d] = report_json($owner, 'revenue', $p + ['by' => 'kind']);
ok(count($d['rows']) === 4 && same(row_of($d, 'group_label', 'Time')['total'], 2135) && same(row_of($d, 'group_label', 'Expense')['total'], 140) && same(row_of($d, 'group_label', 'Usage')['total'], 204) && same(row_of($d, 'group_label', 'Fixed')['total'], 300), 'by kind: time 2,135.00 · expense 140.00 · usage 204.00 · fixed 300.00');
ok($d['totals'][0]['invoices'] === null && same($d['totals'][0]['total'], 2779) && $d['cells']['totals'][0][1] === '—', 'by kind the invoice total is blank (an invoice is counted under each kind it holds), the amounts total 2,779.00');
[$c, $d] = report_json($owner, 'revenue', $p + ['by' => 'practice']);
ok(count($d['rows']) === 2 && same(row_of($d, 'group_label', 'AI Strategy')['total'], 2479) && same(row_of($d, 'group_label', 'Accounting')['total'], 300), 'by practice: AI Strategy 2,479.00 · Accounting 300.00');
[$c, $d] = report_json($owner, 'revenue', $p + ['by' => 'month']);
$month = date('Y-m');
ok(count($d['rows']) === 1 && $d['rows'][0]['group_label'] === $month && $d['rows'][0]['invoices'] === 2 && same($d['rows'][0]['total'], 2779), "by month: $month, 2 invoices, 2,779.00");
[$c, $d] = report_json($owner, 'revenue', $p + ['by' => 'drop table']);
ok($c === 200 && $d['filters']['by'] === 'client', 'an unknown grouping falls back to client');

echo "2. The period\n";
[$c, $d] = report_json($owner, 'revenue', ['from' => '2026-09-01', 'to' => '2026-09-30', 'by' => 'client']);
ok($d['rows'] === [] && $d['totals'] === [], 'September: nothing was issued then');
$today = date('Y-m-d');
[$c, $d] = report_json($owner, 'revenue', ['from' => $today, 'to' => $today]);
ok(count($d['rows']) === 2, 'a one-day period that is today: both invoices');

echo "3. Who sees\n";
[$c, $d] = report_json($ann, 'revenue', $p);
ok($c === 200 && same($d['totals'][0]['total'], 2779), 'Finance: the firm\'s revenue');
[$c, $d] = report_json($bea, 'revenue', $p);
ok($c === 200 && same($d['totals'][0]['total'], 2779), 'Billing: the same');
$r = req('GET', '/reports/revenue?' . http_build_query($p), ['jar' => $marco, 'headers' => JSONH]);
ok($r['code'] === 403 && str_contains($r['body'], "You may not see revenue across the firm; it is Billing's and Finance's."), 'a Manager is refused in words: 403 "…it is Billing\'s and Finance\'s."');
ok(req('GET', '/reports/revenue', ['jar' => $priya, 'headers' => JSONH])['code'] === 403, 'a Consultant: 403');
// the function itself: this is why the page keeps a Manager out (spec "Open questions")
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
$seen = (int) one("SELECT count(*) FROM ct_revenue(CAST(:f AS date), CAST(:t AS date), 'engagement')", ['f' => $p['from'], 't' => $p['to']]);
ok($seen === 2, 'for the record: ct_revenue answers Marco BOTH invoices, including Bea\'s engagement he does not manage (db/014 admits any reports.view holder to every invoice) — the screen refuses him until the database scopes it');
pdo()->exec("SELECT set_config('app.member_id', '1', false)");

echo "4. The page\n";
$r = page($owner, '/reports/revenue?' . http_build_query($p + ['by' => 'kind']));
ok($r['code'] === 200 && str_contains($r['body'], 'id="report-table"') && str_contains($r['body'], 'id="report-totals"') && str_contains($r['body'], '<option value="kind" selected>') && str_contains($r['body'], 'name="by"'), 'the grouping picker, the table, the totals');
ok(!str_contains($r['body'], 'table-light'), 'no row is styled as a subtotal (only utilization by practice has them)');
ok(str_contains($r['body'], '2,135.00 USD') && str_contains($r['body'], '2,779.00 USD'), 'amounts carry the currency');
finish();
