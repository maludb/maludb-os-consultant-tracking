<?php
/**
 * Proof — the outbox (spec "Proof", 1): every queued shape reaches the fake MaluMail or the fake kernel's K6 with the right address and words, the secure link included; `sent` with the provider's reference; a refused send is retried after a
 * backoff and the fifth failure final; a text the kernel refuses is skipped with its code and its email sibling still goes; an opted-out text never reaches the kernel; nothing is sent twice; two runs never overlap; a log row never holds a body, an address or a link.
 */
require __DIR__ . '/lib.php';
$w = report_world(); $acme = $w["acme"]; $client = billing_world()["client"];
$bea = as_member(28); $priya = as_member(26); $marco = as_member(27);
drain_outbox(); reset_fakes();
$inv = (int) invoice_by_number('INV-00001')['id'];
$npass = fn (): int => (int) one("SELECT count(*) FROM activity_log WHERE action = 'worker.pass'");

echo "1. An invoice's mail\n";
bill_contact($client, 'ap@acme.example.invalid');
[$c, $b] = act($bea, '/invoices/send.php', ['invoice' => $inv, 'message' => 'SMOKE a word from us']);
$rows = outbox_of('invoice', null, $inv);
ok($c === 200 && count($rows) === 1 && $rows[0]['status'] === 'queued' && $rows[0]['to_email'] === 'ap@acme.example.invalid' && $rows[0]['attempts'] === 0, 'invoice_send queued one client row (to ap@acme.example.invalid), status queued, no attempt yet');
$p0 = $npass();
[$code, $rep] = worker('send');
ok($code === 0 && step_counts($rep, 'send')['sent'] === 1 && $npass() === $p0 + 1, 'the worker\'s send step: exit 0, one sent, one worker.pass row for the run');
$row = q('SELECT * FROM notification_outbox WHERE id = :i', ['i' => $rows[0]['id']])[0];
$mail = mail_log();
ok($row['status'] === 'sent' && $row['attempts'] === 1 && $row['sent_at'] !== null && preg_match('/^<mm-\d+@fake\.malumail>$/', (string) $row['provider_ref']) === 1 && $row['detail'] === null, 'the row is sent, one attempt, sent_at stamped, the provider\'s message id kept as provider_ref');
$tok = token_in((string) ($mail[0]['text'] ?? ''));
ok(count($mail) === 1 && $mail[0]['to'] === 'ap@acme.example.invalid' && $mail[0]['subject'] === $rows[0]['subject'] && $mail[0]['from'] === getenv('MAIL_FROM') && str_contains($mail[0]['text'], 'SMOKE a word from us') && str_contains($mail[0]['text'], '2,479.00 USD'), 'the fake MaluMail got one mail: the address, the subject as queued, MAIL_FROM, the message and the total in the text');
ok(strlen($tok) === 48 && str_contains((string) $mail[0]['html'], '/i/' . $tok) && visitor('/i/' . $tok)['code'] === 200 && str_contains(visitor('/i/' . $tok)['body'], 'INV-00001'), 'the secure link (48 hex) is in the text and the html, and it opens the client\'s page for INV-00001');
ok(!preg_match('/cost|internal|SMOKE Beta/i', $mail[0]['text']), 'the mail names no cost, no internal note, no other client');

