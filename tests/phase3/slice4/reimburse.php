<?php
/** Proof — reimbursements (spec "Proof", 4): Priya's approved personal-card expense shown as owed (the amount paid; 132.00 is what the client is billed); Bea marks it with PAYRUN-2026-03 (the notice); the company-card one is not offered and is refused; Priya sees her own owed total; all of a person's with one reference, all or nothing; the company-card page sums by category and month. */
require __DIR__ . '/lib.php';
[$acme, $beta] = expense_world();
$priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $dana = as_member(30);
$e1 = (int) one("SELECT id FROM expenses WHERE number = 'EXP-000001'");
$e2 = (int) one("SELECT id FROM expenses WHERE description = 'SMOKE coffee'");
$e1 > 0 && $e2 > 0 && expense_row($e1)['status'] === 'approved' or exit(fwrite(STDERR, "run record.php and approve.php first\n") ?: 2);

echo "1. What is owed\n";
$owedP = (float) one("SELECT COALESCE(sum(amount), 0) FROM expenses WHERE member_id = 26 AND status = 'approved' AND paid_with = 'personal' AND reimbursed_at IS NULL");
[$c, $d] = screen($bea, '/expenses/reimbursements');
$g = array_values(array_filter($d['people'], fn ($p) => $p['member']['member_id'] === 26))[0] ?? null;
$ids = array_column($g['expenses'] ?? [], 'expense_id');
ok($c === 200 && $g !== null && in_array($e1, $ids, true) && (float) $g['totals']['USD'] === $owedP && (float) array_sum(array_column($g['expenses'], 'amount')) === $owedP, 'Bea\'s page: Priya is owed ' . number_format($owedP, 2) . ' USD, the approved personal-card expenses not yet paid');
ok(!in_array($e2, $ids, true) && !in_array($e2, array_merge(...array_map(fn ($p) => array_column($p['expenses'], 'expense_id'), $d['people'])), true), 'the company-card coffee is not offered');
$one = array_values(array_filter($g['expenses'], fn ($x) => $x['expense_id'] === $e1))[0];
ok($one['amount'] === '120.00' && $one['billable_amount'] === '132.00', 'EXP-000001 is owed at 120.00 (what she paid); 132.00 is what the client is billed');
$body = page($bea, '/expenses/reimbursements')['body'];
ok(str_contains($body, 'id="reimbursements-26"') && str_contains($body, 'id="reimbursements-26-total"') && str_contains($body, 'id="reimbursements-26-reference"') && str_contains($body, 'name="expenses[]"'), 'the page: a card per person, the total, a reference, a tick per expense');
[$c, $d] = screen($priya, '/expenses/');
ok($c === 200 && (float) $d['owed']['USD'] === $owedP, 'Priya sees her own owed total on her list (' . $d['owed']['USD'] . ' USD)');
$home = screen($priya, '/')[1];
ok((float) $home['my_expenses']['owed']['USD'] === $owedP && $home['my_expenses']['owed_count'] >= 1 && !isset($home['my_expenses']['latest'][0]['receipt_id']) && array_key_exists('receipt', $home['my_expenses']['latest'][0]), 'and on the home: the owed total and her latest (presented, no raw columns)');
$body = page($priya, '/')['body'];
ok(str_contains($body, 'id="home-my-expenses-owed"') && str_contains($body, 'id="home-my-expenses-add"'), 'the home\'s My expenses region shows what she is owed and "Snap a receipt"');
foreach ([[$priya, 'Priya'], [$marco, 'Marco'], [$dana, 'Dana']] as [$j, $n]) {
    [$c, $b] = act($j, '/expenses/reimburse.php', ['expense' => $e1, 'reference' => 'NOPE']);
    ok($c === 403, "$n (no billing.write) may not mark reimbursed: 403");
}
ok(page($marco, '/expenses/reimbursements')['code'] === 403, 'the page is Billing\'s: Marco gets 403');

echo "2. Mark reimbursed\n";
[$c, $b] = act($bea, '/expenses/reimburse.php', ['expense' => $e2, 'reference' => 'PAYRUN-2026-03']);
ok($c === 422 && str_contains(msg($b), 'was paid with the company card; nothing to reimburse'), 'the company-card one: the verb\'s words (' . msg($b) . ')');
$since = last_activity_id();
[$c, $b] = act($bea, '/expenses/reimburse.php', ['expense' => $e1, 'reference' => 'PAYRUN-2026-03']);
$x = expense_row($e1);
ok($c === 200 && $b['reimbursed'] === 1 && $x['reimbursed_at'] !== null && $x['reimbursed_ref'] === 'PAYRUN-2026-03' && $x['status'] === 'approved', 'expense_mark_reimbursed with PAYRUN-2026-03: reimbursed, still approved');
$log = activity('expense.reimburse', $since);
$after = json_decode((string) $log[0]['after'], true);
ok(count($log) === 1 && (int) $log[0]['engagement_id'] === $acme && $after['reference'] === 'PAYRUN-2026-03' && $after['amount'] === '120.00' && $after['currency'] === 'USD' && $after['number'] === 'EXP-000001', 'expense.reimburse logged: reference, amount, currency, engagement');
$n = q("SELECT member_id, body, record_id FROM notifications WHERE kind = 'expense_reimbursed' ORDER BY id DESC LIMIT 1")[0];
ok($n['member_id'] === 26 && $n['record_id'] === $e1 && str_contains($n['body'], 'PAYRUN-2026-03'), 'Priya is told, with the reference');
[$c, $b] = act($bea, '/expenses/reimburse.php', ['expense' => $e1, 'reference' => 'AGAIN']);
ok($c === 422 && str_contains(msg($b), 'is already reimbursed'), 'again: "already reimbursed"');
[$c, $d] = screen($bea, '/expenses/reimbursements');
ok(!in_array($e1, array_merge([], ...array_map(fn ($p) => array_column($p['expenses'], 'expense_id'), $d['people'])), true), 'it is gone from what is owed');
[$c, $d] = screen($priya, '/expenses/' . $e1);
ok($d['expense']['reimbursed_ref'] === 'PAYRUN-2026-03' && $d['expense']['reimbursed_at'] !== null && $d['may']['reject'] === false, 'her expense shows Reimbursed with the reference; no Send back any more');
$body = page($priya, '/expenses/' . $e1)['body'];
ok(str_contains($body, 'id="expense-view-reimbursed"') && str_contains($body, 'PAYRUN-2026-03'), 'the page says Reimbursed (PAYRUN-2026-03)');
[$c, $b] = act($marco, '/expenses/reject.php', ['expense' => $e1, 'reason' => 'SMOKE too late']);
ok($c === 422 && str_contains(msg($b), 'is billed or reimbursed; credit the invoice instead'), 'a reimbursed expense is not sent back: the verb\'s words');

