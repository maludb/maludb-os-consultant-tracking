<?php
/** Proof — budgets (spec "Proof", 5): with seeded entries the bars and the pace; budget_alert_write writes the alerts once; acknowledge; a second check writes nothing; the budgets screen. */
require __DIR__ . '/lib.php';
$marco = as_member(27); $bea = as_member(28); $priya = as_member(26); $ann = as_member(29);
[$acme, $card] = fixture_client();
[$c, $b] = act($marco, '/engagements/save.php', ['client' => $acme, 'name' => 'SMOKE Budget eng', 'kind' => 'time_and_materials', 'start_date' => date('Y-m-d', strtotime('-14 days')), 'budget_hours' => '40', 'budget_amount' => '10000', 'nte_amount' => '12000', 'ai_budget_amount' => '100', 'start_now' => 'yes']);
$eng = (int) $b['record_id'];
act($marco, '/engagements/staffing/save.php', ['engagement' => $eng, 'member' => 26, 'role' => role('consultant'), 'valid_from' => date('Y-m-d', strtotime('-14 days'))]);

echo "1. The bars\n";
foreach ([13, 12, 11, 6, 5] as $daysAgo) { mkentry($eng, 26, date('Y-m-d', strtotime("-$daysAgo days")), 7); }     // 35 hours at 180 = 6,300
[$c, $d] = screen($marco, '/engagements/' . $eng);
$bud = $d['engagement']['budget'];
ok($c === 200 && (float) $bud['hours_used'] === 35.0 && (float) $bud['hours_pct'] === 87.5 && (float) $bud['amount_used'] === 6300.0 && (float) $bud['amount_pct'] === 63.0 && (float) $bud['nte_pct'] === 0.0 && (float) $bud['invoiced_total'] === 0.0, 'ct_budget_status(): 35 of 40 hours (87.5%), 6,300 of 10,000 (63%), nothing invoiced against the NTE' . ((float) $bud['hours_used'] === 35.0 ? '' : ' (' . json_encode($bud) . ')'));
ok((float) $bud['pace_hours_per_week'] > 0 && $bud['weeks_left_at_pace'] !== null, 'the pace and the weeks left are worked out (' . $bud['pace_hours_per_week'] . ' h/week, ' . $bud['weeks_left_at_pace'] . ' weeks)');
$body = page($marco, '/engagements/' . $eng)['body'];
ok(str_contains($body, 'id="budget-hours-' . $eng . '"') && str_contains($body, 'bg-warning') && str_contains($body, 'id="budget-pace-' . $eng . '"'), 'the hours bar is warning (80–100%) and the pace line shows');