echo "2. A person: an email and a text through the kernel (K6)\n";
act($priya, '/settings/prefs.php', ['text_enabled' => 'yes', 'text_kinds' => ['expense_approved', 'expense_rejected'], 'kinds' => ['expense_approved', 'expense_rejected']]);
[$c, $b] = record_expense($priya, $acme, '30.00', ['description' => 'SMOKE ob taxi one', 'submit' => '1', 'billable' => 'no']);
[$c, $b2] = act($marco, '/expenses/approve.php', ['expense' => (int) $b['record_id']]);
$x1 = (int) $b['record_id'];
$rows = outbox_of('expense_approved', 26, $x1);
ok($c === 200 && array_column($rows, 'channel') === ['email', 'text'] && $rows[0]['status'] === 'queued', 'Priya chose texts: an expense approval queues an email row and a text row');
reset_fakes(); set_sms(['mode' => 'ok']);
[$code, $rep] = worker('send');
$rows = outbox_of('expense_approved', 26, $x1);
$sms = sms_log(); $mail = mail_log();
ok($rows[0]['status'] === 'sent' && $rows[1]['status'] === 'sent' && step_counts($rep, 'send')['sent'] >= 2, 'both rows sent (with whatever else was queued: Marco\'s notice of the submission)');
$toPriya = array_values(array_filter($mail, fn ($m) => $m['to'] === 'priya@example.invalid'));
ok(count($toPriya) === 1 && str_contains($toPriya[0]['subject'], 'was approved') && str_contains($toPriya[0]['text'], '/notifications'), 'the email: Priya\'s address from the mirror, the title as subject, a link to the bell');
ok(count($sms) === 1 && $sms[0]['member_id'] === 26 && str_contains($sms[0]['text'], 'was approved') && mb_strlen($sms[0]['text']) <= 460 && str_starts_with((string) $sms[0]['reference'], 'ct-outbox-') && !empty($sms[0]['accepted']), 'the text: K6 was asked for member 26 (never a number), the words, a reference, accepted');
ok(preg_match('/^kernel:\d+$/', (string) $rows[1]['provider_ref']) === 1 && $rows[1]['detail'] === null && $rows[1]['attempts'] === 1, 'the text row keeps the kernel\'s notification id as its provider reference');

echo "3. The kernel refuses a text: skipped with the code, the email still goes\n";
set_sms(['mode' => 'no_sender']);
[$c, $b] = record_expense($priya, $acme, '31.00', ['description' => 'SMOKE ob taxi two', 'submit' => '1', 'billable' => 'no']);
act($marco, '/expenses/approve.php', ['expense' => (int) $b['record_id']]);
$x2 = (int) $b['record_id'];
reset_fakes(); set_sms(['mode' => 'no_sender']);
[$code, $rep] = worker('send');
$rows = outbox_of('expense_approved', 26, $x2);
ok($rows[0]['channel'] === 'email' && $rows[0]['status'] === 'sent' && $rows[1]['channel'] === 'text' && $rows[1]['status'] === 'skipped' && $rows[1]['detail'] === 'no_sender' && $rows[1]['attempts'] === 1, 'no_sender (503): the text is SKIPPED with the code; the email row, a row of its own, is sent');
ok(count(sms_log()) === 1 && sms_log()[0]['mode'] === 'no_sender' && count(mail_log()) === 1, 'the kernel was asked once; the mail went once');
foreach (['not_held', 'no_verified_phone', 'opted_out', 'rate_limited'] as $mode) {
    [$c, $b] = record_expense($priya, $acme, '32.00', ['description' => 'SMOKE ob ' . $mode, 'submit' => '1', 'billable' => 'no']);
    act($marco, '/expenses/approve.php', ['expense' => (int) $b['record_id']]);
    set_sms(['mode' => $mode]);
    worker('send');
    $t = outbox_of('expense_approved', 26, (int) $b['record_id'])[1];
    $codes[$mode] = [$t['status'], $t['detail']];
}
ok($codes === ['not_held' => ['skipped', 'not_held'], 'no_verified_phone' => ['skipped', 'no_verified_phone'], 'opted_out' => ['skipped', 'opted_out'], 'rate_limited' => ['skipped', 'rate_limited']], 'every refusal the kernel names is a skip with its code: not_held, no_verified_phone, opted_out, rate_limited');
ok(last_text_refusal_is(26, 'rate_limited'), 'and the settings page can say why (the last refusal in the outbox)');

