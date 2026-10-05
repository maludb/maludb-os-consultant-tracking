<?php
/**
 * Proof — the invoices falling due (spec "Proof", 3). The world is slice 7's: Acme has 620.00 approved and unbilled (Priya's 3 h = 540.00, a 20.00 taxi, a 60.00 hosting charge) and no open draft; Beta (a fixed bid) and Bea's engagement have nothing to bill.
 * Acme's billing date is today: ONE draft through the billing date (the unbilled items pulled, a second pass making no second draft), the date moved on a month, the manager and billing told, logged `invoice.draft` (cron, via cadence);
 * the engagements with nothing to bill have their date moved on without a draft; an engagement with a draft already open is left alone; an accepted, uninvoiced milestone on an on_milestone engagement becomes a draft.
 */
require __DIR__ . '/lib.php';
$w = report_world(); $acme = $w['acme']; $beta = $w['beta']; $beas = $w['beas'];
$marco = as_member(27); $bea = as_member(28);
$today = today();
q('UPDATE engagements SET next_billing_date = :d WHERE id = :e', ['d' => $today, 'e' => $acme]);
$lastEnd = (string) one("SELECT period_end FROM invoices WHERE number = 'INV-00001'");
$drafts = fn (int $e): int => (int) one("SELECT count(*) FROM invoices WHERE engagement_id = :e AND status = 'draft'", ['e' => $e]);
$notices = fn (int $m, int $inv): int => (int) one("SELECT count(*) FROM notifications WHERE member_id = :m AND kind = 'invoice_drafted' AND record_id = :i", ['m' => $m, 'i' => $inv]);
drain_outbox(); reset_fakes();
ok(wip($acme) === '620.00' && $drafts($acme) === 0 && (string) one('SELECT next_billing_date FROM engagements WHERE id = :e', ['e' => $beta]) < $today, 'before: Acme has 620.00 unbilled and no draft, its billing date is today; Beta\'s date has long passed with nothing to bill');
$since = last_activity_id();

echo "1. The first pass\n";
[$code, $rep] = worker('cadence', $today . 'T07:00:00Z');
$c = step_counts($rep, 'cadence');
ok($code === 0 && $c['drafted'] === 1 && $c['rolled'] === 2 && $c['milestones'] === 0 && $c['open_draft'] === 0 && $c['failed'] === 0 && count($c['invoices']) === 1, 'cadence: one draft (Acme), two dates moved on without a draft (Beta, Bea\'s engagement)');
$inv = $c['invoices'][0];
$i = inv($inv);
ok($i['status'] === 'draft' && (int) $i['engagement_id'] === $acme && $i['total'] === '620.00' && trim($i['currency']) === 'USD' && $i['created_by'] === null && $i['number'] === null && $i['period_end'] === $today && $i['period_start'] === plus_days($lastEnd, 1), 'the draft: Acme, 620.00 USD, no number yet, no creator (the system), the period from the day after INV-00001\'s end to the billing date');
$by = array_column(q("SELECT kind, sum(line_total) AS t FROM invoice_lines WHERE invoice_id = :i GROUP BY kind", ['i' => $inv]), 't', 'kind');
ok($by === ['expense' => '20.00', 'time' => '540.00', 'usage' => '60.00'] && items_of($inv) === 3 && wip($acme) === '0', 'the lines: time 540.00, expense 20.00, usage 60.00 — every item on the draft, none left unbilled');
$nb = (string) one('SELECT next_billing_date FROM engagements WHERE id = :e', ['e' => $acme]);
ok($nb === (string) one("SELECT ct_recurring_next(CAST(:d AS date), 'monthly', 1)", ['d' => $today]) && $nb > $today, 'the date moved on a month: ' . $nb);
ok((string) one('SELECT next_billing_date FROM engagements WHERE id = :e', ['e' => $beta]) > $today && (string) one('SELECT next_billing_date FROM engagements WHERE id = :e', ['e' => $beas]) > $today && $drafts($beta) === 0 && $drafts($beas) === 0, 'Beta\'s and Bea\'s dates are after today; no draft for either');
ok($notices(27, $inv) === 1 && $notices(1, $inv) === 1 && $notices(28, $inv) === 1 && $notices(30, $inv) === 0 && $notices(26, $inv) === 0, 'told: the manager (Marco), billing (Bea) and the owner (a super-admin) — once each; nobody else');
$n = q("SELECT title, body FROM notifications WHERE member_id = 27 AND kind = 'invoice_drafted' AND record_id = :i", ['i' => $inv])[0];
ok(str_contains($n['title'], '620.00 USD') && str_contains($n['body'], '/invoices/' . $inv) && str_contains($n['body'], 'billing cadence'), 'the notice names the total and links the draft');
$log = activity('invoice.draft', $since);
$after = json_decode((string) $log[0]['after'], true);
ok(count($log) === 1 && $log[0]['source'] === 'cron' && $log[0]['actor_member_id'] === null && (int) $log[0]['invoice_id'] === $inv && (int) $log[0]['engagement_id'] === $acme && $after['amount'] === '620.00' && $after['currency'] === 'USD' && $after['via'] === 'cadence' && $after['trigger'] === 'billing_date' && $after['counts']['time_lines'] === 1 && $after['counts']['expense_lines'] === 1 && $after['counts']['usage_lines'] === 1, 'invoice.draft logged: cron, no actor, the invoice and engagement, amount and currency, via cadence, the add-unbilled counts');
$upd = activity('engagement.update', $since);
ok(count($upd) === 3 && array_unique(array_column($upd, 'source')) === ['cron'] && json_decode((string) $upd[0]['before'], true) === ['next_billing_date' => $today] && json_decode((string) $upd[0]['after'], true)['next_billing_date'] === $nb, 'engagement.update logged for each date moved (three), cron, before and after');
$j = screen($marco, '/invoices/' . $inv);
ok($j[0] === 200 && $j[1]['invoice']['status'] === 'draft' && $j[1]['invoice']['total'] === '620.00', 'Marco opens the draft on its screen: it is an ordinary draft invoice');

