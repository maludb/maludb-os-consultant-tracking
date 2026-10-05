<?php
/**
 * Proof — Monday's nudges (spec "Proof", 2): on the settings' nudge day (from 06:00) the worker queues ONE missing_time notice per person who has not submitted last week — not Dana, who did; texts to those who chose them; a Tuesday, or the
 * small hours, none; the dedupe key holds across passes, the manager's button and the Clerk's tool (same key, never a second nudge). The words are the button's; the sender then delivers them.
 */
require __DIR__ . '/lib.php';
$w = report_world(); $acme = $w['acme'];
$dana = as_member(30); $marco = as_member(27); $owner = as_member(1);
$lw = monday(1); $mon = monday(0);
q("INSERT INTO notification_prefs (member_id, email_enabled, text_enabled, kinds, text_kinds) VALUES (26, true, true, '{missing_time}', '{missing_time}') ON CONFLICT (member_id) DO UPDATE SET email_enabled = true, text_enabled = true, kinds = '{missing_time}', text_kinds = '{missing_time}'");
q("DELETE FROM notification_prefs WHERE member_id IN (1, 27, 30)");
q("INSERT INTO notification_prefs (member_id, email_enabled, text_enabled) VALUES (28, false, false) ON CONFLICT (member_id) DO UPDATE SET email_enabled = false, text_enabled = false");      // Bea turned every channel off: the bell only
[$c, $b] = log_time($dana, $acme, day($lw, 0), '2', ['note' => 'SMOKE nudge Dana last week']);
act($dana, '/time/weeks/submit.php', ['week' => $lw]);
ok($c === 200 && one('SELECT status FROM timesheets WHERE member_id = 30 AND week_start = :w', ['w' => $lw]) === 'submitted', 'Dana submitted last week (' . $lw . ')');
[$c, $d] = screen($owner, '/time/missing?week=' . $lw);
$names = array_column($d['people'], 'member_id');
sort($names);
ok($c === 200 && $names === [1, 26, 27, 28], 'the list of those who did not: the owner, Priya, Marco, Bea — not Dana (submitted), not Ann (external), not the Clerk (an agent)');
drain_outbox(); reset_fakes();
$since = last_activity_id();
$before = (int) one("SELECT count(*) FROM notifications WHERE kind = 'missing_time'");

echo "1. Not on Tuesday, not in the small hours of Monday\n";
[$code, $rep] = worker('nudge', day($mon, 1) . 'T07:00:00Z');
ok($code === 0 && step_counts($rep, 'nudge') === ['nudged' => 0], 'Tuesday 07:00: nobody is nudged');
[$code, $rep] = worker('nudge', $mon . 'T05:30:00Z');
ok(step_counts($rep, 'nudge') === ['nudged' => 0] && (int) one("SELECT count(*) FROM notifications WHERE kind = 'missing_time'") === $before, 'Monday 05:30 (before six): nobody is nudged');

echo "2. Monday morning\n";
[$code, $rep] = worker('nudge', $mon . 'T07:00:00Z');
ok($code === 0 && step_counts($rep, 'nudge') === ['nudged' => 4], 'Monday 07:00: four people nudged');
$n = q("SELECT member_id, title, body, record_type, record_id FROM notifications WHERE kind = 'missing_time' AND id > (SELECT COALESCE(max(id), 0) FROM notifications WHERE kind <> 'missing_time' AND false) ORDER BY id DESC LIMIT 4");
$byMember = array_column($n, null, 'member_id');
ksort($byMember);
ok(array_keys($byMember) === [1, 26, 27, 28] && !isset($byMember[30]), 'the in-app notices: the owner, Priya, Marco, Bea — none for Dana');
ok(str_starts_with($byMember[26]['title'], 'Please submit your week of') && str_contains($byMember[26]['body'], '5 empty weekdays, 0 h logged') && str_contains($byMember[26]['body'], '/time/?week=' . $lw), 'the words are the button\'s: "Please submit your week of …", the empty days, the hours, a link to that week');
$out = q("SELECT member_id, channel, dedupe_key, status FROM notification_outbox WHERE kind = 'missing_time' AND dedupe_key LIKE 'nudge:%' ORDER BY member_id, channel");
ok(count($out) === 4 && array_count_values(array_column($out, 'channel')) === ['email' => 3, 'text' => 1] && $out[2]['member_id'] === 26 && $out[2]['channel'] === 'text', 'an email each for the owner, Priya and Marco, and one text — Priya chose texts; Bea, who turned every channel off, has the bell only');
ok($out[0]['dedupe_key'] === 'nudge:1:' . $lw . ':m1:email' && array_unique(array_column($out, 'status')) === ['queued'], 'the dedupe key nudge:<member>:<week> per channel; queued for the sender');
$log = activity('timesheet.nudge', $since);
$after = json_decode((string) $log[0]['after'], true);
ok(count($log) === 4 && $log[0]['source'] === 'cron' && $log[0]['actor_member_id'] === null && $after['week_start'] === $lw && $after['via'] === 'worker' && $after['nudged_by'] === null && $after['empty_weekdays'] === 5, 'timesheet.nudge logged four times: cron, no actor, the week, the empty days, via worker');
ok(!str_contains(json_encode($log), '@') && !str_contains(json_encode($log), 'SMOKE nudge'), 'no address and no note in those rows');

