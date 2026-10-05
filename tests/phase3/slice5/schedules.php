<?php
/** Proof — schedules (spec "Proof", 3): a monthly VM due twice runs twice (last month, this month) and refuses the third in the verb's words; the end date deactivates it on the final run; the pricing, the cadence arithmetic, who may, what is logged and who is told. */
require __DIR__ . '/lib.php';
[$acme, $beta] = usage_world();
$priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $dana = as_member(30);
$first = (string) one("SELECT to_char(date_trunc('month', current_date) - interval '1 month', 'YYYY-MM-DD')");     // the first day of last month
$thisFirst = (string) one("SELECT to_char(date_trunc('month', current_date), 'YYYY-MM-DD')");
$nextFirst = (string) one("SELECT to_char(date_trunc('month', current_date) + interval '1 month', 'YYYY-MM-DD')");
$lastEnd = date('Y-m-d', strtotime($thisFirst . ' -1 day'));
function save_schedule_req(string $jar, array $extra = []): array
{
    global $acme, $first;
    return act($jar, '/settings/schedules/save.php', array_merge(['engagement' => $acme, 'kind' => 'hosting', 'description' => 'SMOKE Acme VM, monthly', 'quantity' => '1', 'unit' => 'months', 'unit_cost' => '180', 'cadence' => 'monthly', 'every_n' => '1', 'next_date' => $first, 'provider' => provider('aws')], $extra));
}

echo "1. Who may\n";
[$c, $b] = save_schedule_req($priya);
ok($c === 403 && msg($b) === 'You may not record usage charges.', 'Priya: recurring_charge_save → 403');
ok(page($priya, '/settings/schedules/')['code'] === 403 && page($priya, '/settings/schedules/new')['code'] === 403, 'and the list and the form: 403');
ok(page($dana, '/settings/schedules/')['code'] === 403, 'Dana (a Consultant): 403');

echo "2. Add a monthly schedule\n";
$since = last_activity_id();
[$c, $b] = save_schedule_req($marco);
$s1 = (int) ($b['record_id'] ?? 0);
$r = q('SELECT * FROM recurring_charges WHERE id = :s', ['s' => $s1])[0] ?? [];
ok($c === 200 && $s1 > 0 && $b['refresh'] === 'usageChanged' && ($b['location'] ?? '') === '/settings/schedules/?notice=saved' && $r['next_date'] === $first && $r['cadence'] === 'monthly' && (int) $r['every_n'] === 1 && $r['active'] === true && (int) $r['created_by'] === 27 && $r['unit_cost'] === '180.000000', 'recurring_charge_save: 200, record_id ' . $s1 . ', due from the first of last month (' . ($b['did'] ?? msg($b)) . ')');
$log = activity('recurring_charge.save', $since);
$after = json_decode((string) $log[0]['after'], true);
ok(count($log) === 1 && (int) $log[0]['engagement_id'] === $acme && $log[0]['entity_type'] === 'recurring_charge' && $after['amount'] === '180.00' && $after['currency'] === 'USD' && $after['cadence'] === 'monthly' && $after['next_date'] === $first && $after['unit_cost'] === '180.000000', 'recurring_charge.save logged with engagement_id, amount (180.00), currency, cadence, next_date, unit_cost');
[$c, $d] = screen($marco, '/settings/schedules/');
$row = array_values(array_filter($d['schedules'], fn ($x) => $x['schedule_id'] === $s1))[0] ?? [];
ok($c === 200 && $row['due'] === true && $row['next_date'] === $first && $row['run_count'] === 0 && $row['last_run_at'] === null && $row['unit_cost'] === '180.000000' && $row['provider']['name'] === 'AWS', 'the list (JSON): due, never ran, the unit cost (a Manager of the engagement sees it)');
$body = page($marco, '/settings/schedules/')['body'];
ok(str_contains($body, 'id="schedule-row-' . $s1 . '"') && str_contains($body, 'id="schedule-row-' . $s1 . '-run-btn"') && str_contains($body, 'id="schedule-row-' . $s1 . '-due"') && str_contains($body, '>180.00<'), 'the page: a card with Run now and the due chip, the amount per period');
ok(page($marco, '/settings/schedules/' . $s1 . '/edit')['code'] === 200 && page($marco, '/settings/schedules/new?engagement=' . $acme)['code'] === 200, 'the form (add and edit) opens');
[$c, $b] = save_schedule_req($marco, ['end_date' => '2020-01-01']);
ok($c === 422 && isset(fields($b)['end_date']), 'an end date before the next date: 422, field end_date');
[$c, $b] = save_schedule_req($marco, ['unit_cost' => '', 'cadence' => 'hourly', 'every_n' => '99', 'description' => '', 'next_date' => 'someday']);
ok($c === 422 && isset(fields($b)['unit_cost'], $b['error']['fields']['cadence'], $b['error']['fields']['every_n'], $b['error']['fields']['description'], $b['error']['fields']['next_date']), 'a missing unit cost, a bad cadence, every, description and date: every field named');
[$c, $b] = save_schedule_req($marco, ['pricing' => 'resale']);
ok($c === 422 && isset(fields($b)['resale_unit_price']), 'resale without a price: 422');
[$c, $b] = act($marco, '/settings/schedules/save.php', ['kind' => 'hosting']);
ok($c === 422 && isset(fields($b)['engagement']), 'no engagement: 422 with the field');

