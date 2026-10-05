<?php
/**
 * Proof — budget burn (spec "Proof", 1). ct_budget_status per engagement the caller sees, by hand. Acme (T&M, budget 400 h and 60,000.00 from the time world, started 2026-01-05):
 *   hours used 23 (18 + 2 written off + 3, nothing rejected) → 5.75 → 5.8 %; amount used = time at bill value 1,260 + 875 + 250 + 540 = 2,925 + expenses 160 + usage 264 = 3,349.00 → 5.58 → 5.6 % of 60,000.00;
 *   invoiced 2,479.00 (INV-00001; no not-to-exceed set); AI cost 244.00 (no AI budget); pace = 23 h / 39 weeks (273 days since 2026-01-05 when the suites were written — read from the row) .
 * Beta (fixed bid, 20,000.00): nothing used. Bea's engagement: 300.00 invoiced, no budget. Marco sees only what he may see (Acme, Beta).
 */
require __DIR__ . '/lib.php';
$w = report_world();
$owner = as_member(1); $priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29);

echo "1. The figures\n";
[$c, $d] = report_json($owner, 'budget-burn');
ok($c === 200 && array_column($d['rows'], 'engagement_number') === ['ENG-0001', 'ENG-0002', 'ENG-0003'] && $d['filters']['status'] === 'active', 'the active engagements: ENG-0001, 0002 and 0003');
$a = row_of($d, 'engagement_number', 'ENG-0001');
ok(same($a['hours_used'], 23) && same($a['budget_hours'], 400) && same($a['hours_pct'], 5.8), 'Acme hours: 23 of 400 = 5.8 %');
ok(same($a['amount_used'], 3349) && same($a['budget_amount'], 60000) && same($a['amount_pct'], 5.6), 'Acme amount: 3,349.00 of 60,000.00 = 5.6 %');
ok(same($a['invoiced_total'], 2479) && $a['nte_amount'] === null && $a['nte_pct'] === null, 'invoiced 2,479.00, no NTE');
ok(same($a['ai_cost'], 244) && $a['ai_budget_amount'] === null && $a['ai_pct'] === null, 'AI cost 244.00 (at cost), no AI budget');
$days = (int) one("SELECT current_date - DATE '2026-01-05'"); $weeks = max(1, $days / 7.0); $pace = round(23 / $weeks, 2);
ok(same($a['pace_hours_per_week'], $pace) && same($a['weeks_left_at_pace'], round((400 - 23) / $pace, 1)), "pace $pace h a week over " . round($weeks, 2) . " weeks; weeks left " . round((400 - 23) / $pace, 1));
$b = row_of($d, 'engagement_number', 'ENG-0002');
ok(same($b['hours_used'], 0) && same($b['amount_used'], 0) && same($b['amount_pct'], 0) && $b['budget_hours'] === null && same($b['budget_amount'], 20000), 'Beta: nothing used of 20,000.00; no hours budget');
$e = row_of($d, 'engagement_number', 'ENG-0003');
ok(same($e['invoiced_total'], 300) && $e['budget_amount'] === null, 'Bea\'s: 300.00 invoiced, no budget');
ok($d['totals'] === [], 'no totals row (a sum of budgets across engagements means nothing)');
$cells = $d['cells']['rows'][0];
ok($cells[2] === '23 h of 400 h (5.8 %)' && $cells[3] === '3,349.00 USD of 60,000.00 USD (5.6 %)' && $cells[4] === '2,479.00 USD (no budget)' && $cells[5] === '244.00 USD (no budget)', 'the cells: ' . implode(' | ', array_slice($cells, 2, 4)));
ok($d['cells']['header'] === ['Engagement', 'Client', 'Hours', 'Amount', 'Invoiced / NTE', 'AI cost / budget', 'Pace (h/week)', 'Weeks left'], 'the columns');

echo "2. The filters\n";
[$c, $d] = report_json($owner, 'budget-burn', ['status' => 'draft']);
ok($d['rows'] === [], 'status = draft: none');
[$c, $d] = report_json($owner, 'budget-burn', ['status' => 'all']);
ok(count($d['rows']) === 3, 'status = every: the same three');
[$c, $d] = report_json($owner, 'budget-burn', ['status' => 'weird']);
ok($d['filters']['status'] === 'active', 'an unknown status is active');
$dept = (int) one("SELECT id FROM departments WHERE name = 'Accounting'");
[$c, $d] = report_json($owner, 'budget-burn', ['department' => $dept]);
ok(array_column($d['rows'], 'engagement_number') === ['ENG-0003'], 'practice = Accounting (Bea\'s): ENG-0003');
[$c, $d] = report_json($owner, 'budget-burn', ['manager' => 28]);
ok(array_column($d['rows'], 'engagement_number') === ['ENG-0003'], 'manager = Bea: ENG-0003');
[$c, $d] = report_json($owner, 'budget-burn', ['manager' => 27]);
ok(array_column($d['rows'], 'engagement_number') === ['ENG-0001', 'ENG-0002'], 'manager = Marco: ENG-0001 and 0002');
q("UPDATE engagements SET status = 'on_hold' WHERE id = :e", ['e' => $w['beta']]);
[$c, $d] = report_json($owner, 'budget-burn', ['status' => 'on_hold']);
$held = array_column($d['rows'], 'engagement_number');
q("UPDATE engagements SET status = 'active' WHERE id = :e", ['e' => $w['beta']]);
ok($held === ['ENG-0002'] || $held === [], 'status = on hold finds an engagement the owner put on hold (' . implode(',', $held) . ') — and the move is undone');

echo "3. Who sees\n";
[$c, $d] = report_json($marco, 'budget-burn');
ok($c === 200 && array_column($d['rows'], 'engagement_number') === ['ENG-0001', 'ENG-0002'] && in_array('AI cost / budget', $d['cells']['header'], true), 'Marco: his two engagements, with the AI column (he holds cost.view)');
[$c, $d] = report_json($ann, 'budget-burn');
ok($c === 200 && count($d['rows']) === 3, 'Finance: all three');
ok(req('GET', '/reports/budget-burn', ['jar' => $priya, 'headers' => JSONH])['code'] === 403, 'a Consultant: 403');

echo "4. The page\n";
$r = page($owner, '/reports/budget-burn');
$bd = $r['body'];
ok($r['code'] === 200 && str_contains($bd, 'id="report-table"') && substr_count($bd, 'class="progress') >= 3 && str_contains($bd, 'name="status"') && str_contains($bd, 'name="department"') && str_contains($bd, 'name="manager"') && !str_contains($bd, 'name="from"'), 'bars in the table; status, practice and manager filters; no period');
ok(str_contains($bd, 'progress-bar bg-success') && !str_contains($bd, 'progress-bar bg-danger') && !str_contains($bd, 'progress-bar bg-warning'), 'a 5 % burn is a green bar');
ok(str_contains($bd, '23 h of 400 h (5.8 %)') && str_contains($bd, '3,349.00 USD of 60,000.00 USD'), 'the figures as text beside the bars');
finish();