echo "3. The dedupe holds\n";
[$code, $rep] = worker('nudge', $mon . 'T09:00:00Z');
ok(step_counts($rep, 'nudge') === ['nudged' => 0] && (int) one("SELECT count(*) FROM notification_outbox WHERE kind = 'missing_time' AND dedupe_key LIKE 'nudge:%'") === 4 && (int) one("SELECT count(*) FROM notifications WHERE kind = 'missing_time' AND member_id = 28") === 1, 'a second pass the same Monday: nobody again, no new row — and Bea, who has no outbox row to carry a dedupe key, is not told a second time (the log of the first nudge is the record)');
[$c, $b] = act($marco, '/time/weeks/nudge.php', ['member' => 26, 'week' => $lw]);
ok($c === 200 && $b['queued'] === 0 && str_contains((string) $b['did'], 'already nudged'), 'a manager\'s button for Priya the same week: "already nudged" — the worker\'s notice and the button share one key');
[$code, $rep] = worker('nudge', day($mon, 2) . 'T08:00:00Z');
ok(step_counts($rep, 'nudge') === ['nudged' => 0], 'Wednesday: nothing (the nudge day is Monday)');

echo "4. The nudge day is the settings'\n";
q('UPDATE ct_settings SET missing_time_nudge_dow = 3 WHERE id = 1');
$next = day($mon, 7);
[$code, $rep] = worker('nudge', $next . 'T07:00:00Z');
ok(step_counts($rep, 'nudge') === ['nudged' => 0], 'the day moved to Wednesday: next Monday nobody');
[$code, $rep] = worker('nudge', day($next, 2) . 'T07:00:00Z');
$nw = step_counts($rep, 'nudge')['nudged'];
ok($nw >= 3 && (int) one("SELECT count(*) FROM notification_outbox WHERE kind = 'missing_time' AND dedupe_key LIKE 'nudge:%:' || :w || ':%'", ['w' => $mon]) >= 3, "on the Wednesday of the week after it nudges for last week ($mon): $nw people");
q('UPDATE ct_settings SET missing_time_nudge_dow = 1 WHERE id = 1');

echo "5. Then the sender delivers them\n";
set_sms(['mode' => 'ok']);
q("UPDATE notification_outbox SET status = 'skipped', detail = 'proof-held' WHERE kind = 'missing_time' AND dedupe_key NOT LIKE 'nudge:%:' || :w || ':%'", ['w' => $lw]);
[$code, $rep] = worker('send');
$sms = sms_log(); $mail = mail_log(); $tos = array_column($mail, 'to'); sort($tos);
ok(count($mail) === 3 && $tos === ['marco@example.invalid', 'owner@example.invalid', 'priya@example.invalid'] && str_starts_with($mail[0]['subject'], 'Please submit your week of'), 'three emails to the three addresses (not Bea\'s: she chose none), the title as the subject');
ok(count($sms) === 1 && $sms[0]['member_id'] === 26 && str_contains($sms[0]['text'], 'Please submit your week of') && !empty($sms[0]['accepted']), 'one text, to member 26 through the kernel');
finish();