echo "4. A text for someone who turned texts off never reaches the kernel\n";
set_sms(['mode' => 'ok']); reset_fakes(); set_sms(['mode' => 'ok']);
q("INSERT INTO notification_outbox (channel, member_id, kind, subject, body) VALUES ('text', 28, 'missing_time', 'SMOKE ob nudge', 'SMOKE ob text for Bea')");
worker('send');
$t = q("SELECT * FROM notification_outbox WHERE subject = 'SMOKE ob nudge'")[0];
ok($t['status'] === 'skipped' && $t['detail'] === 'opted_out' && sms_log() === [], 'Bea\'s text prefs are off: skipped as opted_out, and the kernel was not called');

echo "5. The kernel\'s own limit: 30 texts a day to one person\n";
q("INSERT INTO notification_prefs (member_id, text_enabled, text_kinds) VALUES (30, true, '{missing_time}') ON CONFLICT (member_id) DO UPDATE SET text_enabled = true, text_kinds = '{missing_time}'");
for ($i = 1; $i <= 31; $i++) { q("INSERT INTO notification_outbox (channel, member_id, kind, subject, body) VALUES ('text', 30, 'missing_time', :s, 'SMOKE ob bulk')", ['s' => 'SMOKE ob bulk ' . $i]); }
reset_fakes(); set_sms(['mode' => 'ok']);
[$code, $rep] = worker('send');
$bulk = q("SELECT status, detail FROM notification_outbox WHERE subject LIKE 'SMOKE ob bulk %' ORDER BY id");
$sent = count(array_filter($bulk, fn ($r) => $r['status'] === 'sent'));
ok($sent === 30 && $bulk[30]['status'] === 'skipped' && $bulk[30]['detail'] === 'rate_limited' && count(sms_log()) === 31, '31 texts for Dana: the fake kernel accepts 30 and refuses the 31st (rate_limited) — a skip, never retried');

echo "6. A mail the relay refuses is retried after a backoff; the fifth failure is final\n";
q("INSERT INTO notification_outbox (channel, client_id, to_email, kind, record_type, record_id, subject, body) VALUES ('email', :c, 'flaky@x.example.invalid', 'reminder', 'invoice', :i, 'SMOKE ob flaky', 'SMOKE body')", ['c' => $client, 'i' => $inv]);
$fid = (int) one("SELECT id FROM notification_outbox WHERE subject = 'SMOKE ob flaky'");
reset_fakes();
$T = time() + 1;
$at = fn (int $mins): string => gmdate('Y-m-d\TH:i:s\Z', $T + 60 * $mins);
[$code, $rep] = worker('send', $at(0));
$r = q('SELECT * FROM notification_outbox WHERE id = :i', ['i' => $fid])[0];
ok($r['status'] === 'queued' && $r['attempts'] === 1 && $r['detail'] === 'retry:502' && step_counts($rep, 'send')['retried'] === 1 && strtotime($r['send_after']) >= $T + 110 && strtotime($r['send_after']) <= $T + 130, 'attempt 1 (502): still queued, attempts 1, detail retry:502, send_after about two minutes on');
[$code, $rep] = worker('send', $at(1));
ok(count(mail_log()) === 1 && (int) one('SELECT attempts FROM notification_outbox WHERE id = :i', ['i' => $fid]) === 1, 'a pass before send_after leaves it alone (no second attempt)');
foreach ([[3, 2, 4], [8, 3, 8], [17, 4, 16]] as [$min, $n, $back]) {
    worker('send', $at($min));
    $r = q('SELECT * FROM notification_outbox WHERE id = :i', ['i' => $fid])[0];
    ok($r['status'] === 'queued' && $r['attempts'] === $n && strtotime($r['send_after']) >= $T + 60 * $min + 60 * $back - 5 && strtotime($r['send_after']) <= $T + 60 * $min + 60 * $back + 30, "attempt $n: queued, a backoff of $back minutes");
}
[$code, $rep] = worker('send', $at(40));
$r = q('SELECT * FROM notification_outbox WHERE id = :i', ['i' => $fid])[0];
ok($r['status'] === 'failed' && $r['attempts'] === 5 && $r['detail'] === 'failed:502' && step_counts($rep, 'send')['failed'] === 1 && count(mail_log()) === 5, 'the fifth failure is final: failed, attempts 5, detail failed:502 — five requests reached the relay');
worker('send', $at(200));
ok(count(mail_log()) === 5, 'a failed row is never sent again');
q("INSERT INTO notification_outbox (channel, client_id, to_email, kind, record_type, record_id, subject, body) VALUES ('email', :c, 'flakyonce@x.example.invalid', 'reminder', 'invoice', :i, 'SMOKE ob once', 'SMOKE body')", ['c' => $client, 'i' => $inv]);
$oid = (int) one("SELECT id FROM notification_outbox WHERE subject = 'SMOKE ob once'");
worker('send', hours_from_now(1));
ok(one('SELECT status FROM notification_outbox WHERE id = :i', ['i' => $oid]) === 'queued', 'a relay that refuses once: queued for the retry');
worker('send', hours_from_now(2));
$r = q('SELECT * FROM notification_outbox WHERE id = :i', ['i' => $oid])[0];
ok($r['status'] === 'sent' && $r['attempts'] === 2 && preg_match('/^<mm-\d+@fake\.malumail>$/', (string) $r['provider_ref']) === 1, 'the retry is sent: attempts 2, the provider\'s id kept');