echo "3. Run now — twice, then refused\n";
$since = last_activity_id();
$before = (int) one('SELECT count(*) FROM notifications');
[$c, $b] = act($priya, '/settings/schedules/run.php', ['schedule' => $s1]);
ok($c === 403, 'Priya: recurring_charge_run → 403');
[$c, $b] = act($bea, '/settings/schedules/run.php', ['schedule' => $s1]);
$u1 = (int) ($b['usage_charge_id'] ?? 0);
$x = usage_row($u1);
ok($c === 200 && $u1 > 0 && $b['next_date'] === $thisFirst && $b['active'] === true && $x['status'] === 'draft' && $x['source'] === 'schedule' && (int) $x['schedule_id'] === $s1 && $x['period_start'] === $first && $x['period_end'] === $lastEnd && $x['cost_amount'] === '180.00' && $x['billable_amount'] === '180.00' && (int) $x['created_by'] === 28 && $x['description'] === 'SMOKE Acme VM, monthly', 'Run now (Bea): a draft charge for last month (' . $first . ' → ' . $lastEnd . '), cost 180.00, source schedule; the next date is the first of this month');
$r = q('SELECT * FROM recurring_charges WHERE id = :s', ['s' => $s1])[0];
ok($r['next_date'] === $thisFirst && (int) $r['run_count'] === 1 && $r['last_run_at'] !== null && $r['active'] === true, 'the schedule: next date moved on, run_count 1, last_run_at set');
$log = activity('recurring_charge.run', $since);
$after = json_decode((string) $log[0]['after'], true);
ok(count($log) === 1 && (int) $log[0]['engagement_id'] === $acme && $after['charge_id'] === $u1 && $after['number'] === $x['number'] && $after['amount'] === '180.00' && $after['currency'] === 'USD' && $after['next_date'] === $thisFirst && $after['period_start'] === $first, 'recurring_charge.run logged with engagement_id, the charge, the period, amount, currency, next_date');
$dr = activity('usage_charge.draft', $since);
ok(count($dr) === 1 && json_decode((string) $dr[0]['after'], true)['source'] === 'schedule' && (int) $dr[0]['entity_id'] === $u1, 'and the charge\'s own usage_charge.draft row (source schedule), so its timeline starts');
$told = q("SELECT member_id, record_type, record_id FROM notifications WHERE kind = 'usage_drafted' AND id > :b ORDER BY id", ['b' => $before]);
ok($told === [['member_id' => 27, 'record_type' => 'usage_charge', 'record_id' => $u1]], 'Marco (the manager) is told it was drafted; Bea who ran it is not');
[$c, $b] = act($marco, '/settings/schedules/run.php', ['schedule' => $s1]);
$u2 = (int) ($b['usage_charge_id'] ?? 0);
$x2 = usage_row($u2);
ok($c === 200 && $x2['period_start'] === $thisFirst && $x2['period_end'] === date('Y-m-d', strtotime($nextFirst . ' -1 day')) && $b['next_date'] === $nextFirst, 'run two (Marco): this month\'s charge, the next date the first of next month');
$since = last_activity_id();
[$c, $b] = act($marco, '/settings/schedules/run.php', ['schedule' => $s1]);
ok($c === 422 && msg($b) === 'The schedule is not due until ' . $nextFirst && (int) one('SELECT count(*) FROM usage_charges WHERE schedule_id = :s', ['s' => $s1]) === 2 && count(activity('recurring_charge.run', $since)) === 0, 'the third run is refused in the verb\'s words (' . msg($b) . '): nothing made, nothing logged');
ok(str_contains(page($marco, '/usage/' . $u1)['body'], 'id="usage-source-schedule"') && str_contains(page($marco, '/usage/' . $u1)['body'], 'SMOKE Acme VM, monthly'), 'the charge says it came from the schedule, with a link');
$body = page($marco, '/settings/schedules/')['body'];
ok(!str_contains($body, 'id="schedule-row-' . $s1 . '-run-btn"') && str_contains($body, 'Last ran') && str_contains($body, '2 runs'), 'no Run now once not due; the card says it ran twice');

