<?php
/** Proof — the settings and the numbering (spec "Proof", 5): the increment changed to 0.5 and shown on the next form; the next invoice number raised, lowering refused in words; settings.save carries changed fields only. */
require __DIR__ . '/lib.php';
$owner = as_member(1); $bea = as_member(28);

echo "1. The settings\n";
[$c, $d] = screen($owner, '/settings/general');
ok($c === 200 && $d['settings']['hours_increment'] === '0.25' && $d['settings']['week_start_dow'] === 1 && $d['settings']['reminder_days'] === [7, 21, 45] && $d['settings']['budget_thresholds_pct'] === [50, 80, 100], 'the defaults: 0.25 h, Monday, reminders 7/21/45, thresholds 50/80/100');
$since = last_activity_id();
[$c, $b] = act($owner, '/settings/general.php', ['hours_increment' => '0.5', 'firm_name' => 'SMOKE Consulting LLC', 'reminder_days' => '14, 7, 30', 'budget_thresholds_pct' => '75,90,100', 'morning_note_member' => 28, 'default_terms_days' => '14']);
ok($c === 200 && $b['changed'] === ['firm_name', 'hours_increment', 'default_terms_days', 'reminder_days', 'budget_thresholds_pct', 'morning_note_member_id'] && str_starts_with((string) $b['did'], 'Saved the settings ('), 'settings_save: the changed keys named (' . implode(', ', $b['changed']) . ')');
$s = q('SELECT hours_increment, firm_name, reminder_days, budget_thresholds_pct, morning_note_member_id, default_terms_days FROM ct_settings')[0];
ok($s['hours_increment'] === '0.50' && $s['firm_name'] === 'SMOKE Consulting LLC' && $s['reminder_days'] === '{7,14,30}' && $s['budget_thresholds_pct'] === '{75,90,100}' && (int) $s['morning_note_member_id'] === 28 && (int) $s['default_terms_days'] === 14, 'the row: 0.50, the name, the days sorted, Bea gets the morning note, terms 14');
$log = activity('settings.save', $since);
$after = json_decode((string) $log[0]['after'], true);
$keys = array_keys($after); sort($keys);
ok(count($log) === 1 && $keys === ['budget_thresholds_pct', 'default_terms_days', 'firm_name', 'hours_increment', 'morning_note_member_id', 'reminder_days'] && json_decode((string) $log[0]['before'], true)['hours_increment'] === '0.25', 'settings.save carries the changed fields only, before and after');
[$c, $b] = act($owner, '/settings/general.php', ['hours_increment' => '0.5']);
ok($c === 200 && $b['did'] === 'Nothing changed' && count(activity('settings.save', $since)) === 1 && str_ends_with((string) $b['location'], 'notice=same'), 'the same again: "Nothing changed", nothing logged');
$body = page($owner, '/settings/general')['body'];
ok(preg_match('/<option value="0.50" selected>/', $body) === 1 && str_contains($body, 'value="SMOKE Consulting LLC"') && str_contains($body, 'value="7, 14, 30"'), 'the next form shows 0.5 h, the name and the days');
[$c, $b] = act($owner, '/settings/general.php', ['hours_increment' => '0.3']);
ok($c === 422 && isset(fields($b)['hours_increment']), 'an increment not on the list: 422');
[$c, $b] = act($owner, '/settings/general.php', ['week_start_dow' => '9', 'max_hours_per_day' => '30', 'reminder_days' => '7, x', 'timezone' => 'Mars/Olympus', 'base_currency' => 'dollars']);
ok($c === 422 && count(fields($b)) === 5, 'five bad fields: 422 naming each');
[$c, $b] = act($owner, '/settings/general.php', ['week_start_dow' => '0', 'default_time_grouping' => 'by_role', 'share_receipts_default' => 'no', 'timezone' => 'America/New_York']);
ok($c === 200 && q('SELECT week_start_dow, default_time_grouping, share_receipts_default, timezone FROM ct_settings')[0] === ['week_start_dow' => 0, 'default_time_grouping' => 'by_role', 'share_receipts_default' => false, 'timezone' => 'America/New_York'], 'Sunday weeks, grouping by role, receipts not shared, New York');
ok((string) one("SELECT ct_week_start('2026-03-04')") === '2026-03-01', 'ct_week_start() follows: a Wednesday\'s week starts on Sunday Mar 1');
q('DELETE FROM member_cost_rates');          // the cost-rate proof left ranges; the currency rule needs none
[$c, $b] = act($owner, '/settings/general.php', ['week_start_dow' => '1', 'base_currency' => 'EUR']);
ok($c === 200 && (string) one('SELECT base_currency FROM ct_settings') === 'EUR', 'the base currency changes while no cost rate is kept');
q("INSERT INTO member_cost_rates (member_id, hourly_cost, valid_from, created_by) VALUES (26, 1, '2026-01-01', 1)");
[$c, $b] = act($owner, '/settings/general.php', ['base_currency' => 'USD']);
ok($c === 422 && fields($b)['base_currency'] === 'The base currency is fixed once a cost rate is kept in it.', 'and is fixed once a cost rate is kept: 422 in words');
q('DELETE FROM member_cost_rates');
[$c, $b] = act($owner, '/settings/general.php', ['base_currency' => 'USD']);
ok($c === 200, 'back to USD');
ok(page($bea, '/settings/general')['code'] === 403, 'Billing may not open the settings');

