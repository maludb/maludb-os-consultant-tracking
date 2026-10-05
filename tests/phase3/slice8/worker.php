<?php
/**
 * Proof — the worker itself (spec "Proof", 9 and the header): each step has its rhythm (every minute; hourly; daily from 06:00 firm time) kept by the worker.pass rows, so a pass the next minute is a no-op for the hourly and daily steps; `--steps=` runs exactly
 * the named ones; a step that throws is its own error and the others still run (exit 1); the clock is honoured only outside production; housekeeping tidies nonces, old sessions and the BODIES of old sent mail and leaves the checkpoints and the counts alone;
 * `worker.pass` once per pass with the counts. Run last: it runs every step at a clock years ahead.
 */
require __DIR__ . '/lib.php';
$w = report_world();
$npass = fn (): int => (int) one("SELECT count(*) FROM activity_log WHERE action = 'worker.pass'");
$day = '2030-03-04';

echo "1. Housekeeping leaves the checkpoints and the counts alone\n";
$sync = q('SELECT * FROM directory_sync_state WHERE id = 1')[0];
$ingest = q('SELECT * FROM activity_ingest_state WHERE id = 1')[0] ?? null;
$seq = q('SELECT kind, next_value FROM document_sequences ORDER BY kind');
q("INSERT INTO sso_nonces (nonce, member_id, expires_at) VALUES ('s8-old', 1, now() - interval '3 days'), ('s8-fresh', 1, now() + interval '1 minute')");
q("INSERT INTO member_sessions (session_hash, member_id, ended_at, ended_by) VALUES ('s8-ended-old', 26, now() - interval '100 days', 'member'), ('s8-ended-new', 26, now() - interval '5 days', 'member'), ('s8-live', 26, NULL, NULL)");
q("INSERT INTO notification_outbox (channel, member_id, kind, subject, body, body_html, status, provider_ref, sent_at) VALUES ('email', 26, 'expense_approved', 'SMOKE s8 old mail', 'SMOKE old body', '<p>old</p>', 'sent', '<mm-old@x>', now() - interval '200 days'), ('email', 26, 'expense_approved', 'SMOKE s8 recent mail', 'SMOKE recent body', NULL, 'sent', '<mm-new@x>', now() - interval '20 days')");
$p0 = $npass();
[$code, $rep] = worker('housekeeping', hours_from_now(0));
$c = step_counts($rep, 'housekeeping');
ok($code === 0 && $c['sso_nonces'] === 1 && $c['member_sessions'] === 1 && $c['outbox_bodies'] === 1, 'housekeeping: one used nonce, one session ended over 90 days ago, one old mail body tidied');
ok((int) one("SELECT count(*) FROM sso_nonces WHERE nonce IN ('s8-old')") === 0 && (int) one("SELECT count(*) FROM sso_nonces WHERE nonce = 's8-fresh'") === 1 && (int) one("SELECT count(*) FROM member_sessions WHERE session_hash IN ('s8-ended-new', 's8-live')") === 2 && (int) one("SELECT count(*) FROM member_sessions WHERE session_hash = 's8-ended-old'") === 0, 'the old nonce and the 100-day session are gone; the fresh nonce, the recent and the live sessions stay');
$old = q("SELECT * FROM notification_outbox WHERE subject = 'SMOKE s8 old mail'")[0]; $new = q("SELECT * FROM notification_outbox WHERE subject = 'SMOKE s8 recent mail'")[0];
ok($old['body'] === '' && $old['body_html'] === null && $old['status'] === 'sent' && $old['provider_ref'] === '<mm-old@x>' && $old['subject'] === 'SMOKE s8 old mail' && $new['body'] === 'SMOKE recent body', 'the old sent mail: body blanked, the ROW kept (status, provider reference, subject); the recent one whole');
ok(q('SELECT * FROM directory_sync_state WHERE id = 1')[0] === $sync && (q('SELECT * FROM activity_ingest_state WHERE id = 1')[0] ?? null) === $ingest && q('SELECT kind, next_value FROM document_sequences ORDER BY kind') === $seq, 'the directory cursor, the ingest checkpoint and every document sequence are exactly as they were');
ok($npass() === $p0 + 1, 'one worker.pass for the run');