echo "7. Skips: a suppressed address, no address, not yet due\n";
q("INSERT INTO notification_outbox (channel, client_id, to_email, kind, record_type, record_id, subject, body) VALUES ('email', :c, 'suppressed@x.example.invalid', 'reminder', 'invoice', :i, 'SMOKE ob suppressed', 'SMOKE body')", ['c' => $client, 'i' => $inv]);
q("INSERT INTO notification_outbox (channel, client_id, to_email, kind, record_type, record_id, subject, body) VALUES ('email', :c, NULL, 'reminder', 'invoice', :i, 'SMOKE ob noaddress', 'SMOKE body')", ['c' => $client, 'i' => $inv]);
q("INSERT INTO notification_outbox (channel, client_id, to_email, kind, record_type, record_id, subject, body, send_after) VALUES ('email', :c, 'later@x.example.invalid', 'reminder', 'invoice', :i, 'SMOKE ob later', 'SMOKE body', now() + interval '1 day')", ['c' => $client, 'i' => $inv]);
$before = count(mail_log());
worker('send', hours_from_now(3));
$rs = array_column(q("SELECT subject, status, detail FROM notification_outbox WHERE subject IN ('SMOKE ob suppressed', 'SMOKE ob noaddress', 'SMOKE ob later')"), null, 'subject');
ok($rs['SMOKE ob suppressed']['status'] === 'skipped' && str_starts_with((string) $rs['SMOKE ob suppressed']['detail'], 'undeliverable:suppressed'), 'a suppressed address (the relay\'s 400): skipped as undeliverable, not retried');
ok($rs['SMOKE ob noaddress']['status'] === 'skipped' && $rs['SMOKE ob noaddress']['detail'] === 'no_address' && count(mail_log()) === $before + 1, 'a client row with no address: skipped as no_address, nothing reached the relay for it');
ok($rs['SMOKE ob later']['status'] === 'queued', 'a row whose send_after is tomorrow is left alone');