echo "4. Change it\n";
$since = last_activity_id();
[$c, $b] = act($marco, '/settings/schedules/save.php', ['schedule' => $s1, 'unit_cost' => '200', 'engagement' => $acme, 'description' => 'SMOKE Acme VM, monthly', 'kind' => 'hosting', 'quantity' => '1', 'unit' => 'months', 'cadence' => 'monthly', 'every_n' => '1', 'next_date' => $nextFirst, 'pricing' => 'markup', 'markup_pct' => '10']);
$r = q('SELECT * FROM recurring_charges WHERE id = :s', ['s' => $s1])[0];
$log = activity('recurring_charge.save', $since);
ok($c === 200 && $r['unit_cost'] === '200.000000' && $r['pricing'] === 'markup' && count($log) === 1 && json_decode((string) $log[0]['before'], true)['unit_cost'] === '180.000000' && json_decode((string) $log[0]['after'], true)['unit_cost'] === '200.000000' && json_decode((string) $log[0]['after'], true)['amount'] === '200.00', 'changed to 200 with a 10 % markup: the log carries before and after of what changed');
[$c, $b] = act($marco, '/settings/schedules/save.php', ['schedule' => $s1, 'next_date' => $thisFirst]);
ok($c === 200, 'bring the next date back to this month (a keyed change leaves the rest as it was)');
[$c, $b] = act($marco, '/settings/schedules/run.php', ['schedule' => $s1]);
$x3 = usage_row((int) $b['usage_charge_id']);
ok($c === 200 && $x3['cost_amount'] === '200.00' && $x3['billable_amount'] === '220.00' && $x3['pricing'] === 'markup' && $x3['period_start'] === $thisFirst, 'the next run uses the new figures: cost 200.00, billable 220.00 at +10 %');

echo "5. The end date, and turning a schedule off\n";
[$c, $b] = save_schedule_req($marco, ['description' => 'SMOKE Acme storage, ends', 'unit_cost' => '40', 'end_date' => $lastEnd]);
$s2 = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $s2 > 0, 'a schedule due last month that ends at the end of last month');
$since = last_activity_id();
[$c, $b] = act($marco, '/settings/schedules/run.php', ['schedule' => $s2]);
ok($c === 200 && (int) one('SELECT count(*) FROM usage_charges WHERE schedule_id = :s', ['s' => $s2]) === 1 && count(activity('recurring_charge.run', $since)) === 1 && q('SELECT active, run_count FROM recurring_charges WHERE id = :s', ['s' => $s2])[0] === ['active' => false, 'run_count' => 1],
    'the last run before the end date makes its charge and deactivates the schedule (db/016 relaxed the check)' . ($c === 200 ? '' : " (got $c " . msg($b) . ')'));
