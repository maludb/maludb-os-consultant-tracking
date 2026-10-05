<?php
/**
 * Proof — overdue notices and reminders (spec "Proof", 6) with the settings' reminder_days {7,21,45}. Invoices issued with the due dates the proof chooses: A is 7 days past due (1,000.00), B 30 (500.00: past 7 and 21), C 3 (nothing yet), E 10 on a client nobody can be written to.
 * The pass tells billing and the manager ONCE per threshold (`invoice_overdue`) and reminds the client — through slice 6's send, fresh secure link and all — while the reminders sent number fewer than the thresholds crossed and none went in the last seven days;
 * logged `invoice.remind` (cron); `reminder_days = {}` turns it off; an invoice with nobody to write to is counted, never retried into a loop of notices.
 */
require __DIR__ . '/lib.php';
$w = report_world(); $acme = $w['acme']; $beas = $w['beas']; $client = billing_world()['client'];
$bea = as_member(28);
$today = today();
if ((int) one("SELECT count(*) FROM client_contacts WHERE client_id = :c AND receives_invoices AND email = 'ap@acme.example.invalid'", ['c' => $client]) === 0) { bill_contact($client, 'ap@acme.example.invalid'); }
function overdue_invoice(string $jar, int $engagement, int $daysLate, string $price): array
{
    global $today;
    $due = plus_days($today, -$daysLate);
    [$c, $b] = draft($jar, $engagement, ['add_unbilled' => 'no', 'issue_date' => plus_days($due, -14), 'due_date' => $due, 'period_end' => plus_days($due, -14), 'po_number' => 'PO-S8']);
    $d = (int) $b['record_id'];
    act($jar, '/invoices/lines/save.php', ['invoice' => $d, 'kind' => 'fixed', 'description' => 'SMOKE s8 fee', 'unit_price' => $price]);
    [$c, $b] = act($jar, '/invoices/issue.php', ['invoice' => $d]);
    if ($c !== 200) { fwrite(STDERR, "issue failed: " . json_encode($b) . "\n"); }
    return [$d, (string) $b['number']];
}
[$A, $nA] = overdue_invoice($bea, $acme, 7, '1000');
[$B, $nB] = overdue_invoice($bea, $acme, 30, '500');
[$C, $nC] = overdue_invoice($bea, $acme, 3, '200');
[$E, $nE] = overdue_invoice($bea, $beas, 10, '100');
q('UPDATE client_contacts SET receives_invoices = false WHERE client_id = (SELECT client_id FROM engagements WHERE id = :e)', ['e' => $beas]);
q('UPDATE clients SET email = NULL WHERE id = (SELECT client_id FROM engagements WHERE id = :e)', ['e' => $beas]);
drain_outbox(); reset_fakes();
ok((int) inv($A)['reminder_count'] === 0 && (int) inv($B)['reminder_count'] === 0 && one('SELECT reminder_days FROM ct_settings') === '{7,21,45}', "$nA, $nB, $nC, $nE issued 7, 30, 3 and 10 days past due; the settings' reminder days are 7, 21, 45");
$since = last_activity_id();

echo "1. The first pass\n";
[$code, $rep] = worker('reminders', $today . 'T07:00:00Z');
$c = step_counts($rep, 'reminders');
ok($code === 0 && $c['reminded'] === 2 && $c['notified'] === 3 && $c['no_recipient'] === 1 && $c['failed'] === 0 && $c['off'] === false, 'reminders: two reminded (A and B), three told (A, B, E — C has not crossed 7), one with nobody to write to (E)');
ok((int) inv($A)['reminder_count'] === 1 && (int) inv($B)['reminder_count'] === 1 && (int) inv($C)['reminder_count'] === 0 && (int) inv($E)['reminder_count'] === 0 && inv($A)['last_reminder_at'] !== null, 'reminder_count: A 1, B 1 (one a pass, though it has crossed two thresholds), C 0, E 0');
$ra = outbox_of('reminder', null, $A);
$tok = token_in((string) ($ra[0]['body'] ?? ''));
ok(count($ra) === 1 && $ra[0]['to_email'] === 'ap@acme.example.invalid' && str_starts_with((string) $ra[0]['subject'], 'A reminder: Invoice ' . $nA) && str_contains($ra[0]['body'], 'gentle reminder') && str_contains($ra[0]['body'], '1,000.00 USD') && strlen($tok) === 48 && visitor('/i/' . $tok)['code'] === 200, 'A\'s reminder is queued to the contact who receives invoices: the courtesy words, the balance, a FRESH secure link that opens');
$told = q("SELECT member_id FROM notifications WHERE kind = 'invoice_overdue' AND record_id = :i ORDER BY member_id", ['i' => $A]);
ok(array_column($told, 'member_id') === [1, 27, 28], 'billing (the owner, Bea) and the manager (Marco) are told A is overdue: `invoice_overdue`, once each');
$t = q("SELECT title, body FROM notifications WHERE kind = 'invoice_overdue' AND record_id = :i AND member_id = 28", ['i' => $A])[0];
ok($t['title'] === "$nA is 7 days past due (1,000.00 USD owed)" && str_contains($t['body'], '/invoices/' . $A), 'the notice: "INV… is 7 days past due (1,000.00 USD owed)" and a link');
ok((int) one("SELECT count(*) FROM notifications WHERE kind = 'invoice_overdue' AND record_id = :i", ['i' => $C]) === 0, 'C (3 days) is not told: no threshold crossed');
ok((int) one("SELECT count(*) FROM notifications WHERE kind = 'invoice_overdue' AND record_id = :i", ['i' => $E]) === 2 && outbox_of('reminder', null, $E) === [], 'E (nobody to write to): billing (the owner, Bea — Bea is also the manager) is still told, so someone chases it by hand, and no mail is queued');
$log = activity('invoice.remind', $since);
$la = json_decode((string) $log[0]['after'], true);
ok(count($log) === 2 && $log[0]['source'] === 'cron' && $log[0]['actor_member_id'] === null && (int) $log[0]['invoice_id'] === $A && $la['amount'] === '1000.00' && $la['currency'] === 'USD' && $la['recipients'] === 1 && $la['reminder_count'] === 1 && $la['days_past_due'] === 7 && $la['threshold'] === 7 && $la['via'] === 'worker' && !str_contains(json_encode($log), 'example.invalid') && !str_contains(json_encode($log), $tok), 'invoice.remind logged for A and B: cron, no actor, the balance and currency, the threshold — no address, no token');

