<?php
/**
 * Proof — the home, per role (spec "Proof", 1; design §9). Every region reads its slice's functions, to the right people: a Consultant (Priya) sees her week, her timer, what she is owed, her bell — no manager and no billing region; a Manager (Marco) adds the approvals waiting
 * (weeks, expenses, usage charges as count cards that link to the pages), the open budget alerts and the engagements ending within 30 days; Billing (Bea) the WIP total, the drafts to issue, invoices due to draft, who owes us (the aging's five buckets), the OS statement's last month and
 * its remainder, the payment claims waiting and the Clerk's last morning note; the administrator all of it. Every figure is checked against the database; the JSON answers every region through a presenter; every empty state says what it is, never a slice.
 */
require __DIR__ . '/lib.php';
$w = home_world(); pulled_month(); $acme = $w['acme']; $beta = $w['beta'];
$owner = as_member(1); $priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29); $dana = as_member(30);
$today = today();
$mon = monday(0);

echo "0. The fixtures: a timer, a week and an expense awaiting Marco, a draft charge, an alert, an engagement ending, the OS month\n";
if ((int) one("SELECT count(*) FROM expenses WHERE description = 'SMOKE s9 pending'") === 0) {
    [$c, $b] = act($priya, '/time/timer/start.php', ['engagement' => $acme]);
    ok($c === 200, 'Priya starts her timer on Acme');
    [$c, $b] = log_time($dana, $acme, day(monday(1), 1), '1.5', ['note' => 'SMOKE s9 pending week']);
    act($dana, '/time/weeks/submit.php', ['week' => monday(1)]);
    ok($c === 200 && (int) one("SELECT count(*) FROM timesheets WHERE member_id = 30 AND week_start = :w AND status = 'submitted'", ['w' => monday(1)]) === 1, 'Dana\'s last week, 1.5 h on Acme, is submitted and awaits Marco');
    [$c, $b] = record_expense($priya, $acme, '15.00', ['description' => 'SMOKE s9 pending', 'submit' => '1']);
    ok($c === 200 && expense_row((int) $b['record_id'])['status'] === 'submitted', 'Priya\'s 15.00 expense is submitted and awaits Marco');
    [$c, $b] = draft_charge($bea, $acme, ['description' => 'SMOKE s9 draft charge']);
    ok($c === 200 && usage_row((int) $b['record_id'])['status'] === 'draft', 'a hosting charge is a draft and awaits Marco');
    q("INSERT INTO budget_alerts (engagement_id, kind, threshold_pct, value_at, budget_at) VALUES (:e, 'amount', 80, 16000, 20000) ON CONFLICT DO NOTHING", ['e' => $beta]);
    q("UPDATE engagements SET end_date = current_date + 10 WHERE id = :e", ['e' => $beta]);
}
$stmt = q("SELECT s.period, s.status, (SELECT round(sum(amount), 2) FROM ai_statement_lines l WHERE l.statement_id = s.id) AS amt, (SELECT COALESCE(sum(allocated_amount), 0) FROM ai_statement_lines l WHERE l.statement_id = s.id) AS alloc FROM ai_statements s ORDER BY s.period DESC LIMIT 1")[0];
$hours = (string) one("SELECT COALESCE(sum(e.hours), 0) FROM time_entries e JOIN timesheets t ON t.id = e.timesheet_id WHERE t.member_id = 26 AND t.week_start = :w", ['w' => $mon]);
$owedP = (string) one("SELECT COALESCE(sum(amount), 0) FROM expenses WHERE member_id = 26 AND status = 'approved' AND paid_with = 'personal' AND reimbursed_at IS NULL");

