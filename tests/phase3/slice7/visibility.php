<?php
/**
 * Proof — who sees what (spec "Proof", 3). The matrix of nine reports × six people, then each person's index and what the functions answer them:
 *   Priya and Dana (Consultants, reports.view_own): utilization — their own row alone — and nothing else (the other eight 403 in words; the index shows one card).
 *   Marco (Manager, reports.view and cost.view on his engagements): everything, revenue scoped to his engagements (db/018); his engagement pickers hold his engagements only.
 *   Bea (Billing), Ann (Finance), the owner: everything — nine reports and the aging.
 */
require __DIR__ . '/lib.php';
$w = report_world();
$owner = as_member(1); $priya = as_member(26); $dana = as_member(30); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29);
$reports = ['utilization', 'realization', 'wip', 'revenue', 'margin', 'budget-burn', 'ai-passthrough', 'expenses', 'missing-time'];
$who = ['owner' => $owner, 'Priya' => $priya, 'Dana' => $dana, 'Marco' => $marco, 'Bea' => $bea, 'Ann' => $ann];
$expect = [
    'owner' => array_fill_keys($reports, 200), 'Bea' => array_fill_keys($reports, 200), 'Ann' => array_fill_keys($reports, 200),
    'Marco' => array_fill_keys($reports, 200), 'Priya' => array_fill_keys($reports, 403), 'Dana' => array_fill_keys($reports, 403),
];
$expect['Priya']['utilization'] = 200; $expect['Dana']['utilization'] = 200;

echo "1. The matrix (54 requests)\n";
foreach ($who as $n => $jar) {
    $got = [];
    foreach ($reports as $r) { $got[$r] = req('GET', '/reports/' . $r . (in_array($r, ['realization', 'revenue', 'margin', 'ai-passthrough', 'expenses', 'utilization'], true) ? '?' . http_build_query(whole()) : ''), ['jar' => $jar, 'headers' => JSONH])['code']; }
    ok($got === $expect[$n], "$n: " . implode(' ', array_map(static fn ($k, $v) => "$k=$v", array_keys($got), $got)));
}

echo "2. The index\n";
$cards = static fn ($jar): array => array_column(screen($jar, '/reports/')[1]['reports'], 'key');
ok($cards($priya) === ['utilization'] && $cards($dana) === ['utilization'], 'a Consultant: one card, utilization');
ok($cards($marco) === ['utilization', 'realization', 'wip', 'revenue', 'margin', 'budget-burn', 'ai-passthrough', 'expenses', 'missing-time'], 'Marco: nine cards (revenue scoped to his engagements since db/018; no aging)');
foreach (['owner' => $owner, 'Bea' => $bea, 'Ann' => $ann] as $n => $jar) {
    ok($cards($jar) === array_merge($reports, ['ar-aging']), "$n: the nine reports and who owes us");
}
$b = page($priya, '/reports/')['body'];
ok(str_contains($b, 'id="report-card-utilization"') && !str_contains($b, 'id="report-card-realization"') && str_contains($b, 'id="reports-cards"'), 'the page: one card for Priya');
$b = page($bea, '/reports/')['body'];
ok(substr_count($b, 'id="report-card-') >= 10 && str_contains($b, 'id="report-card-ar-aging"') && str_contains($b, 'Open invoices by client'), 'Billing\'s page: ten cards, each a line on what it answers');
ok(screen($bea, '/reports/')[1]['may']['export'] === true && screen($marco, '/reports/')[1]['may']['export'] === false, 'the index says who may export (Billing yes, Marco no)');

echo "3. The words of a refusal\n";
$m = static fn ($jar, $path): string => (string) (json_decode(req('GET', $path, ['jar' => $jar, 'headers' => JSONH])['body'], true)['error']['message'] ?? '');
ok($m($priya, '/reports/realization') === 'You may not see reports.' && $m($priya, '/reports/wip') === 'You may not see reports.' && $m($priya, '/reports/budget-burn') === 'You may not see reports.', 'Priya, any firm report: "You may not see reports."');
ok($m($priya, '/reports/margin') === 'You may not see cost and margin.', 'margin: "You may not see cost and margin."');
ok(req('GET', '/reports/nonsense', ['jar' => $owner, 'headers' => JSONH])['code'] === 404 && req('GET', '/reports/Utilization', ['jar' => $owner, 'headers' => JSONH])['code'] === 404, 'a report not in the list (or in another case): 404');
ok(req('GET', '/reports/utilization', ['jar' => jar(), 'headers' => JSONH])['code'] === 401, 'no session: 401');

