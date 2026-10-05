<?php
/** Proof — who sees (spec "Proof", 5): Priya her own; Marco everything on his engagements, with receipts; Bea and Ann all; the receipt of Dana's expense → 404 for Priya; the lists, the filters and the approvals follow the view. */
require __DIR__ . '/lib.php';
require_once dirname(__DIR__, 3) . '/app/db.php';
[$acme, $beta] = expense_world();
$priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29); $dana = as_member(30);

echo "1. Dana's expense and its receipt\n";
$since = last_activity_id();
[$c, $b] = upload($dana, '/expenses/save.php', ['engagement' => $acme, 'date' => date('Y-m-d'), 'category' => category('lodging'), 'amount' => '210.00', 'description' => 'SMOKE Dana hotel'], 'file', jpeg_file(), 'hotel.jpg');
$ed = (int) ($b['record_id'] ?? 0);
$aid = (int) one("SELECT id FROM attachments WHERE record_type = 'expense' AND record_id = :e", ['e' => $ed]);
ok($c === 200 && $ed > 0 && $aid > 0, 'Dana records a 210.00 hotel with a receipt');
ok(req('GET', '/attachments/' . $aid, ['jar' => $dana])['code'] === 200, 'she sees her receipt');
ok(req('GET', '/attachments/' . $aid, ['jar' => $priya])['code'] === 404 && req('GET', '/expenses/attachments/get.php?id=' . $aid, ['jar' => $priya])['code'] === 404, 'the receipt of Dana\'s expense → 404 for Priya, by both doors');
ok(page($priya, '/expenses/' . $ed)['code'] === 404 && page($priya, '/expenses/' . $ed . '/edit')['code'] === 404, 'Dana\'s expense page and edit page → 404 for Priya');
foreach (['/expenses/submit.php', '/expenses/delete.php', '/expenses/save.php'] as $path) {
    [$c, $b] = act($priya, $path, ['expense' => $ed, 'amount' => '1.00']);
    ok($c === 404, "$path on Dana's expense → 404 for Priya");
}
ok(req('GET', '/attachments/' . $aid, ['jar' => $marco])['code'] === 200 && req('GET', '/attachments/' . $aid, ['jar' => $bea])['code'] === 200 && req('GET', '/attachments/' . $aid, ['jar' => $ann])['code'] === 200, 'Marco (manager of Acme), Bea (Billing) and Ann (Finance) see it');

echo "2. The lists\n";
[$c, $d] = screen($priya, '/expenses/');
ok($c === 200 && count($d['expenses']) > 0 && array_unique(array_column(array_column($d['expenses'], 'member'), 'member_id')) === [26] && !in_array($ed, array_column($d['expenses'], 'expense_id'), true), 'Priya\'s list: hers only');
ok($d['counts'][''] === count($d['expenses']) && $d['counts']['draft'] + $d['counts']['submitted'] + $d['counts']['approved'] + $d['counts']['rejected'] === $d['counts'][''], 'with the counts by status adding up');
[$c, $d2] = screen($priya, '/expenses/?status=approved');
ok(count($d2['expenses']) === $d['counts']['approved'] && array_unique(array_column($d2['expenses'], 'status')) === ['approved'], 'the Approved tab: only those');
[$c, $d2] = screen($priya, '/expenses/?engagement=' . $beta);
ok(array_unique(array_column(array_column($d2['expenses'], 'engagement'), 'engagement_id')) === [$beta] || $d2['expenses'] === [], 'the engagement filter');
ok(page($priya, '/expenses/?member=30')['code'] === 404, '?member=30 for a Consultant: 404');
[$c, $d] = screen($marco, '/expenses/?member=30');
ok($c === 200 && $d['member_id'] === 30 && in_array($ed, array_column($d['expenses'], 'expense_id'), true), 'Marco opens Dana\'s list with ?member= (a manager of her engagement), with her receipt flag');
$body = page($marco, '/expenses/?member=30')['body'];
ok(str_contains($body, 'SMOKE Dana') && str_contains($body, 'id="expense-card-' . $ed . '"') && str_contains($body, 'id="receipt-' . $ed . '"'), 'titled with her name, her card with the receipt chip');
[$c, $d] = screen($dana, '/expenses/');
ok(array_unique(array_column(array_column($d['expenses'], 'member'), 'member_id')) === [30] && count($d['expenses']) === 1, 'Dana sees hers');