echo "1. Priya (a Consultant)\n";
[$c, $d] = screen($priya, '/');
ok($c === 200 && array_keys($d) === ['may', 'my_week', 'timer', 'my_expenses', 'approvals', 'budget_alerts', 'billing', 'clerk_note', 'bell'], 'the home (JSON) answers every region by name: may, my_week, timer, my_expenses, approvals, budget_alerts, billing, clerk_note, bell');
ok($d['may']['time'] === true && $d['may']['approve'] === false && $d['may']['manage'] === false && $d['may']['billing'] === false && $d['may']['clerk'] === false, 'she may log time and expenses; she may not approve, manage or bill');
ok(same($d['my_week']['total_hours'], $hours) && $d['my_week']['week_start'] === $mon && $d['my_week']['status'] === 'approved' && $d['timer'] !== null && $d['timer']['engagement']['number'] !== '', "My week: {$d['my_week']['total_hours']} h in the week of $mon (the database says $hours), approved by Marco in the world; the timer is running");
ok($d['my_expenses'] !== null && same($d['my_expenses']['owed']['USD'] ?? 0, $owedP) && $d['my_expenses']['counts']['submitted'] >= 1, "My expenses: owed {$owedP} USD (the database says the same), one or more submitted");
ok($d['approvals'] === null && $d['budget_alerts'] === null && $d['billing'] === null && $d['clerk_note'] === null, 'approvals, budget alerts, billing and the Clerk\'s note are null for her');
$r = page($priya, '/');
foreach (['home-my-week', 'home-my-week-timer', 'home-my-expenses', 'home-bell'] as $id) { ok(str_contains($r['body'], 'id="' . $id . '"'), "her page shows #$id"); }
foreach (['home-approvals', 'home-budget-alerts', 'home-billing', 'home-clerk-note'] as $id) { ok(!str_contains($r['body'], 'id="' . $id . '"'), "her page has no #$id"); }
ok(!preg_match('/\(slices? \d/', $r['body']) && !str_contains($r['body'], 'is not built yet'), 'no region says which slice builds it');

echo "2. Marco (a Manager)\n";
[$c, $d] = screen($marco, '/');
ok($d['may']['approve'] === true && $d['may']['manage'] === true && $d['may']['billing'] === true && $d['may']['clerk'] === false && $d['billing'] !== null && $d['billing']['aging'] === ['current_due' => [], 'd1_30' => [], 'd31_60' => [], 'd61_90' => [], 'd90_plus' => []] && $d['billing']['statement'] === null && $d['clerk_note'] === null, 'he may approve and manage, and has invoices.draft so the Billing region shows his engagements\' figures — but no aging (receivables.view is firm-wide), no OS statement and no Clerk note');
$ap = $d['approvals'];
$wk = (int) one("SELECT count(*) FROM timesheets WHERE status = 'submitted'"); $ex = (int) one("SELECT count(*) FROM expenses WHERE status = 'submitted' AND member_id <> 27"); $us = (int) one("SELECT count(*) FROM usage_charges WHERE status = 'draft'");
ok($ap['counts'] === ['weeks' => $wk, 'expenses' => $ex, 'usage' => $us] && $wk >= 1 && $ex >= 1 && $us >= 1, "approvals waiting: counts weeks $wk, expenses $ex, usage charges $us — the database agrees (every one at least 1)");
ok(count($ap['weeks']) === $wk && $ap['weeks'][0]['member']['display_name'] === 'SMOKE Dana' && count($ap['expenses']) === $ex && count($ap['usage']) === $us && isset($ap['usage'][0]['usage_charge_id']), 'the lists behind the counts: Dana\'s week, the expense, the charge — each through a presenter');
$bm = $d['budget_alerts'];
ok(in_array($beta, array_column(array_column($bm['open'], 'engagement'), 'engagement_id'), true) && in_array($beta, array_column($bm['ending'], 'engagement_id'), true) && $bm['active'] >= 2, 'budget alerts: the open 80 % alert on Beta and Beta among the engagements ending within 30 days; the active count');
$r = page($marco, '/');
foreach (['home-approvals', 'home-approvals-weeks-count', 'home-approvals-expenses-count', 'home-approvals-usage-count', 'home-budget-alerts', 'home-my-week'] as $id) { ok(str_contains($r['body'], 'id="' . $id . '"'), "his page shows #$id"); }
ok(preg_match('/id="home-approvals-weeks-count">' . $wk . '</', $r['body']) === 1 && preg_match('/id="home-approvals-usage-count">' . $us . '</', $r['body']) === 1 && str_contains($r['body'], 'href="/time/approvals"') && str_contains($r['body'], 'href="/expenses/approvals"'), 'the count cards show the numbers and link to the approval pages');
ok(str_contains($r['body'], 'id="home-billing"') && !str_contains($r['body'], 'id="home-billing-claims"') && !str_contains($r['body'], 'id="home-clerk-note"'), 'his page: the Billing region without the claims row, and no Clerk note');