echo "2. The numbering\n";
[$c, $d] = screen($owner, '/settings/numbering');
$inv = array_values(array_filter($d['numbering'], fn ($x) => $x['kind'] === 'invoice'))[0];
ok($c === 200 && count($d['numbering']) === 5 && $inv['preview'] === 'INV-00001' && $inv['highest_issued'] === 0, 'five kinds; invoices next INV-00001, none issued');
$since = last_activity_id();
[$c, $b] = act($owner, '/settings/numbering.php', ['kind' => 'invoice', 'next_value' => '1001', 'prefix' => 'inv-']);
ok($c === 200 && $b['preview'] === 'INV-01001' && $b['changed'] === ['next_value'] && str_ends_with((string) $b['location'], '#numbering-row-invoice'), 'raised to 1001: next INV-01001 (the prefix uppercased, unchanged)');
ok((string) one("SELECT next_document_number('invoice')") === 'INV-01001' && (string) one("SELECT next_document_number('invoice')") === 'INV-01002', 'next_document_number() hands out INV-01001 then INV-01002');
$client = mkclient('SMOKE Numbering client');
$eng = mkengagement($client, 'SMOKE numbering');
$inv = (int) one("INSERT INTO invoices (client_id, engagement_id, due_date, currency, status) VALUES (:c, :e, current_date, 'USD', 'draft') RETURNING id", ['c' => $client, 'e' => $eng]);
pdo()->exec("BEGIN; SELECT set_config('ct.via_function', '1', true); UPDATE invoices SET status = 'issued', number = 'INV-01002', issued_at = now() WHERE id = $inv; COMMIT;");   // issued the way the verb does it (the proof is not the invoicing slice)
[$c, $b] = act($owner, '/settings/numbering.php', ['kind' => 'invoice', 'next_value' => '500']);
ok($c === 422 && msg($b) === 'The next number cannot go below 1003: INV-01002 is already issued.', 'lowering below the highest issued: 422 "The next number cannot go below 1003: INV-01002 is already issued."');
[$c, $b] = act($owner, '/settings/numbering.php', ['kind' => 'invoice', 'next_value' => '1003']);
ok($c === 200 && $b['did'] === 'Nothing changed', '1003 is where it stands: nothing changed');
[$c, $b] = act($owner, '/settings/numbering.php', ['kind' => 'engagement', 'prefix' => 'ENG', 'padding' => '6']);
ok($c === 200 && $b['preview'] === 'ENG' . str_pad((string) one("SELECT next_value FROM document_sequences WHERE kind = 'engagement'"), 6, '0', STR_PAD_LEFT), 'engagements: the prefix without a dash and padding 6 (' . $b['preview'] . ')');
[$c, $b] = act($owner, '/settings/numbering.php', ['kind' => 'invoice', 'prefix' => '9X']);
ok($c === 422 && isset(fields($b)['prefix']), 'a prefix starting with a digit: 422');
[$c, $b] = act($owner, '/settings/numbering.php', ['kind' => 'orders', 'prefix' => 'O-']);
ok($c === 422 && isset(fields($b)['kind']), 'an unknown kind: 422');
$log = activity('numbering.save', $since);
ok(count($log) === 2 && json_decode((string) $log[0]['before'], true) === ['kind' => 'invoice', 'next_value' => 1] && json_decode((string) $log[0]['after'], true) === ['kind' => 'invoice', 'next_value' => 1001], 'numbering.save logs the kind and before/after');
ok(page($bea, '/settings/numbering')['code'] === 403, 'the numbering is the admin\'s');
finish();