echo "2. Not again within the week, not twice for a threshold\n";
[$code, $rep] = worker('reminders', $today . 'T07:30:00Z');
$c = step_counts($rep, 'reminders');
ok($c['reminded'] === 0 && $c['notified'] === 0 && $c['no_recipient'] === 1 && (int) inv($B)['reminder_count'] === 1 && count(outbox_of('reminder', null, $A)) === 1, 'the same day again: nobody reminded (B has crossed two thresholds but one went just now), no notice twice (E still has nobody to write to)');

echo "3. Eight days on: B's second threshold, C's first\n";
[$code, $rep] = worker('reminders', plus_days($today, 8) . 'T07:00:00Z');
$c = step_counts($rep, 'reminders');
$tokB1 = token_in((string) (outbox_of('reminder', null, $B)[0]['body'] ?? ''));
ok($c['reminded'] === 2 && (int) inv($B)['reminder_count'] === 2 && (int) inv($C)['reminder_count'] === 1 && (int) inv($A)['reminder_count'] === 1 && $c['notified'] === 1, 'B (38 days, thresholds 7 and 21) gets its second; C (11 days) its first; A (15 days, one threshold, one reminder) none; one new overdue notice (C)');
$tokB2 = token_in((string) (outbox_of('reminder', null, $B)[1]['body'] ?? ''));
ok(visitor('/i/' . $tokB1)['code'] === 404 && visitor('/i/' . $tokB2)['code'] === 200 && visitor('/i/' . $tok)['code'] === 200, 'B\'s first link is dead: a second reminder mints a fresh one and the old one stops working; A, not reminded again, keeps its link');
[$code, $rep] = worker('reminders', plus_days($today, 40) . 'T07:00:00Z');
ok(step_counts($rep, 'reminders')['reminded'] >= 2 && (int) inv($A)['reminder_count'] === 2 && (int) inv($B)['reminder_count'] === 3, '32 days later (A at 47 days has crossed 7, 21 and 45): the thresholds keep counting — A\'s second reminder, B\'s third');

echo "4. reminder_days = {} turns it off\n";
q("UPDATE ct_settings SET reminder_days = '{}' WHERE id = 1");
$n = (int) one("SELECT count(*) FROM notification_outbox WHERE kind = 'reminder'");
[$code, $rep] = worker('reminders', plus_days($today, 90) . 'T07:00:00Z');
ok(step_counts($rep, 'reminders') === ['reminded' => 0, 'notified' => 0, 'no_recipient' => 0, 'failed' => 0, 'off' => true] && (int) one("SELECT count(*) FROM notification_outbox WHERE kind = 'reminder'") === $n, 'with no days: off — nothing reminded, nothing told, nothing queued');
q("UPDATE ct_settings SET reminder_days = '{7,21,45}' WHERE id = 1");

echo "5. The mail goes\n";
set_sms(['mode' => 'ok']);
[$code, $rep] = worker('send', hours_from_now(1));
$mail = array_values(array_filter(mail_log(), fn ($m) => str_starts_with($m['subject'], 'A reminder:')));
ok(count($mail) >= 4 && $mail[0]['to'] === 'ap@acme.example.invalid' && strlen(token_in($mail[0]['text'])) === 48, 'the sender delivers the reminders (' . count($mail) . ' so far) to the contact, each with its own link');
finish();
