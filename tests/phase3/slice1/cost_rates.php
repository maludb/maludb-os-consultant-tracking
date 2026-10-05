<?php
/** Proof — cost rates and capacity (spec "Proof", 3): the admin adds Priya's 70 from Jan 1 and 80 from Jul 1 (the first ends Jun 30); an overlap refused in words; Ann (Finance) reads the table, Bea and Marco do not; no log payload carries a rate. */
require __DIR__ . '/lib.php';
$owner = as_member(1); $bea = as_member(28); $marco = as_member(27); $ann = as_member(29); $priya = as_member(26);

echo "1. Ranges\n";
$since = last_activity_id();
[$c, $b] = act($owner, '/settings/cost-rates/save.php', ['member' => 26, 'hourly_cost' => '70', 'valid_from' => '2026-01-01', 'note' => 'SMOKE hired']);
$r1 = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $r1 > 0 && str_ends_with((string) $b['location'], '/settings/cost-rates/?notice=saved#cost-rate-26') && $b['ended_range_id'] === null, "cost_rate_save: 200, record_id $r1, lands on Priya's card");
[$c, $b] = act($owner, '/settings/cost-rates/save.php', ['member' => 26, 'hourly_cost' => '80', 'valid_from' => '2026-07-01']);
$r2 = (int) $b['record_id'];
ok($c === 200 && $b['ended_range_id'] === $r1 && (string) one('SELECT valid_to FROM member_cost_rates WHERE id = :i', ['i' => $r1]) === '2026-07-01' && one('SELECT valid_to FROM member_cost_rates WHERE id = :i', ['i' => $r2]) === null, 'a second range from Jul 1 ends the first on Jul 1 (the day before, inclusive: Jun 30) in the same transaction');
ok((string) one("SELECT ct_cost_rate(26, '2026-06-30')") === '70.00' && (string) one("SELECT ct_cost_rate(26, '2026-07-01')") === '80.00' && one("SELECT ct_cost_rate(26, '2025-12-31')") === null, 'ct_cost_rate(): 70 on Jun 30, 80 on Jul 1, none before Jan 1');
[$c, $b] = act($owner, '/settings/cost-rates/save.php', ['member' => 26, 'hourly_cost' => '75', 'valid_from' => '2026-03-01', 'valid_to' => '2026-04-01']);
ok($c === 422 && msg($b) === 'That range overlaps another of theirs; end the other range first.', 'an insert inside a closed range: the EXCLUDE\'s refusal in words');
[$c, $b] = act($owner, '/settings/cost-rates/save.php', ['member' => 26, 'hourly_cost' => '75', 'valid_from' => '2026-04-01', 'valid_to' => '2026-03-01']);
ok($c === 422 && isset(fields($b)['valid_to']), 'an end before the start: 422 (field valid_to)');
[$c, $b] = act($owner, '/settings/cost-rates/save.php', ['member' => 26, 'hourly_cost' => 'lots', 'valid_from' => '2026-04-01']);
ok($c === 422 && isset(fields($b)['hourly_cost']), 'a rate that is not an amount: 422');
[$c, $b] = act($owner, '/settings/cost-rates/save.php', ['member' => 40, 'hourly_cost' => '1', 'valid_from' => '2026-04-01']);
ok($c === 200, 'an agent may have a cost rate too (its run cost; the Clerk, 40)');
[$c, $b] = act($owner, '/settings/cost-rates/end.php', ['cost_rate' => $r2, 'valid_to' => '2026-12-31']);
ok($c === 200 && (string) one('SELECT valid_to FROM member_cost_rates WHERE id = :i', ['i' => $r2]) === '2026-12-31', 'cost_rate_end closes the open range');
[$c, $b] = act($owner, '/settings/cost-rates/end.php', ['cost_rate' => $r1, 'valid_to' => '2026-08-01']);
ok($c === 422 && msg($b) === 'That end runs into their next range.', 'ending the first range past the second: 422 in words');
[$c, $b] = act($owner, '/settings/cost-rates/save.php', ['cost_rate' => $r2, 'hourly_cost' => '82']);
ok($c === 200 && (string) one('SELECT hourly_cost FROM member_cost_rates WHERE id = :i', ['i' => $r2]) === '82.00' && (string) one('SELECT valid_from FROM member_cost_rates WHERE id = :i', ['i' => $r2]) === '2026-07-01', 'changing a range\'s rate keeps its dates');

