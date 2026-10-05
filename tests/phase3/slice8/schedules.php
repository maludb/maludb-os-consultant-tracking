<?php
/**
 * Proof — the hosting schedules and the budget checks (spec "Proof", 5): a schedule due today is run ONCE (a draft charge for the period it covers, the date a month on, the run counted, the manager told); a second pass runs nothing; an end date
 * deactivates a schedule on its final run; one that cannot run (its engagement closed) is counted failed and left exactly as it was. The budget step writes each crossing ONCE (the database's unique alert), tells the manager, and is silent the second time.
 */
require __DIR__ . '/lib.php';
$w = report_world(); $acme = $w['acme']; $beta = (int) billing_world()['beta'];
$marco = as_member(27);
$today = today();
$nextMonth = (string) one("SELECT ct_recurring_next(CAST(:d AS date), 'monthly', 1)", ['d' => $today]);
function mk_schedule(string $jar, int $e, string $desc, array $extra = []): int
{
    global $today;
    [$c, $b] = act($jar, '/settings/schedules/save.php', array_merge(['engagement' => $e, 'kind' => 'hosting', 'description' => $desc, 'quantity' => '1', 'unit' => 'months', 'unit_cost' => '180', 'cadence' => 'monthly', 'every_n' => '1', 'next_date' => $today, 'provider' => provider('aws')], $extra));
    if ($c !== 200) { fwrite(STDERR, "schedule failed: " . json_encode($b) . "\n"); }
    return (int) $b['record_id'];
}
drain_outbox();
$s1 = mk_schedule($marco, $acme, 'SMOKE s8 VM due today');
$s2 = mk_schedule($marco, $acme, 'SMOKE s8 VM tomorrow', ['next_date' => plus_days($today, 1)]);
$s3 = mk_schedule($marco, $acme, 'SMOKE s8 VM ends soon', ['end_date' => plus_days($today, 10), 'unit_cost' => '90']);
$s4 = mk_schedule($marco, $beta, 'SMOKE s8 VM on a closed engagement');
q("UPDATE engagements SET status = 'closed' WHERE id = :e", ['e' => $beta]);
$since = last_activity_id();

echo "1. The schedules\n";
[$code, $rep] = worker('schedules', $today . 'T07:00:00Z');
$c = step_counts($rep, 'schedules');
ok($code === 0 && $c === ['ran' => 2, 'failed' => 1], 'schedules: two ran (today\'s and the one that ends soon), one failed (the closed engagement), tomorrow\'s left alone');
$u1 = q("SELECT * FROM usage_charges WHERE schedule_id = :s", ['s' => $s1]);
ok(count($u1) === 1 && $u1[0]['status'] === 'draft' && $u1[0]['source'] === 'schedule' && $u1[0]['billable_amount'] === '180.00' && $u1[0]['period_start'] === $today && $u1[0]['period_end'] === plus_days($nextMonth, -1) && $u1[0]['created_by'] === null && (int) $u1[0]['engagement_id'] === $acme, 'one draft charge: 180.00, source schedule, the period today to the day before the next date, no creator, on Acme');
$r1 = q('SELECT * FROM recurring_charges WHERE id = :s', ['s' => $s1])[0];
ok($r1['next_date'] === $nextMonth && (int) $r1['run_count'] === 1 && $r1['active'] === true && $r1['last_run_at'] !== null, 'the schedule: next date a month on, run_count 1, still active');
$r3 = q('SELECT * FROM recurring_charges WHERE id = :s', ['s' => $s3])[0];
ok($r3['active'] === false && (int) $r3['run_count'] === 1, 'the schedule with an end date ten days away: its next date is past it, so it is deactivated after the run (db/016)');
ok((int) one('SELECT count(*) FROM usage_charges WHERE schedule_id = :s', ['s' => $s2]) === 0 && q('SELECT next_date, run_count FROM recurring_charges WHERE id = :s', ['s' => $s2])[0] === ['next_date' => plus_days($today, 1), 'run_count' => 0], 'tomorrow\'s schedule: untouched');
$r4 = q('SELECT next_date, run_count, active FROM recurring_charges WHERE id = :s', ['s' => $s4])[0];
ok((int) one('SELECT count(*) FROM usage_charges WHERE schedule_id = :s', ['s' => $s4]) === 0 && $r4 === ['next_date' => $today, 'run_count' => 0, 'active' => true], 'the closed engagement\'s schedule: no charge, the date unmoved, run_count 0 — the transaction rolled back');
$n = q("SELECT member_id, title, body, record_type FROM notifications WHERE kind = 'usage_drafted' AND record_id = :c", ['c' => $u1[0]['id']]);
ok(count($n) === 1 && (int) $n[0]['member_id'] === 27 && str_contains($n[0]['title'], 'by the schedule "SMOKE s8 VM due today"') && str_contains($n[0]['body'], '/usage/' . $u1[0]['id']), 'the manager (Marco) is told: "… drafted by the schedule …" with a link');
$rl = activity('recurring_charge.run', $since); $ul = activity('usage_charge.draft', $since);
$ra = json_decode((string) $rl[0]['after'], true);
ok(count($rl) === 2 && count($ul) === 2 && $rl[0]['source'] === 'cron' && $rl[0]['actor_member_id'] === null && $ra['amount'] === '180.00' && $ra['currency'] === 'USD' && $ra['next_date'] === $nextMonth && $ra['via'] === 'worker' && json_decode((string) $ul[0]['after'], true)['source'] === 'schedule', 'recurring_charge.run and usage_charge.draft logged for each run: cron, no actor, amount and currency, the next date');

