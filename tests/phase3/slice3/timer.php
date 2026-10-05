<?php
/** Proof — the timer (spec "Proof", 2): start on Acme, start on Beta (the first stops into a 0.25 h draft), stop → a second entry; the bar; a timer on an engagement she is not staffed on → 422. */
require __DIR__ . '/lib.php';
[$acme, $beta] = time_world();
$priya = as_member(26); $marco = as_member(27);
$notHers = (int) one("SELECT id FROM engagements WHERE name = 'SMOKE Not hers'");

echo "1. Start, switch, stop\n";
$since = last_activity_id();
[$c, $b] = act($priya, '/time/timer/start.php', ['engagement' => $acme, 'note' => 'SMOKE timing']);
ok($c === 200 && (int) one('SELECT engagement_id FROM time_timers WHERE member_id = 26') === $acme && count(activity('timer.start', $since)) === 1, 'timer_start on Acme: one running timer, timer.start logged');
[$c, $d] = screen($priya, '/time/');
ok($d['timer']['engagement']['engagement_id'] === $acme && (float) $d['timer']['elapsed_hours'] >= 0, 'the week JSON carries the timer');
$body = page($priya, '/time/')['body'];
ok(str_contains($body, 'id="timer-bar-stop-btn"') && str_contains($body, 'id="timer-bar-elapsed"') && !str_contains($body, 'id="timer-bar-form"'), 'the bar shows the running timer with elapsed and Stop');
[$c, $b] = act($priya, '/time/timer/start.php', ['engagement' => $beta]);
ok($c === 200 && (int) one('SELECT engagement_id FROM time_timers WHERE member_id = 26') === $beta && str_contains((string) $b['did'], 'stopped into the week'), 'starting on Beta stops the first');
$first = q("SELECT engagement_id, hours, status, timer_started_at IS NOT NULL AS t, entry_date FROM time_entries WHERE member_id = 26 AND timer_started_at IS NOT NULL ORDER BY id DESC LIMIT 1")[0];
ok((int) $first['engagement_id'] === $acme && $first['hours'] === '0.25' && $first['status'] === 'draft' && $first['t'] && $first['entry_date'] === date('Y-m-d'), 'the first timer became a 0.25 h draft on Acme, today (rounded up to the increment, at least one step)');
ok(count(activity('timer.stop', $since)) === 1 && json_decode((string) activity('timer.stop', $since)[0]['after'], true)['hours'] === '0.25', 'timer.stop logged with the hours');
$since = last_activity_id();
[$c, $b] = act($priya, '/time/timer/stop.php', []);
ok($c === 200 && ($b['hours'] ?? '') === '0.25' && (int) one('SELECT count(*) FROM time_timers WHERE member_id = 26') === 0 && (int) one("SELECT count(*) FROM time_entries WHERE member_id = 26 AND timer_started_at IS NOT NULL") === 2, 'timer_stop: a second entry (Beta), no timer left');
[$c, $b] = act($priya, '/time/timer/stop.php', []);
ok($c === 422 && msg($b) === 'No timer is running', 'stopping again: 422 "No timer is running"');
[$c, $b] = act($priya, '/time/timer/start.php', ['engagement' => $notHers]);
ok($c === 422 && msg($b) === 'Not staffed on this engagement today (or it is not active)', 'a timer on an engagement she is not staffed on: 422 in the verb\'s words' . ($c === 422 && msg($b) === 'Not staffed on this engagement today (or it is not active)' ? '' : " (got $c " . json_encode($b) . ')'));
[$c, $b] = act($priya, '/time/timer/start.php', ['engagement' => $beta]);
ok($c === 200 && (int) one('SELECT engagement_id FROM time_timers WHERE member_id = 26') === $beta, 'running again on Beta');
[$c, $b] = act($marco, '/time/timer/stop.php', []);
ok($c === 422 && (int) one('SELECT count(*) FROM time_timers WHERE member_id = 26') === 1, 'Marco stopping his (none): 422; hers keeps running (one per person, own)');
[$c, $d] = screen($priya, '/');
ok($c === 200 && $d['timer']['engagement']['engagement_id'] === $beta && $d['my_week']['status'] === 'open', 'the home shows her timer and her week');
act($priya, '/time/timer/stop.php', []);
q("DELETE FROM time_entries WHERE member_id = 26 AND timer_started_at IS NOT NULL");
finish();