echo "4. A Consultant's utilization is her own\n";
[$c, $d] = report_json($priya, 'utilization', ['from' => $w['w1'], 'to' => day($w['w1'], 6)]);
ok($c === 200 && count($d['rows']) === 1 && $d['rows'][0]['member_name'] === 'SMOKE Priya' && same($d['rows'][0]['utilization_pct'], 17.5) && same($d['totals'][0]['capacity_hours'], 40), 'Priya: her row alone — 7 h of 40 h = 17.5 % — and a totals row of the same');
[$c, $d] = report_json($priya, 'utilization', ['from' => $w['w1'], 'to' => day($w['w1'], 6), 'member' => 28]);
ok($c === 200 && $d['rows'] === [], 'asking for Bea\'s row: nothing (the function, not the page, keeps her to herself)');
[$c, $d] = report_json($dana, 'utilization', ['from' => $w['w1'], 'to' => day($w['w1'], 6), 'by' => 'practice']);
ok(count($d['rows']) === 2 && $d['rows'][0]['member_name'] === 'SMOKE Dana' && !empty($d['rows'][1]['is_subtotal']), 'Dana, grouped by practice: her row and her practice\'s subtotal (hers alone)');
$r = page($priya, '/reports/utilization?' . http_build_query(['from' => $w['w1'], 'to' => day($w['w1'], 6)]));
ok($r['code'] === 200 && str_contains($r['body'], 'SMOKE Priya') && !str_contains($r['body'], 'SMOKE Bea') && !str_contains($r['body'], 'id="report-csv-btn"'), 'her page lists no one else and offers no CSV');
ok(!str_contains($r['body'], 'name="member"') && !str_contains($r['body'], 'name="department"'), 'and no person or practice picker (no one else to pick)');

echo "5. A Manager's pickers and figures hold his engagements only\n";
$b = page($marco, '/reports/realization?' . http_build_query(whole()))['body'];
ok(str_contains($b, 'ENG-0001 SMOKE Time Acme') && str_contains($b, 'ENG-0002 SMOKE Time Beta') && !str_contains($b, 'ENG-0003'), 'the engagement picker: Acme and Beta, not Bea\'s ENG-0003');
[$c, $d] = report_json($marco, 'budget-burn');
ok(array_column($d['rows'], 'engagement_number') === ['ENG-0001', 'ENG-0002'], 'budget burn: his two');
[$c, $d] = report_json($marco, 'realization', whole());
ok(count($d['rows']) === 1 && same($d['rows'][0]['approved_hours'], 23), 'realization: Acme\'s row, the same figures the owner sees');
[$c, $d] = report_json($marco, 'realization', whole() + ['engagement' => $w['beas']]);
ok($d['rows'] === [], 'asking for Bea\'s engagement by number: nothing');
[$c, $d] = report_json($marco, 'ai-passthrough', whole() + ['engagement' => $w['beas']]);
ok($d['rows'] === [], 'and in AI pass-through: nothing');
[$c, $d] = report_json($marco, 'wip', ['engagement' => $w['beas']]);
ok($d['rows'] === [], 'and in unbilled work: nothing');
[$c, $d] = report_json($marco, 'margin', whole() + ['engagement' => $w['beas']]);
ok($d['rows'] === [], 'and in margin: nothing');
[$c, $d] = report_json($marco, 'expenses', whole() + ['engagement' => $w['beas']]);
ok($d['rows'] === [], 'and in the expense report: nothing');

echo "6. Finance reads everything and downloads, never writes\n";
$since = last_activity_id();
$wr = req('POST', '/reports/utilization', ['jar' => $ann, 'headers' => JSONH, 'form' => ['x' => 1]]);
ok((int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action NOT IN ('screen.view')", ['s' => $since]) === 0, 'a POST to a report page writes nothing (no handler under /reports changes anything; it answered ' . $wr['code'] . ')');
$r = req('GET', '/reports/margin?' . http_build_query(whole()), ['jar' => $ann]);
ok($r['code'] === 200 && str_contains($r['body'], 'Every engagement.') && str_contains($r['body'], 'id="report-csv-btn"'), 'Ann: margin for every engagement, with the CSV button');
finish();
