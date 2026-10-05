<?php
/** Proof — missing time (spec "Proof", 9): for a week some submitted and others did not, the list names the others with their empty days; Nudge queues one notice per person; a second nudge the same week queues nothing. */
require __DIR__ . '/lib.php';
[$acme, $beta] = time_world();
$marco = as_member(27); $bea = as_member(28); $priya = as_member(26); $ann = as_member(29);
$w = monday(1);   // Priya submitted (and it was approved) in the partial proof; Marco, Dana did not; Bea, the owner logged nothing

echo "1. The list\n";
[$c, $d] = screen($marco, '/time/missing?week=' . $w);
$people = array_column($d['people'], null, 'member_id');
ok($c === 200 && $d['week_start'] === $w && !isset($people[26]) && isset($people[30], $people[27], $people[28]), 'the list names those who did not submit the week; not Priya (approved)');
ok($people[30]['status'] === 'none' && $people[30]['empty_weekdays'] === 5 && (float) $people[30]['hours_logged'] === 0.0 && (float) $people[30]['capacity_hours'] === 40.0, 'Dana: no week, 5 empty weekdays, 0 h of 40');
ok(!isset($people[29]), 'Ann (external) is not expected to submit');
[$c, $d] = screen($marco, '/time/missing');
ok($c === 200 && $d['week_start'] === $w, 'the default is last week');

echo "2. Nudge\n";
$since = last_activity_id();
[$c, $b] = act($marco, '/time/weeks/nudge.php', ['member' => 30, 'week' => $w]);
ok($c === 200 && $b['queued'] >= 1 && str_starts_with((string) $b['did'], 'Nudged SMOKE Dana'), 'timesheet_nudge_send: queued (' . $b['queued'] . ')');
$n = q("SELECT member_id, kind, body FROM notifications WHERE kind = 'missing_time' ORDER BY id DESC LIMIT 1")[0];
ok((int) $n['member_id'] === 30 && str_contains($n['body'], '5 empty weekdays') && str_contains($n['body'], '/time/?week=' . $w), 'the in-app notice names the empty days and links the week');
$out = q("SELECT channel, dedupe_key, status FROM notification_outbox WHERE kind = 'missing_time' AND member_id = 30 ORDER BY id");
ok(count($out) === 1 && $out[0]['channel'] === 'email' && $out[0]['dedupe_key'] === 'nudge:30:' . $w . ':m30:email' && $out[0]['status'] === 'queued', 'one outbox row, email (her defaults: text off), with the dedupe key, queued for slice 8');
ok(count(activity('timesheet.nudge', $since)) === 1, 'timesheet.nudge logged');
[$c, $b] = act($marco, '/time/weeks/nudge.php', ['member' => 30, 'week' => $w]);
ok($c === 200 && $b['queued'] === 0 && str_contains((string) $b['did'], 'already nudged') && (int) one("SELECT count(*) FROM notification_outbox WHERE kind = 'missing_time' AND member_id = 30") === 1 && count(activity('timesheet.nudge', $since)) === 1, 'a second nudge the same week queues nothing (dedupe) and logs nothing');
[$c, $d] = screen($marco, '/time/missing?week=' . $w);
ok(array_column($d['people'], null, 'member_id')[30]['nudged'] === true, 'the list marks her nudged');
[$c, $b] = act($marco, '/time/weeks/nudge.php', ['member' => 26, 'week' => $w]);
ok($c === 422 && str_contains(msg($b), 'has submitted'), 'nudging someone who submitted: 422 in words');
[$c, $b] = act($priya, '/time/weeks/nudge.php', ['member' => 30, 'week' => $w]);
ok($c === 403, 'a Consultant may not nudge: 403');
[$c, $b] = act($bea, '/time/weeks/nudge.php', ['member' => 27, 'week' => $w]);
ok($c === 200 && $b['queued'] >= 1, 'Billing nudges Marco');
[$c, $b] = act($ann, '/time/weeks/nudge.php', ['member' => 30, 'week' => $w]);
ok($c === 403, 'Finance may not nudge');
act($priya, '/settings/prefs.php', ['text_enabled' => 'yes', 'text_kinds' => ['missing_time']]);
q("UPDATE timesheets SET status = 'open' WHERE member_id = 26 AND week_start = :w AND false", ['w' => $w]);   // nothing: Priya submitted; use Dana's text preference instead
act(as_member(30), '/settings/prefs.php', ['text_enabled' => 'yes', 'text_kinds' => ['missing_time'], 'kinds' => ['missing_time']]);
$w2 = monday(4);
[$c, $b] = act($marco, '/time/weeks/nudge.php', ['member' => 30, 'week' => $w2]);
ok($c === 200 && $b['queued'] === 3 && q("SELECT channel FROM notification_outbox WHERE kind = 'missing_time' AND member_id = 30 AND dedupe_key LIKE 'nudge:30:' || :w || ':%' ORDER BY channel", ['w' => $w2]) === [['channel' => 'email'], ['channel' => 'text']], 'with texts on for missing_time: an email and a text are queued (K6 sends the text, slice 8)');
finish();