echo "2. The log never carries a rate\n";
$rows = q("SELECT before::text AS b, after::text AS a FROM activity_log WHERE action IN ('cost_rate.save', 'cost_rate.end') AND id > :s", ['s' => $since]);
$leak = array_filter($rows, fn ($r) => preg_match('/hourly_cost|"70|"80|"82|"75|70\.00|80\.00|82\.00/', $r['b'] . $r['a']));
ok(count($rows) === 5 && $leak === [] && str_contains($rows[0]['a'], '"member_id": 26') && str_contains($rows[0]['a'], '"valid_from": "2026-01-01"'), count($rows) . ' cost-rate events logged with member_id and the dates — none carries a rate');
ok(str_contains($rows[4]['a'], '"rate_changed": true'), 'a rate change is logged as a fact (rate_changed), never the figure');

echo "3. Who reads the table\n";
[$c, $d] = screen($ann, '/settings/cost-rates/');
$p = array_values(array_filter($d['people'], fn ($x) => $x['member_id'] === 26))[0] ?? null;
ok($c === 200 && $p !== null && count($p['ranges']) === 2 && $p['ranges'][0]['hourly_cost'] === '82.00' && $d['may']['write'] === false, 'Ann (Finance) reads the table: Priya\'s two ranges, 82.00 the latest; no write');
ok(page($ann, '/settings/cost-rates/')['code'] === 200 && !str_contains(page($ann, '/settings/cost-rates/')['body'], 'id="cost-rate-form"'), 'her page has no form');
ok(page($bea, '/settings/cost-rates/')['code'] === 200 && !str_contains(page($bea, '/settings/cost-rates/')['body'], 'id="cost-rate-form"') && page($marco, '/settings/cost-rates/')['code'] === 403 && page($priya, '/settings/cost-rates/')['code'] === 403, 'Bea (Billing) reads it too, without the form (design §16 c); Marco (Manager) and Priya: 403');
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
ok((int) one('SELECT count(*) FROM mcp_member_cost_rates') === 0, 'the view itself answers Marco nothing (cost.view without ct_sees_all)');
pdo()->exec("SELECT set_config('app.member_id', '29', false)");
ok((int) one('SELECT count(*) FROM mcp_member_cost_rates') === 3, 'and Ann every range (3)');
[$c, $b] = act($ann, '/settings/cost-rates/save.php', ['member' => 26, 'hourly_cost' => '1', 'valid_from' => '2027-01-01']);
ok($c === 403, 'Finance may not write: 403');
[$c, $d] = screen($owner, '/settings/cost-rates/');
ok($c === 200 && $d['may']['write'] === true && $d['currency'] === 'USD', 'the admin reads and writes; the table is in the base currency');

echo "4. Capacity\n";
$since = last_activity_id();
ok((string) one("SELECT ct_capacity(26, '2026-01-01')") === '40.00', 'without a range, the settings\' default (40)');
[$c, $b] = act($owner, '/settings/capacity/save.php', ['member' => 26, 'hours_per_week' => '32', 'valid_from' => '2026-01-01']);
$k1 = (int) $b['record_id'];
[$c, $b] = act($owner, '/settings/capacity/save.php', ['member' => 26, 'hours_per_week' => '40', 'valid_from' => '2026-09-01']);
ok($c === 200 && $b['ended_range_id'] === $k1 && (string) one("SELECT ct_capacity(26, '2026-08-31')") === '32.00' && (string) one("SELECT ct_capacity(26, '2026-09-01')") === '40.00', 'two ranges: 32 then 40 from Sep 1; the first ended');
[$c, $b] = act($owner, '/settings/capacity/save.php', ['member' => 26, 'hours_per_week' => '200', 'valid_from' => '2027-01-01']);
ok($c === 422 && isset(fields($b)['hours_per_week']), '200 hours a week: 422');
[$c, $b] = act($owner, '/settings/capacity/save.php', ['member' => 26, 'hours_per_week' => '20', 'valid_from' => '2026-03-01', 'valid_to' => '2026-04-01']);
ok($c === 422 && msg($b) === 'That range overlaps another of theirs; end the other range first.', 'an overlap: the EXCLUDE\'s refusal in words');
$log = activity('capacity.save', $since);
ok(count($log) === 2 && str_contains((string) $log[0]['after'], '"hours_per_week": "32.00"'), 'capacity.save logs the hours (not guarded like a cost)');
[$c, $d] = screen($owner, '/settings/capacity/');
ok($c === 200 && $d['default_hours_per_week'] === '40.00' && count($d['people']) === 1 && count($d['people'][0]['ranges']) === 2, 'the screen: the default and Priya\'s two ranges');
ok(page($bea, '/settings/capacity/')['code'] === 403 && page($ann, '/settings/capacity/')['code'] === 403, 'the capacity screen is the admin\'s (settings.manage)');
finish();