echo "2. A second pass runs nothing\n";
[$code, $rep] = worker('schedules', $today . 'T07:30:00Z');
ok(step_counts($rep, 'schedules') === ['ran' => 0, 'failed' => 1] && (int) one("SELECT count(*) FROM usage_charges WHERE schedule_id IN ($s1, $s2, $s3, $s4)") === 2, 'again: nothing runs (the failed one fails again, harmlessly); still two charges');
q("UPDATE recurring_charges SET active = false WHERE id = :s", ['s' => $s4]);
q("UPDATE engagements SET status = 'active' WHERE id = :e", ['e' => $beta]);

echo "3. The budgets\n";
pdo()->exec("SELECT set_config('app.member_id', '1', false)");
$st = q('SELECT hours_used, ai_cost FROM ct_budget_status(:e)', ['e' => $acme])[0];
$H = (float) $st['hours_used']; $A = (float) $st['ai_cost'];
$bh = number_format($H / 0.6, 2, '.', ''); $ba = number_format($A / 0.9, 2, '.', '');       // 60 % of the hours budget (past 50), 90 % of the AI budget (past the 80 % AI threshold)
q('UPDATE engagements SET budget_hours = :h, ai_budget_amount = :a WHERE id = :e', ['h' => $bh, 'a' => $ba, 'e' => $acme]);
$before = (int) one('SELECT count(*) FROM budget_alerts');
$since = last_activity_id();
[$code, $rep] = worker('budgets', $today . 'T07:00:00Z');
$c = step_counts($rep, 'budgets');
ok($code === 0 && $c['alerts'] === 2 && $c['checked'] >= 3, "budgets: every active engagement checked; two new alerts (Acme has used $H h of a $bh h budget — 60 %, past 50; its AI cost $A of $ba is 90 %, past the AI threshold)");
$al = array_column(q('SELECT kind, threshold_pct, value_at, budget_at, acknowledged_at FROM budget_alerts WHERE engagement_id = :e ORDER BY kind', ['e' => $acme]), null, 'kind');
ok(array_keys($al) === ['ai', 'hours'] && (int) $al['hours']['threshold_pct'] === 50 && (float) $al['hours']['value_at'] === $H && $al['hours']['budget_at'] === $bh && (int) $al['ai']['threshold_pct'] === 80 && (float) $al['ai']['value_at'] === $A && $al['ai']['acknowledged_at'] === null, 'hours@50 (the hours used of the budget) and ai@80 (the AI cost of its budget), both open');
$notes = q("SELECT title, body FROM notifications WHERE kind = 'budget_alert' AND member_id = 27 ORDER BY id");
ok(count($notes) === 2 && str_contains(implode('|', array_column($notes, 'title')), 'ENG-0001 has used 50% of its hours budget') && str_contains(implode('|', array_column($notes, 'title')), 'ENG-0001 has used 80% of its AI budget') && str_contains($notes[0]['body'], '/engagements/' . $acme), 'the manager is told per alert: "ENG-0001 has used 50% of its hours budget", "… 80% of its AI budget"');
ok((int) one("SELECT count(*) FROM notification_outbox WHERE kind = 'budget_alert' AND member_id = 27 AND channel = 'email'") === 2, 'budget_alert is among Marco\'s default kinds: two emails queued');
$log = activity('budget_alert.write', $since);
$a0 = json_decode((string) $log[0]['after'], true);
ok(count($log) === 2 && $log[0]['source'] === 'cron' && $log[0]['actor_member_id'] === null && (int) $log[0]['engagement_id'] === $acme && $a0['via'] === 'worker' && array_column(array_map(fn ($l) => json_decode((string) $l['after'], true), $log), 'value_at', 'kind')['ai'] === null, 'budget_alert.write logged once per alert: cron, no actor, the engagement; an AI alert carries no value (it is money)');
[$code, $rep] = worker('budgets', $today . 'T07:10:00Z');
ok(step_counts($rep, 'budgets')['alerts'] === 0 && (int) one('SELECT count(*) FROM budget_alerts') === $before + 2 && (int) one("SELECT count(*) FROM notifications WHERE kind = 'budget_alert'") === 2, 'a second pass: no new alert, no new notice — each crossing is written once');
q('UPDATE engagements SET budget_hours = :h WHERE id = :e', ['h' => number_format($H / 0.9, 2, '.', ''), 'e' => $acme]);
[$code, $rep] = worker('budgets', $today . 'T07:20:00Z');
ok(step_counts($rep, 'budgets')['alerts'] === 1 && (int) one("SELECT threshold_pct FROM budget_alerts WHERE engagement_id = :e AND kind = 'hours' ORDER BY threshold_pct DESC LIMIT 1", ['e' => $acme]) === 80 && (int) one("SELECT count(*) FROM notifications WHERE kind = 'budget_alert'") === 3, 'the budget tightened (90 % used): one new alert, the 80 % crossing — told once');
q('UPDATE engagements SET budget_hours = 400, ai_budget_amount = NULL WHERE id = :e', ['e' => $acme]);
finish();
