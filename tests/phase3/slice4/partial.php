<?php
/** Proof — _partial=1 (the agents' door): under an action token an expense's save with only the fields that change keeps the rest — the engagement, the date, the category, the vendor, the description, the paid-with, the markup; a Consultant's markup stays the policy's. */
require __DIR__ . '/lib.php';
[$acme, $beta] = expense_world();
$priyaTok = ['X-Action-Token: ' . person_token(26)];
[$c, $b] = act_token('/expenses/save.php', ['engagement' => $acme, 'date' => date('Y-m-d'), 'category' => category('meals'), 'amount' => '60.00', 'tax_amount' => '5.00', 'vendor_name' => 'SMOKE Partial Cafe', 'description' => 'SMOKE partial', 'paid_with' => 'company_card', 'billable' => 'no'], $priyaTok);
$e = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $e > 0, 'expense_save under a person\'s action token (no session, no CSRF)');
[$c, $b] = act_token('/expenses/save.php', ['expense' => $e, 'amount' => '65.00', '_partial' => '1'], $priyaTok);
$x = expense_row($e);
ok($c === 200 && $x['amount'] === '65.00' && $x['tax_amount'] === '5.00' && $x['vendor_name'] === 'SMOKE Partial Cafe' && $x['description'] === 'SMOKE partial' && $x['paid_with'] === 'company_card' && $x['billable'] === false && (int) $x['engagement_id'] === $acme && $x['expense_date'] === date('Y-m-d') && $x['markup_pct'] === '10.00', '_partial=1 with the amount only keeps the tax, vendor, description, paid-with, billable, engagement, date and markup');
$since = last_activity_id();
[$c, $b] = act_token('/expenses/save.php', ['expense' => $e, 'description' => 'SMOKE partial changed', '_partial' => '1'], $priyaTok);
$log = activity('expense.save', $since);
ok($c === 200 && expense_row($e)['description'] === 'SMOKE partial changed' && !isset(json_decode((string) $log[0]['after'], true)['markup_ignored']), 'a partial save sends the base row\'s markup back: never mistaken for a forgery');
[$c, $b] = act_token('/expenses/save.php', ['expense' => $e, 'markup_pct' => '80', '_partial' => '1'], $priyaTok);
ok($c === 200 && expense_row($e)['markup_pct'] === '10.00', 'a Consultant\'s markup under a token is ignored too');
[$c, $b] = act_token('/expenses/save.php', ['expense' => $e, 'billable' => 'yes', '_partial' => '1'], $priyaTok);
ok($c === 200 && expense_row($e)['billable'] === true, 'billable alone');
[$c, $b] = act_token('/expenses/save.php', ['expense' => $e, 'amount' => '70.00'], ['X-Action-Token: ' . person_token(30)]);
ok($c === 404, 'Dana\'s token on Priya\'s expense: 404');
finish();