[$c, $b] = act($marco, '/settings/schedules/run.php', ['schedule' => $s2]);
ok($c === 422 && str_contains(msg($b), 'not active'), 'running it again: "The schedule is not active"');
[$c, $b] = save_schedule_req($marco, ['description' => 'SMOKE Acme storage, far end', 'unit_cost' => '40', 'end_date' => $nextFirst]);
$s3 = (int) $b['record_id'];
[$c, $b] = act($marco, '/settings/schedules/run.php', ['schedule' => $s3]);
ok($c === 200 && $b['active'] === true && $b['next_date'] === $thisFirst, 'a schedule whose end date is not yet reached runs and stays active');
[$c, $b] = act($marco, '/settings/schedules/save.php', ['schedule' => $s3, 'active' => 'no']);
ok($c === 200 && (int) one('SELECT count(*) FROM recurring_charges WHERE id = :s AND active', ['s' => $s3]) === 0, 'turned off by hand (active = no)');
[$c, $b] = act($marco, '/settings/schedules/run.php', ['schedule' => $s3]);
ok($c === 422 && msg($b) === 'The schedule is not active', 'a run of an inactive schedule: "The schedule is not active"');
ok(str_contains(page($marco, '/settings/schedules/')['body'], 'id="schedule-row-' . $s3 . '-active">Done<'), 'the card says Done');
$s2 = $s3;

echo "6. Cadence arithmetic\n";
$d0 = date('Y-m-d', strtotime('-3 days'));
[$c, $b] = save_schedule_req($marco, ['description' => 'SMOKE weekly every 2', 'cadence' => 'weekly', 'every_n' => '2', 'next_date' => $d0, 'unit_cost' => '10']);
$w = (int) $b['record_id'];
[$c, $b] = act($marco, '/settings/schedules/run.php', ['schedule' => $w]);
ok($c === 200 && $b['next_date'] === date('Y-m-d', strtotime($d0 . ' +14 days')), 'weekly every 2: the next date is 14 days on');
$q0 = (string) one("SELECT to_char(date_trunc('month', current_date) - interval '3 months', 'YYYY-MM-DD')");
[$c, $b] = save_schedule_req($marco, ['description' => 'SMOKE quarterly', 'cadence' => 'quarterly', 'every_n' => '1', 'next_date' => $q0, 'unit_cost' => '10']);
$qq = (int) $b['record_id'];
[$c, $b] = act($marco, '/settings/schedules/run.php', ['schedule' => $qq]);
ok($c === 200 && $b['next_date'] === date('Y-m-d', strtotime($q0 . ' +3 months')), 'quarterly: three months on');

echo "7. An engagement he does not manage; delete\n";
[$c, $b] = act($bea, '/engagements/save.php', ['client' => (int) one("SELECT id FROM clients WHERE name = 'SMOKE Beta'"), 'name' => 'SMOKE Beas usage eng', 'kind' => 'time_and_materials', 'start_date' => '2026-01-05', 'manager' => 28, 'start_now' => 'yes']);
$beas = (int) $b['record_id'];
[$c, $b] = save_schedule_req($marco, ['engagement' => $beas]);
ok(in_array($c, [403, 404], true), 'Marco scheduling on Bea\'s engagement: refused (' . $c . ')');
[$c, $b] = save_schedule_req($bea, ['engagement' => $beas, 'description' => 'SMOKE Bea schedule']);
$bs = (int) $b['record_id'];
ok($c === 200, 'Bea, its manager, may');
[$c, $b] = act($marco, '/settings/schedules/run.php', ['schedule' => $bs]);
ok(in_array($c, [403, 404], true), 'Marco running Bea\'s schedule: refused (' . $c . ')');
[$c, $d] = screen($marco, '/settings/schedules/');
ok(!in_array($bs, array_column($d['schedules'], 'schedule_id'), true), 'it is not on Marco\'s list (the view)');
$since = last_activity_id();
[$c, $b] = act($priya, '/settings/schedules/delete.php', ['schedule' => $s2]);
ok($c === 403, 'Priya: recurring_charge_delete → 403');
[$c, $b] = act($marco, '/settings/schedules/delete.php', ['schedule' => $s2]);
$log = activity('recurring_charge.delete', $since);
ok($c === 200 && (int) one('SELECT count(*) FROM recurring_charges WHERE id = :s', ['s' => $s2]) === 0 && count($log) === 1 && (int) $log[0]['engagement_id'] === $acme, 'recurring_charge_delete: gone, logged with the engagement');
ok((int) one('SELECT count(*) FROM usage_charges WHERE description = :d', ['d' => 'SMOKE Acme storage, far end']) === 1 && one('SELECT schedule_id FROM usage_charges WHERE description = :d', ['d' => 'SMOKE Acme storage, far end']) === null, 'the charge it made stays, no longer naming a schedule');
finish();