echo "3. All of a person's\n";
$ids = [];
foreach (['SMOKE pay A' => '11.00', 'SMOKE pay B' => '22.00'] as $dsc => $amt) {
    [$c, $b] = record_expense($priya, $acme, $amt, ['description' => $dsc]);
    $id = (int) $b['record_id']; $ids[] = $id;
    act($priya, '/expenses/submit.php', ['expense' => $id]); act($marco, '/expenses/approve.php', ['expense' => $id]);
}
$before = (float) one("SELECT COALESCE(sum(amount), 0) FROM expenses WHERE member_id = 26 AND status = 'approved' AND paid_with = 'personal' AND reimbursed_at IS NULL");
[$c, $b] = act($bea, '/expenses/reimburse.php', ['expenses' => [$ids[0], $e2], 'reference' => 'PAYRUN-ATOMIC']);
ok($c === 422 && expense_row($ids[0])['reimbursed_at'] === null, 'one list with a company-card expense in it: refused as a whole, the other is not marked either');
[$c, $b] = act($bea, '/expenses/reimburse.php', ['member' => 26, 'reference' => 'PAYRUN-2026-04']);
ok($c === 200 && $b['reimbursed'] >= 2 && (int) one("SELECT count(*) FROM expenses WHERE member_id = 26 AND status = 'approved' AND paid_with = 'personal' AND reimbursed_at IS NULL") === 0 && (string) one('SELECT reimbursed_ref FROM expenses WHERE id = :e', ['e' => $ids[1]]) === 'PAYRUN-2026-04', 'member=26: every one she is owed is marked with one reference (' . $before . ' USD)');
[$c, $b] = act($bea, '/expenses/reimburse.php', ['member' => 26, 'reference' => 'X']);
ok($c === 422 && msg($b) === 'Nothing is owed to that person.', 'nothing owed: 422 in words');
[$c, $b] = act($bea, '/expenses/reimburse.php', ['reference' => 'X']);
ok($c === 422 && isset(fields($b)['expense']), 'no expense and no person: 422 (field expense)');
[$c, $b] = act($bea, '/expenses/reimburse.php', ['expense' => 999999, 'reference' => 'X']);
ok($c === 404, 'an expense that does not exist: 404');

echo "4. The company card\n";
[$c, $d] = screen($bea, '/expenses/company-card');
$row = array_values(array_filter($d['rows'], fn ($r) => $r['category']['label'] === 'Meals' && $r['month'] === date('Y-m')))[0] ?? null;
$exp = (float) one("SELECT sum(amount) FROM expenses x JOIN expense_categories c ON c.id = x.category_id WHERE x.status = 'approved' AND x.paid_with = 'company_card' AND c.key = 'meals' AND to_char(x.expense_date, 'YYYY-MM') = :m", ['m' => date('Y-m')]);
ok($c === 200 && $row !== null && (float) $row['amount'] === $exp && $exp >= 40.0 && $row['currency'] === 'USD' && $row['count'] >= 1, 'the company-card page sums by category and month: Meals ' . date('Y-m') . ' ' . ($row['amount'] ?? '?') . ' USD');
$body = page($bea, '/expenses/company-card')['body'];
ok(str_contains($body, 'id="company-card-month-' . date('Y-m') . '"') && !str_contains($body, 'SMOKE Priya') && !str_contains($body, 'SMOKE coffee'), 'the page names the month and the category, no person and no description (the ledger\'s shape)');
[$c, $d] = screen($bea, '/expenses/company-card?from=2001-01-01&to=2001-12-31');
ok($c === 200 && $d['rows'] === [], 'another period: nothing');
ok(page($bea, '/expenses/company-card?from=nope')['code'] === 422, 'a malformed period: 422');
ok(page($dana, '/expenses/company-card')['code'] === 403, 'Dana (no billing.write or reports.view): 403');
ok(page($marco, '/expenses/company-card')['code'] === 200, 'Marco (reports.view) reads it');
finish();