echo "8. Nothing is sent twice; two runs never overlap; no key means no send\n";
$n = count(mail_log()); $s = count(sms_log());
[$code, $rep] = worker('send', hours_from_now(4));
ok(count(mail_log()) === $n && count(sms_log()) === $s && array_sum(array_intersect_key(step_counts($rep, 'send'), array_flip(['sent', 'skipped', 'retried', 'failed']))) === 0, 'another pass sends nothing more: every row is settled');
$lock = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('DB_USER'), need('DB_PASSWORD'));
$lock->query("SELECT pg_advisory_lock(hashtext('consultant_tracking_worker'))");
q("INSERT INTO notification_outbox (channel, client_id, to_email, kind, record_type, record_id, subject, body) VALUES ('email', :c, 'locked@x.example.invalid', 'reminder', 'invoice', :i, 'SMOKE ob locked', 'SMOKE body')", ['c' => $client, 'i' => $inv]);
$p = $npass();
[$code, $rep, $raw] = worker('send');
ok($code === 0 && ($rep['skipped'] ?? '') === 'another pass holds the lock' && $npass() === $p && one("SELECT status FROM notification_outbox WHERE subject = 'SMOKE ob locked'") === 'queued', 'while another pass holds the advisory lock a run exits 0 saying so, sends nothing and logs no pass');
$lock->query("SELECT pg_advisory_unlock(hashtext('consultant_tracking_worker'))");
[$code, $rep, $raw] = worker('send', null, '', 'MALUMAIL_API_KEY=');
ok(step_counts($rep, 'send')['unconfigured'] >= 1 && one("SELECT status FROM notification_outbox WHERE subject = 'SMOKE ob locked'") === 'queued', 'no MALUMAIL_API_KEY: the email row stays queued (uncounted as an attempt), nothing is lost');
[$code, $rep] = worker('send');
ok(one("SELECT status FROM notification_outbox WHERE subject = 'SMOKE ob locked'") === 'sent', 'once the key is back it is sent');
ok(worker('nonsense')[0] === 2, 'an unknown step name exits 2');

echo "9. The log: kind, channel and code — never a body, an address or a link\n";
$sendLogs = q("SELECT * FROM activity_log WHERE action IN ('notification.send', 'notification.skip', 'notification.fail') ORDER BY id");
$kinds = array_count_values(array_column($sendLogs, 'action'));
ok(($kinds['notification.send'] ?? 0) >= 4 && ($kinds['notification.skip'] ?? 0) >= 8 && ($kinds['notification.fail'] ?? 0) === 1, 'notification.send, .skip and .fail rows exist (' . json_encode($kinds) . ')');
$blob = json_encode($sendLogs);
ok(!str_contains($blob, 'example.invalid') && !str_contains($blob, '/i/') && !str_contains($blob, 'SMOKE body') && !preg_match('/[a-f0-9]{48}/', $blob), 'no row of them holds an address, a link, a token or a body');
$one = q("SELECT * FROM activity_log WHERE action = 'notification.fail'")[0];
$after = json_decode((string) $one['after'], true);
ok($one['source'] === 'cron' && $one['actor_member_id'] === null && $one['entity_type'] === 'notification_outbox' && (int) $one['entity_id'] === $fid && ksort($after) === true && $after === ['attempts' => 5, 'channel' => 'email', 'code' => 'failed:502', 'kind' => 'reminder'] && (int) $one['client_id'] === $client && (int) $one['invoice_id'] === $inv, 'a row: source cron, no actor, the outbox row as entity, kind/channel/code/attempts, the client and the invoice as keys');
$wp = json_decode((string) q("SELECT after FROM activity_log WHERE action = 'worker.pass' ORDER BY id DESC LIMIT 1")[0]['after'], true);
ok(isset($wp['at'], $wp['ran'], $wp['results']) && $wp['ran'] === ['send'], 'worker.pass carries the clock, the steps that ran and their counts');
finish();

function last_text_refusal_is(int $member, string $code): bool
{
    $r = q("SELECT status, detail FROM notification_outbox WHERE member_id = :m AND channel = 'text' AND status IN ('sent', 'skipped', 'failed') ORDER BY id DESC LIMIT 1", ['m' => $member])[0] ?? null;
    return $r !== null && $r['detail'] === $code;
}
