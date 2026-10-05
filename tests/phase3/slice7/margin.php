<?php
/**
 * Proof — margin and who sees cost (spec "Proof", 1; §16 c and f). ct_margin over the report world, by hand. Acme, the whole period:
 *   bill value  = time approved and billable 1,260 + 875 (W1) + 540 (W4) = 2,675 (the owner's no-rate hours bill nothing; the written-off 2 h never bill) + expenses 100 + 40 + 20 = 160 + usage 50 + 4 + 30 + 120 + 60 = 264 = 3,099.00;
 *   time cost   = Priya 10 h × 70 = 700 + Dana (7 + 2 written-off) × 55 = 495 = 1,195.00 (the owner has no cost rate: 0); expense cost 160.00; usage cost 50 + 4 + 30 + 100 + 60 = 244.00; total cost 1,599.00;
 *   margin 3,099 − 1,599 = 1,500.00 → 48.4 % (1500/3099 = 48.40).
 * W1 alone: bill 1,260 + 875 + 100 + 40 = 2,275.00, cost 875 + 140 = 1,015.00 → margin 1,260.00, 55.4 %. W2 alone: only Dana's written-off 2 h: bill 0, cost 110.00, margin (110.00), no percentage.
 */
require __DIR__ . '/lib.php';
$w = report_world();
$owner = as_member(1); $priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29); $dana = as_member(30);

echo "1. The figures\n";
[$c, $d] = report_json($owner, 'margin', whole());
ok($c === 200 && count($d['rows']) === 1 && $d['rows'][0]['engagement_number'] === 'ENG-0001' && $d['scope'] === 'Every engagement', 'one engagement has activity; the page says "Every engagement" for the owner');
$r = $d['rows'][0];
ok(same($r['bill_value'], 3099) && same($r['time_cost'], 1195) && same($r['expense_cost'], 160) && same($r['usage_cost'], 244) && same($r['total_cost'], 1599) && same($r['margin'], 1500) && same($r['margin_pct'], 48.4), 'bill 3,099.00 − cost 1,599.00 (time 1,195.00, expenses 160.00, usage 244.00) = 1,500.00 = 48.4 %');
ok(same($d['totals'][0]['margin'], 1500) && same($d['totals'][0]['margin_pct'], 48.4) && $d['totals'][0]['currency'] === 'USD', 'the totals row');
ok($d['cells']['rows'][0] === ['ENG-0001 SMOKE Time Acme', 'SMOKE Acme', '3,099.00 USD', '1,195.00 USD', '160.00 USD', '244.00 USD', '1,599.00 USD', '1,500.00 USD', '48.4 %'], 'the row as cells: ' . implode(' | ', $d['cells']['rows'][0]));
[$c, $d] = report_json($owner, 'margin', ['from' => $w['w1'], 'to' => day($w['w1'], 6)]);
$r = $d['rows'][0];
ok(same($r['bill_value'], 2275) && same($r['total_cost'], 1015) && same($r['usage_cost'], 0) && same($r['margin'], 1260) && same($r['margin_pct'], 55.4), 'W1: bill 2,275.00, cost 1,015.00, margin 1,260.00 = 55.4 %');
[$c, $d] = report_json($owner, 'margin', ['from' => $w['w2'], 'to' => day($w['w2'], 6)]);
$r = $d['rows'][0];
ok(same($r['bill_value'], 0) && same($r['time_cost'], 110) && same($r['margin'], -110) && $r['margin_pct'] === null, 'W2: written-off hours cost 110.00 and bill nothing — margin (110.00), no percentage');
ok($d['cells']['rows'][0][7] === '(110.00) USD' && $d['cells']['rows'][0][8] === '—', 'shown negative in parentheses, the percentage as a dash');
$b = page($owner, '/reports/margin?' . http_build_query(['from' => $w['w2'], 'to' => day($w['w2'], 6)]))['body'];
ok(str_contains($b, 'text-danger">(110.00) USD'), 'a negative margin is red');
[$c, $d] = report_json($owner, 'margin', whole() + ['engagement' => $w['beta']]);
ok($d['rows'] === [], 'engagement = Beta (no activity): none');
[$c, $d] = report_json($owner, 'margin', ['from' => '2026-01-01', 'to' => '2026-01-31']);
ok($d['rows'] === [] && $d['totals'] === [], 'a period without activity: none');

echo "2. Who sees\n";
[$c, $d] = report_json($marco, 'margin', whole());
ok($c === 200 && $d['scope'] === 'Your engagements' && count($d['rows']) === 1 && same($d['rows'][0]['margin'], 1500), 'Marco: his engagement\'s margin, the page says "Your engagements"');
$b = page($marco, '/reports/margin?' . http_build_query(whole()))['body'];
ok(str_contains($b, 'id="report-scope"') && str_contains($b, 'Your engagements: the engagements you manage.'), 'and shows it above the table');
$b = page($bea, '/reports/margin?' . http_build_query(whole()))['body'];
ok(str_contains($b, 'Every engagement.') && str_contains($b, '1,500.00 USD'), 'Billing: "Every engagement"');
[$c, $d] = report_json($ann, 'margin', whole());
ok($c === 200 && $d['scope'] === 'Every engagement' && same($d['rows'][0]['margin'], 1500), 'Finance: every engagement');
$r = req('GET', '/reports/margin', ['jar' => $priya, 'headers' => JSONH]);
ok($r['code'] === 403 && str_contains($r['body'], 'You may not see cost and margin.'), 'a Consultant: 403 "You may not see cost and margin."');
ok(req('GET', '/reports/margin', ['jar' => $dana, 'headers' => JSONH])['code'] === 403, 'Dana too');
$cards = array_column(screen($priya, '/reports/')[1]['reports'], 'key');
ok(!in_array('margin', $cards, true), 'the index offers a Consultant no margin card');
echo "3. A Manager with no engagement of their own: the margin page is absent\n";
q('UPDATE engagements SET manager_member_id = 1 WHERE manager_member_id = 27');
$deptAdmin = (int) one("SELECT count(*) FROM department_members WHERE member_id = 27 AND is_admin");
$r = req('GET', '/reports/margin', ['jar' => $marco, 'headers' => JSONH]);
ok($r['code'] === 403 && str_contains($r['body'], 'You manage no engagement, so there is no margin to show you.'), 'Marco, managing nothing: 403 in words' . ($deptAdmin ? ' (he is also a department admin — the test would not hold)' : ''));
$cards = array_column(screen($marco, '/reports/')[1]['reports'], 'key');
ok(!in_array('margin', $cards, true) && in_array('realization', $cards, true), 'the index drops the margin card and keeps the others');
q('UPDATE engagements SET manager_member_id = 27 WHERE id IN (:a, :b)', ['a' => $w['acme'], 'b' => $w['beta']]);
ok(report_json($marco, 'margin', whole())[0] === 200, 'managing again: the page is back');
finish();