echo "3. The views themselves\n";
pdo()->exec("SELECT set_config('app.member_id', '26', false)");
ok((int) one('SELECT count(*) FROM mcp_expenses WHERE member_id <> 26') === 0, 'the view answers Priya nobody else\'s expense');
ok((int) one("SELECT count(*) FROM mcp_attachments WHERE record_type = 'expense' AND record_id = :e", ['e' => $ed]) === 0, 'nor their receipts');
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
ok((int) one('SELECT count(*) FROM mcp_expenses WHERE member_id = 30') > 0 && (int) one("SELECT count(*) FROM mcp_attachments WHERE record_type = 'expense' AND record_id = :e", ['e' => $ed]) === 1, 'Marco: Dana\'s expense and receipt, on his engagement');
$bea28 = (int) one('SELECT count(*) FROM expenses');
pdo()->exec("SELECT set_config('app.member_id', '28', false)");
ok((int) one('SELECT count(*) FROM mcp_expenses') === $bea28, 'Bea: every expense');
pdo()->exec("SELECT set_config('app.member_id', '29', false)");
ok((int) one('SELECT count(*) FROM mcp_expenses') === $bea28, 'Ann (Finance): every expense');
[$c, $d] = screen($ann, '/expenses/' . $ed);
ok($c === 200 && $d['may']['approve'] === false && $d['may']['submit'] === false && $d['may']['reimburse'] === false && $d['expense']['amount'] === '210.00', 'Ann reads it with the money, may do nothing');
ok(page($ann, '/expenses/')['code'] === 403, 'Finance has no list of her own (no expenses.write): 403 in words');

echo "4. Approvals\n";
act($dana, '/expenses/submit.php', ['expense' => $ed]);
[$c, $d] = screen($marco, '/expenses/approvals');
$mine = array_column($d['expenses'], 'expense_id');
ok($c === 200 && in_array($ed, $mine, true) && !in_array((int) one("SELECT id FROM expenses WHERE description = 'SMOKE Marco own'"), $mine, true), 'Marco\'s approvals: Dana\'s hotel, never his own');
ok(array_column(array_filter($d['expenses'], fn ($x) => $x['expense_id'] === $ed), 'has_receipt') === [true], 'with the receipt beside the amount');
$body = page($marco, '/expenses/approvals')['body'];
ok(str_contains($body, 'id="approvals-list"') && str_contains($body, 'id="expense-card-' . $ed . '-receipt-thumb"') && str_contains($body, 'id="expense-card-' . $ed . '-approve-btn"') && str_contains($body, 'id="expense-card-' . $ed . '-reject-reason"'), 'the page: the receipt thumbnail, Approve, the reject reason');
[$c, $d] = screen($marco, '/expenses/approvals?engagement=' . $beta);
ok(!in_array($ed, array_column($d['expenses'], 'expense_id'), true), 'the engagement filter');
ok(page($dana, '/expenses/approvals')['code'] === 403 && page($priya, '/expenses/approvals')['code'] === 403, 'Dana and Priya: 403 in words');
$body = page($marco, '/')['body'];
ok(str_contains($body, 'id="home-approval-expense-' . $ed . '"') && str_contains($body, 'id="home-approvals-expenses-link"'), 'the home\'s Approvals waiting names the expense');
$home = screen($marco, '/')[1];
ok(is_array($home['approvals']['expenses']) && !isset($home['approvals']['expenses'][0]['receipt_id']), 'and its JSON is presented (no raw columns)');
$home = screen($dana, '/')[1];
ok($home['approvals'] === null, 'Dana has no approvals region');
finish();
