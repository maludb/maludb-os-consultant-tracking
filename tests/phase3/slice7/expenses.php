<?php
/**
 * Proof — the expense report (spec "Proof", 1: the reimbursable column). mcp_expenses grouped, by hand. Priya on Acme, category Meals, all approved: hotel 100.00 (Sep 15), taxi 40.00 (Sep 16), taxi 20.00 (W4) = 3 expenses, 160.00, no tax;
 * billable 160.00 (no markup), reimbursable 160.00 (personal card), reimbursed 40.00 (Bea marked the 40.00 taxi paid, PAYRUN-RPT). W1 alone: two expenses, 140.00, reimbursed 40.00.
 */
require __DIR__ . '/lib.php';
$w = report_world();
$owner = as_member(1); $priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29);
$meals = category('meals');

echo "1. The figures\n";
[$c, $d] = report_json($owner, 'expenses', whole());
ok($c === 200 && count($d['rows']) === 1 && $d['filters']['status'] === 'approved', 'one line (approved is the default status)');
$r = $d['rows'][0];
ok($r['member_name'] === 'SMOKE Priya' && $r['engagement_number'] === 'ENG-0001' && $r['category_label'] === 'Meals' && $r['expenses'] === 3, 'Priya · ENG-0001 · Meals · 3 expenses');
ok(same($r['amount'], 160) && same($r['tax_amount'], 0) && same($r['billable_amount'], 160) && same($r['reimbursable_amount'], 160) && same($r['reimbursed_amount'], 40), 'amount 160.00, tax 0.00, billable 160.00, reimbursable 160.00, reimbursed 40.00');
ok(same($d['totals'][0]['amount'], 160) && same($d['totals'][0]['reimbursed_amount'], 40) && $d['totals'][0]['expenses'] === 3, 'the totals row');
ok($d['cells']['header'] === ['Person', 'Engagement', 'Category', 'Expenses', 'Amount', 'Tax', 'Billable', 'Reimbursable', 'Reimbursed'] && $d['cells']['rows'][0] === ['SMOKE Priya', 'ENG-0001', 'Meals', '3', '160.00 USD', '0.00 USD', '160.00 USD', '160.00 USD', '40.00 USD'], 'columns and the row as cells');
[$c, $d] = report_json($owner, 'expenses', ['from' => $w['w1'], 'to' => day($w['w1'], 6)]);
ok($d['rows'][0]['expenses'] === 2 && same($d['rows'][0]['amount'], 140) && same($d['rows'][0]['reimbursed_amount'], 40), 'W1: 2 expenses, 140.00, 40.00 reimbursed');

echo "2. The filters\n";
foreach ([['status' => 'all'], ['member' => 26], ['engagement' => $w['acme']], ['category' => $meals]] as $f) {
    [$c, $d] = report_json($owner, 'expenses', whole() + $f);
    ok(count($d['rows']) === 1 && $d['rows'][0]['expenses'] === 3, 'filter ' . json_encode($f) . ': the same line');
}
foreach ([['status' => 'submitted'], ['status' => 'draft'], ['status' => 'rejected'], ['member' => 30], ['engagement' => $w['beta']], ['category' => category('travel')]] as $f) {
    [$c, $d] = report_json($owner, 'expenses', whole() + $f);
    ok($d['rows'] === [] && $d['totals'] === [], 'filter ' . json_encode($f) . ': none');
}
[$c, $d] = report_json($owner, 'expenses', whole() + ['status' => 'x']);
ok($d['filters']['status'] === 'approved', 'an unknown status is approved');

echo "3. Who sees\n";
[$c, $d] = report_json($marco, 'expenses', whole());
ok($c === 200 && same($d['totals'][0]['amount'], 160), 'Marco (approver of the engagement): the line');
[$c, $d] = report_json($ann, 'expenses', whole());
ok($c === 200 && same($d['totals'][0]['reimbursed_amount'], 40), 'Finance: the line');
ok(req('GET', '/reports/expenses', ['jar' => $priya, 'headers' => JSONH])['code'] === 403, 'Priya, whose own expenses these are, is refused the firm\'s report (she has /expenses): 403');
$b = page($owner, '/reports/expenses?' . http_build_query(whole()))['body'];
ok(str_contains($b, 'id="report-table"') && str_contains($b, 'name="member"') && str_contains($b, 'name="engagement"') && str_contains($b, 'name="category"') && str_contains($b, 'name="status"') && str_contains($b, '160.00 USD'), 'the page: the four filters beside the period; table; money with its currency');
finish();
