<?php
/**
 * Proof — missing time (spec "Proof", 1). ct_missing_time(week), by hand. People expected to log: active humans with a capability, not external, capacity > 0 — Bea, Dana, Marco, the owner, Priya (Ann is Finance, external).
 * W1: Priya, Dana and the owner submitted (approved) → Bea and Marco are missing: no week, 0 h, 5 empty weekdays, 40 h capacity. W2: Dana's week was approved → Bea, Marco, Priya, the owner. W4: Priya's → Bea, Dana, Marco, the owner.
 * Last week (the default, W-1): nobody has a week → all five.
 */
require __DIR__ . '/lib.php';
$w = report_world();
$owner = as_member(1); $priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29);
$names = static fn (array $d): array => array_column($d['rows'], 'member_name');

echo "1. The weeks\n";
[$c, $d] = report_json($owner, 'missing-time', ['week' => $w['w1']]);
ok($c === 200 && $names($d) === ['SMOKE Bea', 'SMOKE Marco'] && $d['week_start'] === $w['w1'], 'W1: Bea and Marco');
$r = $d['rows'][0];
ok($r['status'] === 'none' && same($r['hours_logged'], 0) && $r['empty_weekdays'] === 5 && same($r['capacity_hours'], 40) && $r['timesheet_id'] === null, 'no week, 0 h, 5 empty weekdays, 40 h capacity');
ok($d['cells']['header'] === ['Person', 'Week', 'Logged', 'Empty weekdays', 'Capacity'] && $d['cells']['rows'][0] === ['SMOKE Bea', 'No week', '0 h', '5', '40 h'] && $d['totals'] === [], 'cells: SMOKE Bea | No week | 0 h | 5 | 40 h; no totals');
[$c, $d] = report_json($owner, 'missing-time', ['week' => $w['w2']]);
ok($names($d) === ['SMOKE Bea', 'SMOKE Marco', 'SMOKE Owner', 'SMOKE Priya'], 'W2 (Dana\'s approved): Bea, Marco, the owner, Priya');
[$c, $d] = report_json($owner, 'missing-time', ['week' => $w['w4']]);
ok($names($d) === ['SMOKE Bea', 'SMOKE Dana', 'SMOKE Marco', 'SMOKE Owner'], 'this week (Priya\'s approved): Bea, Dana, Marco, the owner');
[$c, $d] = report_json($owner, 'missing-time');
ok($c === 200 && count($d['rows']) === 5 && $d['week_start'] === monday(1), 'no week asked: last week (' . monday(1) . '), all five');
[$c, $d] = report_json($owner, 'missing-time', ['week' => day($w['w1'], 3)]);
ok($d['week_start'] === $w['w1'] && count($d['rows']) === 2, 'a Thursday asked: its Monday');
// a week saved but unsubmitted shows its status and hours
$jar = as_member(30);
[$c, $b] = log_time($jar, $w['acme'], day(monday(1), 1), '2', ['note' => 'SMOKE rpt missing']);
[$c, $d] = report_json($owner, 'missing-time');
$da = row_of($d, 'member_name', 'SMOKE Dana');
ok($da !== null && $da['status'] === 'open' && same($da['hours_logged'], 2) && $da['empty_weekdays'] === 4, 'Dana with a draft week last week: open, 2 h, 4 empty weekdays');
ok(row_of($d, 'member_name', 'SMOKE Bea')['status'] === 'none', 'and Bea still has none');
q("DELETE FROM time_entries WHERE note = 'SMOKE rpt missing'");

echo "2. Who sees, and the page\n";
[$c, $d] = report_json($marco, 'missing-time', ['week' => $w['w1']]);
ok($c === 200 && $names($d) === ['SMOKE Bea', 'SMOKE Marco'], 'Marco (time.approve): the same list');
[$c, $d] = report_json($ann, 'missing-time', ['week' => $w['w1']]);
ok($c === 200 && count($d['rows']) === 2, 'Finance: the same');
ok(req('GET', '/reports/missing-time', ['jar' => $priya, 'headers' => JSONH])['code'] === 403, 'a Consultant: 403');
$b = page($owner, '/reports/missing-time?week=' . $w['w1'])['body'];
ok(str_contains($b, 'id="report-table"') && str_contains($b, 'name="week"') && !str_contains($b, 'name="from"') && str_contains($b, '/time/missing?week=' . $w['w1']), 'the page: a week picker, the table, a Nudge link into slice 3\'s /time/missing');
finish();
