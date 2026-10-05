<?php
/** Proof — the markup (spec "Proof", 2): a Consultant posting markup_pct=50 is ignored (the policy's 10 stays) and the log notes it; a manager's 50 is kept; the category's markup beats the engagement's policy beats the settings'; billable follows the category's default. */
require __DIR__ . '/lib.php';
[$acme, $beta] = expense_world();
$priya = as_member(26); $marco = as_member(27);
$meals = category('meals');

echo "1. A Consultant's markup is the policy's\n";
$since = last_activity_id();
[$c, $b] = record_expense($priya, $acme, '100.00', ['markup_pct' => '50', 'description' => 'SMOKE forged markup']);
$e = (int) $b['record_id'];
$log = activity('expense.save', $since);
ok($c === 200 && expense_row($e)['markup_pct'] === '10.00' && expense_row($e)['billable_amount'] === '110.00', 'Priya posts markup_pct=50: ignored, the 10 % policy stays (100.00 → 110.00)');
ok(json_decode((string) $log[0]['after'], true)['markup_ignored'] === '50.00' && json_decode((string) $log[0]['after'], true)['markup_pct'] === '10.00', 'the log notes it: markup_ignored 50.00, markup_pct 10.00');
$since = last_activity_id();
[$c, $b] = record_expense($priya, $acme, '100.00', ['markup_pct' => '10', 'description' => 'SMOKE honest markup']);
ok($c === 200 && !isset(json_decode((string) activity('expense.save', $since)[0]['after'], true)['markup_ignored']), 'the form posts the policy\'s own 10: nothing to note');
$since = last_activity_id();
[$c, $b] = act($priya, '/expenses/save.php', ['expense' => $e, 'markup_pct' => '40', 'description' => 'SMOKE still forged']);
ok($c === 200 && expense_row($e)['markup_pct'] === '10.00' && isset(json_decode((string) activity('expense.save', $since)[0]['after'], true)['markup_ignored']), 'on her own draft too: a forged 40 is ignored and noted');
[$c, $b] = act($priya, '/expenses/save.php', ['expense' => $e, 'markup_pct' => 'lots']);
ok($c === 422 && isset(fields($b)['markup_pct']), 'a markup that is not a number: 422 (field markup_pct)');
$body = page($priya, '/expenses/new')['body'];
ok(preg_match('/<input[^>]*id="expense-form-field-markup"[^>]*readonly/', $body) === 1, 'her form shows the markup read-only');
$body = page($marco, '/expenses/new')['body'];
ok(preg_match('/<input[^>]*id="expense-form-field-markup"[^>]*readonly/', $body) === 0, 'a manager\'s form shows it editable');

echo "2. A manager's markup is kept\n";
[$c, $b] = record_expense($marco, $acme, '100.00', ['member' => 26, 'markup_pct' => '50', 'description' => 'SMOKE manager markup']);
$m = (int) $b['record_id'];
ok($c === 200 && expense_row($m)['markup_pct'] === '50.00' && expense_row($m)['billable_amount'] === '150.00', 'Marco records for Priya with markup_pct=50: kept (100.00 → 150.00)');
$since = last_activity_id();
[$c, $b] = act($marco, '/expenses/save.php', ['expense' => $m, 'markup_pct' => '25']);
ok($c === 200 && expense_row($m)['markup_pct'] === '25.00' && json_decode((string) activity('expense.save', $since)[0]['before'], true) == ['markup_pct' => '50.00'] && !isset(json_decode((string) activity('expense.save', $since)[0]['after'], true)['markup_ignored']), 'he changes it to 25: kept, the log has before 50.00');
[$c, $d] = screen($marco, '/expenses/new');
ok($d['may_set_markup'] === true, 'the form JSON says he may set the markup');
[$c, $b] = act($priya, '/expenses/save.php', ['expense' => $m, 'markup_pct' => '99']);
ok($c === 200 && expense_row($m)['markup_pct'] === '25.00', 'Priya (the person) cannot raise it on the manager\'s entry either');

echo "3. Category, engagement, settings\n";
q("UPDATE expense_categories SET default_markup_pct = 20 WHERE key = 'software'");
[$c, $b] = record_expense($priya, $acme, '50.00', ['category' => category('software'), 'description' => 'SMOKE software']);
ok($c === 200 && expense_row((int) $b['record_id'])['markup_pct'] === '20.00', 'a category with its own markup: 20 beats the 10 of the settings');
q("UPDATE expense_categories SET default_markup_pct = NULL WHERE key = 'software'");
$was = (string) one('SELECT expense_policy FROM engagements WHERE id = :e', ['e' => $beta]);
q("UPDATE engagements SET expense_policy = '{\"markup_pct\": 5}' WHERE id = :e", ['e' => $beta]);
[$c, $b] = record_expense($priya, $beta, '100.00', ['description' => 'SMOKE policy markup']);
ok($c === 200 && expense_row((int) $b['record_id'])['markup_pct'] === '5.00', 'the engagement\'s policy markup (5) beats the settings\' 10');
[$c, $d] = screen($priya, '/expenses/new?engagement=' . $beta);
ok(in_array('5', array_column(array_filter($d['engagements'], fn ($g) => $g['engagement_id'] === $beta), 'policy_markup'), true), 'the form carries the engagement\'s policy markup for its live billable amount');
q('UPDATE engagements SET expense_policy = :p WHERE id = :e', ['p' => $was, 'e' => $beta]);
q("UPDATE ct_settings SET default_markup_pct = 0 WHERE id = 1");
[$c, $b] = record_expense($priya, $acme, '100.00', ['description' => 'SMOKE pass-through']);
ok($c === 200 && expense_row((int) $b['record_id'])['markup_pct'] === '0.00' && expense_row((int) $b['record_id'])['billable_amount'] === '100.00', 'with the settings\' default at 0 it is a pass-through: 100.00 stays 100.00');
q("UPDATE ct_settings SET default_markup_pct = 10 WHERE id = 1");

echo "4. Billable follows the category\n";
q("UPDATE expense_categories SET billable_default = false WHERE key = 'other'");
[$c, $b] = record_expense($priya, $acme, '10.00', ['category' => category('other'), 'description' => 'SMOKE not billable']);
ok($c === 200 && expense_row((int) $b['record_id'])['billable'] === false, 'a category that is not billable by default: the expense is not (nothing sent)');
[$c, $b] = record_expense($priya, $acme, '10.00', ['category' => category('other'), 'billable' => 'yes', 'description' => 'SMOKE billable anyway']);
ok($c === 200 && expense_row((int) $b['record_id'])['billable'] === true, 'billable sent explicitly wins');
q("UPDATE expense_categories SET billable_default = true WHERE key = 'other'");
finish();