echo "3. Bea (Billing)\n";
[$c, $d] = screen($bea, '/');
$b = $d['billing'];
ok($d['may']['billing'] === true && $d['may']['clerk'] === true && $d['may']['approve'] === true && $d['approvals'] !== null, 'she bills and may send the note; the Billing role holds the Manager\'s approving rights too, so the approvals region is hers as well');
pdo()->exec("SELECT set_config('app.member_id', '28', false)");
$wip = (string) one("SELECT COALESCE(sum(total_amount), 0) FROM ct_unbilled(current_date) WHERE trim(currency) = 'USD'");
ok(isset($b['unbilled']['USD']) && same($b['unbilled']['USD'], $wip) && $wip > 0, "WIP: {$b['unbilled']['USD']} USD — ct_unbilled() says $wip");
$drafts = (int) one("SELECT count(*) FROM invoices WHERE status = 'draft'"); $open = q("SELECT currency, sum(total - amount_paid) AS owed FROM invoices WHERE status IN ('issued','sent','viewed','partially_paid') GROUP BY currency");
ok($b['draft_count'] === $drafts && same($b['owed']['USD'] ?? 0, $open[0]['owed'] ?? 0) && isset($b['aging']['current_due'], $b['aging']['d1_30'], $b['aging']['d31_60'], $b['aging']['d61_90'], $b['aging']['d90_plus']) && array_keys($b['aging']) === ['current_due', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'], "$drafts draft(s) to issue; who owes us " . ($open[0]['owed'] ?? 0) . ' USD; the aging\'s five buckets');
$claims = (int) one("SELECT count(*) FROM payment_claims WHERE status = 'submitted'");
ok($b['claims_waiting'] === $claims && is_int($b['due_to_draft']) && $b['milestones_waiting'] >= 0, "claims waiting $claims; due to draft {$b['due_to_draft']}");
ok($b['statement'] !== null && $b['statement']['period'] === $stmt['period'] && same($b['statement']['amount'], $stmt['amt']) && same($b['statement']['remainder'], (float) $stmt['amt'] - (float) $stmt['alloc']), "the OS statement: {$b['statement']['period']}, {$b['statement']['amount']} USD with a remainder of {$b['statement']['remainder']} — the database agrees");
ok($d['clerk_note']['title'] === 'The morning note, SMOKE s9' && $d['clerk_note']['for'] === 'SMOKE Bea', 'the Clerk\'s last morning note, left for Bea');
ok(count($d['bell']) >= 2 && count($d['bell']) <= 5 && in_array('morning_note', array_column($d['bell'], 'kind'), true) && array_keys($d['bell'][0]) === ['notification_id', 'kind', 'record_type', 'record_id', 'title', 'body', 'read', 'created_at'], 'the bell\'s last five (the note and the statement\'s allocation), through the notification presenter');
$r = page($bea, '/');
foreach (['home-billing', 'home-billing-unbilled-amount', 'home-billing-drafts-count', 'home-billing-due-count', 'home-billing-owed-amount', 'home-billing-aging', 'home-billing-statement', 'home-billing-claims-count', 'home-clerk-note', 'home-clerk-note-body'] as $id) { ok(str_contains($r['body'], 'id="' . $id . '"'), "her page shows #$id"); }
ok(str_contains($r['body'], 'The morning note, SMOKE s9') && preg_match('/home-billing-statement-remainder">[^<]*to allocate|home-billing-statement-remainder">allocated/', $r['body']) === 1 && str_contains($r['body'], 'id="home-approvals"'), 'the note and the statement\'s remainder are written');

echo "4. The administrator, and Ann (Finance)\n";
[$c, $d] = screen($owner, '/');
ok($d['may']['approve'] === true && $d['may']['billing'] === true && $d['may']['clerk'] === true && $d['may']['manage'] === true && $d['billing'] !== null && $d['approvals'] !== null && $d['budget_alerts'] !== null && $d['clerk_note'] !== null, 'the administrator: every region answered');
[$c, $d] = screen($ann, '/');
ok($c === 200 && $d['may']['time'] === false && $d['my_week'] === null && $d['timer'] === null && $d['my_expenses'] === null && $d['approvals'] === null && $d['clerk_note'] === null && $d['billing'] !== null && $d['may']['clerk'] === false, 'Ann (Finance, reads): no week, no timer, no expenses, no approvals, no Clerk note — the billing figures she may read');
$r = page($ann, '/');
ok(!str_contains($r['body'], 'id="home-my-week"') && !str_contains($r['body'], 'id="home-clerk-note"') && str_contains($r['body'], 'id="home-billing"'), 'her page: the billing region only');

echo "5. Empty states\n";
$noDana = page($dana, '/');
ok(str_contains($noDana['body'], 'id="home-bell"') && ($d2 = screen($dana, '/')[1]) && $d2['approvals'] === null, 'Dana\'s page is the Consultant\'s');
q("DELETE FROM notifications WHERE member_id = 30");
$r = page($dana, '/');
ok(str_contains($r['body'], 'id="home-bell-empty"') && str_contains($r['body'], 'Nothing yet'), 'a bell with nothing in it says "Nothing yet", not a slice name');
finish();