echo "2. The alerts\n";
$since = last_activity_id();
[$c, $b] = act($marco, '/usage/budgets/check.php', ['engagement' => $eng]);
ok($c === 200 && $b['new_alerts'] === 3 && $b['checked'] === 1, 'budget_alert_write on the engagement: 3 new alerts (hours 50 and 80, amount 50)');
$alerts = q('SELECT kind, threshold_pct FROM budget_alerts WHERE engagement_id = :e ORDER BY kind, threshold_pct', ['e' => $eng]);
ok($alerts === [['kind' => 'amount', 'threshold_pct' => 50], ['kind' => 'hours', 'threshold_pct' => 50], ['kind' => 'hours', 'threshold_pct' => 80]], 'the alerts written: amount 50, hours 50 and 80');
ok(count(activity('budget_alert.write', $since)) === 3 && (int) activity('budget_alert.write', $since)[0]['engagement_id'] === $eng, 'budget_alert.write logged once per alert, keyed to the engagement');
[$c, $b] = act($marco, '/usage/budgets/check.php', ['engagement' => $eng]);
ok($c === 200 && $b['new_alerts'] === 0 && count(activity('budget_alert.write', $since)) === 3, 'a second check writes nothing');
[$c, $d] = screen($marco, '/engagements/' . $eng);
ok(count($d['engagement']['alerts']) === 3 && $d['engagement']['open_alerts'] === 3, 'the page shows three open alerts');
$aid = (int) one("SELECT id FROM budget_alerts WHERE engagement_id = :e AND kind = 'hours' AND threshold_pct = 80", ['e' => $eng]);
$since = last_activity_id();
[$c, $b] = act($marco, '/usage/budgets/acknowledge.php', ['alert' => $aid]);
ok($c === 200 && (int) one('SELECT acknowledged_by FROM budget_alerts WHERE id = :a', ['a' => $aid]) === 27 && count(activity('budget_alert.acknowledge', $since)) === 1, 'acknowledged by Marco, logged');
[$c, $b] = act($marco, '/usage/budgets/acknowledge.php', ['alert' => $aid]);
ok($c === 422 && msg($b) === 'That alert is already acknowledged.', 'acknowledging it again: 422');
[$c, $b] = act($priya, '/usage/budgets/acknowledge.php', ['alert' => (int) one("SELECT id FROM budget_alerts WHERE engagement_id = :e AND kind = 'amount'", ['e' => $eng])]);
ok($c === 403, 'a Consultant may not acknowledge: 403');
[$c, $b] = act($ann, '/usage/budgets/check.php', []);
ok($c === 403, 'Finance may not check: 403');
[$c, $b] = act($bea, '/usage/budgets/check.php', []);
ok($c === 200 && $b['checked'] >= 1, 'Billing checks every active engagement (' . $b['checked'] . ')');
// the AI alert at the settings' 80%: a usage charge at cost (slice 5 brings the screens)
q("INSERT INTO usage_charges (engagement_id, provider_id, kind, description, period_start, period_end, cost_amount, quantity, unit, unit_cost, pricing, billable, currency, status, created_by) VALUES (:e, (SELECT id FROM usage_providers WHERE provider_key = 'anthropic'), 'model_usage', 'SMOKE tokens', current_date - 7, current_date, 85, 1000000, 'tokens', 0.000085, 'cost', true, 'USD', 'draft', 27)", ['e' => $eng]);
[$c, $b] = act($marco, '/usage/budgets/check.php', ['engagement' => $eng]);
$ai = q("SELECT kind, threshold_pct, value_at FROM budget_alerts WHERE engagement_id = :e AND kind = 'ai'", ['e' => $eng]);
ok($c === 200 && $b['new_alerts'] === 1 && $ai === [['kind' => 'ai', 'threshold_pct' => 80, 'value_at' => '85.00']], 'an AI charge of 85 against a 100 budget: the ai alert at 80% once' . ($b['new_alerts'] === 1 ? '' : ' (' . json_encode($ai) . ' cost ' . one('SELECT cost_amount FROM usage_charges ORDER BY id DESC LIMIT 1') . ')'));
$row = q("SELECT after::text AS a FROM activity_log WHERE action = 'budget_alert.write' ORDER BY id DESC LIMIT 1")[0]['a'];
ok(str_contains($row, '"kind": "ai"') && str_contains($row, '"value_at": null'), 'the ai alert\'s log carries no amount (a cost)');
[$c, $d] = screen($priya, '/engagements/' . $eng);
$aiAlert = array_values(array_filter($d['engagement']['alerts'], fn ($a) => $a['kind'] === 'ai'))[0] ?? null;
ok($aiAlert === null || $aiAlert['value_at'] === null, 'Priya sees no AI alert figures (the view admits her no alerts at all without engagements.manage or reports.view)');

echo "3. The budgets screen\n";
[$c, $d] = screen($marco, '/usage/budgets');
ok($c === 200 && in_array($eng, array_column($d['engagements'], 'engagement_id'), true) && count($d['alerts']) >= 3 && $d['may']['check'] === true, 'the budgets screen: the active engagements with their bars and the open alerts');
$body = page($marco, '/usage/budgets')['body'];
ok(str_contains($body, 'id="budget-engagement-' . $eng . '"') && str_contains($body, 'id="budget-alerts-check-btn"') && str_contains($body, '-ack-btn"'), 'with Check now and Acknowledge buttons');
ok(page($priya, '/usage/budgets')['code'] === 403 && page($ann, '/usage/budgets')['code'] === 200 && !str_contains(page($ann, '/usage/budgets')['body'], 'id="budget-alerts-check-btn"'), 'a Consultant is refused; Finance reads it without Check now');
[$c, $d] = screen($marco, '/');
ok(count($d['budget_alerts']['open']) >= 3, 'the home\'s region lists the open alerts');
finish();