echo "2. A second pass makes no second draft\n";
[$code, $rep] = worker('cadence', $today . 'T07:05:00Z');
$c = step_counts($rep, 'cadence');
ok($c['drafted'] === 0 && $c['rolled'] === 0 && $c['open_draft'] === 0 && $drafts($acme) === 1, 'the same day again: nothing due, still one draft');
q('UPDATE engagements SET next_billing_date = :d WHERE id = :e', ['d' => $today, 'e' => $acme]);
[$c0, $bx] = record_expense(as_member(26), $acme, '11.00', ['description' => 'SMOKE cadence taxi', 'submit' => '1', 'date' => $today]);
act($marco, '/expenses/approve.php', ['expense' => (int) $bx['record_id']]);
[$code, $rep] = worker('cadence', $today . 'T07:10:00Z');
$c = step_counts($rep, 'cadence');
ok($c['drafted'] === 0 && $c['open_draft'] === 1 && $drafts($acme) === 1 && (string) one('SELECT next_billing_date FROM engagements WHERE id = :e', ['e' => $acme]) === $today, 'the date due again, work to bill, but a draft is open: left alone (open_draft 1), the date unmoved — a second draft is never made');
q('UPDATE engagements SET next_billing_date = :d WHERE id = :e', ['d' => $nb, 'e' => $acme]);

echo "3. A milestone\n";
q("UPDATE engagements SET billing_cadence = 'on_milestone', next_billing_date = NULL WHERE id = :e", ['e' => $beta]);
[$c1, $b1] = act($marco, '/engagements/milestones/save.php', ['engagement' => $beta, 'name' => 'SMOKE cadence M1', 'amount' => '5000']);
$m1 = (int) $b1['record_id'];
act($marco, '/engagements/milestones/deliver.php', ['milestone' => $m1, 'note' => 'SMOKE delivered']);
act($marco, '/engagements/milestones/accept.php', ['milestone' => $m1, 'acceptance_note' => 'SMOKE Jo accepted by email']);
ok(one('SELECT status FROM milestones WHERE id = :m', ['m' => $m1]) === 'accepted', 'Beta\'s milestone (5,000.00) is accepted and uninvoiced');
$since = last_activity_id();
[$code, $rep] = worker('cadence', $today . 'T07:20:00Z');
$c = step_counts($rep, 'cadence');
$bd = (int) one("SELECT id FROM invoices WHERE engagement_id = :e AND status = 'draft'", ['e' => $beta]);
ok($c['milestones'] === 1 && $c['drafted'] === 0 && $bd > 0 && inv($bd)['total'] === '5000.00' && q("SELECT kind, description FROM invoice_lines WHERE invoice_id = :i", ['i' => $bd]) === [['kind' => 'milestone', 'description' => 'Milestone: SMOKE cadence M1']], 'an on_milestone engagement: one draft with the milestone as its line (5,000.00)');
$after = json_decode((string) activity('invoice.draft', $since)[0]['after'], true);
ok($after['via'] === 'cadence' && $after['trigger'] === 'milestone' && $notices(27, $bd) === 1 && one('SELECT next_billing_date FROM engagements WHERE id = :e', ['e' => $beta]) === null, 'logged as the milestone trigger; the manager told; no billing date invented');
[$code, $rep] = worker('cadence', $today . 'T07:25:00Z');
ok(step_counts($rep, 'cadence')['milestones'] === 0 && $drafts($beta) === 1, 'a second pass: no second draft for the same milestone');

echo "4. Nothing is issued, sent or approved\n";
ok((int) one("SELECT count(*) FROM invoices WHERE id IN (:a, :b) AND (number IS NOT NULL OR status <> 'draft' OR issued_at IS NOT NULL OR sent_at IS NOT NULL)", ['a' => $inv, 'b' => $bd]) === 0 && (int) one("SELECT count(*) FROM notification_outbox WHERE kind IN ('invoice', 'reminder')  AND status = 'queued' AND record_id IN (" . $inv . ',' . $bd . ')') === 0, 'both drafts are drafts: no number, not issued, not sent, no mail queued for a client');
finish();