echo "2. Rhythm: minute, hourly, daily from 06:00\n";
$ran = fn (array $r): array => $r['ran'];
[$code, $rep] = worker('', $day . 'T05:00:00Z');
$daily = ['cadence', 'schedules', 'budgets', 'reminders', 'claims', 'links', 'housekeeping'];
ok($code === 0 && $ran($rep) === ['send', 'nudge', 'statement', 'receipts', 'reimbursements', 'dispatch'] && array_keys($rep['skipped']) === $daily && array_values(array_unique($rep['skipped'])) === ['not due'], '05:00 (before six): the minute steps and the first hourly pass run; the seven daily steps are "not due"');
[$code, $rep] = worker('', $day . 'T05:30:00Z');
ok($ran($rep) === ['send', 'nudge', 'dispatch'] && count($rep['skipped']) === 10, '05:30: only the minute steps (send, nudge, dispatch) — the hourly ones ran half an hour ago');
[$code, $rep] = worker('', $day . 'T06:00:00Z');
ok($ran($rep) === WORKER_STEPS_PROOF() && $rep['skipped'] === [], '06:00: every step runs — the hourly ones an hour on, the daily ones for the first time today');
[$code, $rep] = worker('', $day . 'T06:30:00Z');
ok($ran($rep) === ['send', 'nudge', 'dispatch'], '06:30: back to the minute steps');
[$code, $rep] = worker('', '2030-03-05T05:59:00Z');
ok(!in_array('cadence', $ran($rep), true) && in_array('statement', $ran($rep), true), 'the next day at 05:59: the daily steps still wait for six');
[$code, $rep] = worker('', '2030-03-05T06:00:00Z');
ok(in_array('cadence', $ran($rep), true) && in_array('housekeeping', $ran($rep), true), 'the next day at 06:00: the daily steps run again');
$last = json_decode((string) q("SELECT after FROM activity_log WHERE action = 'worker.pass' ORDER BY id DESC LIMIT 1")[0]['after'], true);
ok($last['at'] === '2030-03-05T06:00:00+00:00' && is_array($last['results']['send']) && array_keys($last['results']['send']) !== [] && is_numeric($last['seconds']) && $last['errors'] === [], 'worker.pass carries the clock the pass used, every step\'s counts, the seconds and no errors');

echo "3. Steps named run whether due or not; the clock is not honoured in production\n";
[$code, $rep] = worker('housekeeping,budgets', '2030-03-05T06:10:00Z');
ok($ran($rep) === ['budgets', 'housekeeping'] && $rep['skipped'] === [], '--steps=housekeeping,budgets: exactly those, in the worker\'s order, though they ran minutes ago');
[$code, $rep] = worker('nudge', '2030-03-05T06:10:00Z', '', 'APP_ENV=prod');
ok($code === 0 && !str_starts_with($rep['at'], '2030'), 'APP_ENV=prod: CT_WORKER_NOW is ignored — the pass runs on the real clock (' . substr($rep['at'], 0, 10) . ')');
[$code] = worker('', null, '--limit=5 --bogus');
ok($code === 2, 'an unknown option exits 2 with the usage');

echo "4. A step that throws is its own error; the others still run\n";
$sql = fn (string $s) => shell_exec('sudo -n -u postgres psql -q -d ' . escapeshellarg(need('DB_NAME')) . ' -c ' . escapeshellarg($s) . ' 2>&1');
$sql('ALTER TABLE budget_alerts RENAME TO budget_alerts_proof');
[$code, $rep] = worker('budgets,send,housekeeping', '2030-03-06T07:00:00Z');
$sql('ALTER TABLE budget_alerts_proof RENAME TO budget_alerts');
ok($code === 1 && isset($rep['errors']['budgets']) && $ran($rep) === ['send', 'housekeeping'] && isset($rep['results']['send']), 'budgets threw (its table was away): exit 1, the error in the report, send and housekeeping still ran');
$row = json_decode((string) q("SELECT after FROM activity_log WHERE action = 'worker.pass' ORDER BY id DESC LIMIT 1")[0]['after'], true);
ok(isset($row['errors']['budgets']) && !in_array('budgets', $row['ran'], true), 'worker.pass recorded the error and not "ran" for the failed step — so its rhythm will try it again at the next pass');
[$code, $rep] = worker('budgets', '2030-03-06T07:05:00Z');
ok($code === 0 && step_counts($rep, 'budgets')['checked'] >= 1, 'the table back: the next pass runs it');
finish();

function WORKER_STEPS_PROOF(): array { return ['send', 'nudge', 'cadence', 'statement', 'schedules', 'budgets', 'reminders', 'receipts', 'reimbursements', 'claims', 'links', 'dispatch', 'housekeeping']; }
