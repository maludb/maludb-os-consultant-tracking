<?php
/** Proof — partial approval and reopen (spec "Proof", 5–6): a week with entries on Marco's engagement and on another manager's; Marco's approve leaves the other's submitted and says so; Bea finishes it; reopen rules. */
require __DIR__ . '/lib.php';
[$acme, $beta] = time_world();
$priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $dana = as_member(30);
$w = monday(1);
// an engagement managed by Bea (Accounting), with Priya staffed
[$c, $b] = act($bea, '/engagements/save.php', ['client' => (int) one("SELECT id FROM clients WHERE name = 'SMOKE Beta'"), 'name' => 'SMOKE Beas eng', 'kind' => 'time_and_materials', 'start_date' => '2026-01-05', 'manager' => 28, 'start_now' => 'yes']);
$beas = (int) $b['record_id'];
act($bea, '/engagements/staffing/save.php', ['engagement' => $beas, 'member' => 26, 'role' => role('consultant'), 'valid_from' => '2026-01-05']);
$beasNum = (string) one('SELECT number FROM engagements WHERE id = :e', ['e' => $beas]);

echo "1. Partial approval\n";
[$c, $b] = log_time($priya, $acme, day($w, 0), '6');
[$c, $b] = log_time($priya, $beas, day($w, 1), '3');
[$c, $b] = log_time($priya, $beas, day($w, 2), '2');
$ts = week_id(26, $w);
[$c, $b] = act($priya, '/time/weeks/submit.php', ['week' => $w]);
ok($c === 200 && q("SELECT count(DISTINCT member_id) FROM notifications WHERE kind = 'timesheet_submitted' AND record_id = :t", ['t' => $ts])[0]['count'] === 2, 'submitted: both managers (Marco and Bea) told');
[$c, $d] = screen($marco, '/time/approvals');
$card = array_values(array_filter($d['weeks'], fn ($x) => $x['timesheet_id'] === $ts))[0] ?? null;
ok($card !== null && $card['mine_to_approve'] === 1 && $card['submitted_entries'] === 3 && !str_contains((string) $card['engagements_in'], $beasNum), 'Marco\'s approvals card: 1 of 3 entries his to approve; it names only the engagement he sees (Bea\'s is not his to see)' . ($card === null ? ' (no card)' : ' (' . json_encode([$card['mine_to_approve'], $card['submitted_entries'], $card['engagements_in']]) . ')'));
[$c, $b] = act($marco, '/time/weeks/approve.php', ['timesheet' => $ts]);
ok($c === 200 && $b['approved'] === 1 && $b['left_submitted'] === 2 && $b['week_status'] === 'submitted' && str_contains((string) $b['did'], '2 entries on other engagements await their manager'), 'Marco approves his one; two on Bea\'s await their manager; the week stays submitted');
ok((int) one("SELECT count(*) FROM notifications WHERE kind = 'timesheet_approved' AND record_id = :t", ['t' => $ts]) === 0, 'Priya is not yet told (the week is not approved)');
$body = page($marco, '/time/weeks/' . $ts)['body'];
ok(str_contains($body, 'id="week-others"') && str_contains($body, '2 entries on engagements you do not manage await their manager'), 'his page says so');
[$c, $b] = act($marco, '/time/weeks/approve.php', ['timesheet' => $ts]);
ok($c === 422 && msg($b) === 'Nothing in this week is on an engagement you manage.', 'approving again with nothing of his left: 422 in words');
[$c, $b] = act($dana, '/time/weeks/reopen.php', ['timesheet' => $ts]);
ok($c === 403 || $c === 404, 'Dana may not reopen Priya\'s week');
[$c, $b] = act($priya, '/time/weeks/reopen.php', ['timesheet' => $ts]);
ok($c === 422 && msg($b) === 'Part of this week is approved; ask the manager to reject what must change', 'a week with an approved entry cannot be reopened (the sentence)');
[$c, $b] = act($bea, '/time/weeks/approve.php', ['timesheet' => $ts]);
ok($c === 200 && $b['approved'] === 2 && $b['week_status'] === 'approved', 'Bea (Billing manages every engagement) finishes it: the week turns approved');
ok((int) one("SELECT count(*) FROM notifications WHERE kind = 'timesheet_approved' AND record_id = :t", ['t' => $ts]) === 1, 'now Priya is told once');
ok(q('SELECT engagement_id, bill_rate FROM time_entries WHERE timesheet_id = :t ORDER BY entry_date', ['t' => $ts]) === [['engagement_id' => $acme, 'bill_rate' => '180.00'], ['engagement_id' => $beas, 'bill_rate' => '180.00'], ['engagement_id' => $beas, 'bill_rate' => '180.00']], 'every entry carries its snapshot (180 from the client\'s default card on both)');

echo "2. Reopen\n";
$w2 = monday(0);
[$c, $b] = log_time($dana, $acme, day($w2, 0), '4');
$ts2 = week_id(30, $w2);
[$c, $b] = act($dana, '/time/weeks/submit.php', ['week' => $w2]);
ok($c === 200, 'Dana submits this week');
$since = last_activity_id();
[$c, $b] = act($dana, '/time/weeks/reopen.php', ['timesheet' => $ts2]);
ok($c === 200 && q('SELECT status, submitted_at FROM timesheets WHERE id = :t', ['t' => $ts2])[0] === ['status' => 'open', 'submitted_at' => null] && (string) one('SELECT status FROM time_entries WHERE timesheet_id = :t', ['t' => $ts2]) === 'draft' && count(activity('timesheet.reopen', $since)) === 1, 'she reopens her own submitted week: open, the entry a draft again, logged');
[$c, $b] = act($dana, '/time/weeks/reopen.php', ['timesheet' => $ts2]);
ok($c === 422 && msg($b) === 'Only a submitted week is reopened (this one is open)', 'reopening an open week: 422 in words');
[$c, $b] = act($dana, '/time/weeks/submit.php', ['week' => $w2]);
[$c, $b] = act($marco, '/time/weeks/reopen.php', ['timesheet' => $ts2]);
ok($c === 200 && (string) one('SELECT status FROM timesheets WHERE id = :t', ['t' => $ts2]) === 'open', 'a manager reopens it for her');
[$c, $b] = act($marco, '/time/weeks/submit.php', ['week' => $w2, 'member' => 30]);
ok($c === 200 && (string) one('SELECT status FROM timesheets WHERE id = :t', ['t' => $ts2]) === 'submitted' && json_decode((string) (($sl = activity('timesheet.submit', 0)) ? end($sl)['after'] : '{}'), true)['submitted_by'] === 27, 'and submits it for her (the log says submitted_by Marco)');
[$c, $b] = act($dana, '/time/weeks/submit.php', ['week' => monday(5)]);
ok($c === 200 && (string) one('SELECT status FROM timesheets WHERE member_id = 30 AND week_start = :w', ['w' => monday(5)]) === 'submitted' && (string) one('SELECT total_hours FROM timesheets WHERE member_id = 30 AND week_start = :w', ['w' => monday(5)]) === '0.00', 'an empty week (a week off) may be submitted: the row is made and submitted with 0 hours');
finish();
