<?php
/**
 * Proof — utilization (spec "Proof", 1): ct_utilization over the report world. Every figure below is derived from the fixture by hand.
 * Week W1 (Mon–Sun): six people hold a capability and a capacity (40 h each, the settings' default, no member_capacity rows): Ann, Bea, Dana, Marco, the owner, Priya = 240 h.
 * Logged and billable in W1: Priya 4+3 = 7, Dana 5+2 = 7, the owner 4; nobody else. 7/40 = 17.5 %, 4/40 = 10.0 %, the others 0.0 %. Total 18 h / 240 h = 7.5 %.
 * Two weeks (W1+W2): capacity 80 each; Dana adds 2 h (written off, still billable and not rejected) = 9 → 9/80 = 11.25 → 11.3 %; the totals 20 / 480 = 4.2 %.
 * Thu–Sun of W1 (4 of 7 days): capacity 40 × 4/7 = 22.86 each, nothing logged → 0.0 %.
 */
require __DIR__ . '/lib.php';
$w = report_world(); $w1 = $w['w1']; $w2 = $w['w2'];
$owner = as_member(1); $priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29);
$wk = ['from' => $w1, 'to' => day($w1, 6)];

echo "1. One week, everyone (the owner)\n";
[$c, $d] = report_json($owner, 'utilization', $wk);
ok($c === 200 && $d['report'] === 'utilization' && $d['filters']['from'] === $w1 && $d['filters']['to'] === day($w1, 6), 'the page answers with its period');
ok(array_column($d['rows'], 'member_name') === ['SMOKE Ann', 'SMOKE Bea', 'SMOKE Dana', 'SMOKE Marco', 'SMOKE Owner', 'SMOKE Priya'], 'six people, by name');
$p = row_of($d, 'member_name', 'SMOKE Priya'); $da = row_of($d, 'member_name', 'SMOKE Dana'); $o = row_of($d, 'member_name', 'SMOKE Owner'); $m = row_of($d, 'member_name', 'SMOKE Marco');
ok(same($p['capacity_hours'], 40) && same($p['logged_hours'], 7) && same($p['billable_hours'], 7) && same($p['approved_billable_hours'], 7) && same($p['utilization_pct'], 17.5), 'Priya: 40 h capacity, 7 h logged, billable and approved, 17.5 %');
ok(same($da['logged_hours'], 7) && same($da['utilization_pct'], 17.5), 'Dana: 7 h → 17.5 %');
ok(same($o['logged_hours'], 4) && same($o['utilization_pct'], 10.0), 'the owner: 4 h → 10.0 %');
ok(same($m['logged_hours'], 0) && same($m['utilization_pct'], 0.0) && $m['utilization_pct'] !== null, 'Marco logged nothing: 0.0 % (not blank)');
$t = $d['totals'][0];
ok(same($t['capacity_hours'], 240) && same($t['logged_hours'], 18) && same($t['billable_hours'], 18) && same($t['approved_billable_hours'], 18) && same($t['utilization_pct'], 7.5), 'the totals row: 240 h, 18 h, 7.5 % (18 / 240)');
ok($d['cells']['header'] === ['Person', 'Practice', 'Capacity', 'Logged', 'Billable', 'Approved billable', 'Utilization'], 'the columns: person, practice, capacity, logged, billable, approved billable, utilization');
$cells = $d['cells']['rows'][5];
ok($cells === ['SMOKE Priya', 'AI Strategy', '40 h', '7 h', '7 h', '7 h', '17.5 %'], 'a row as its cells: ' . implode(' | ', $cells));
ok($d['cells']['totals'][0] === ['Total', '', '240 h', '18 h', '18 h', '18 h', '7.5 %'], 'the totals as cells');

echo "2. Two weeks, half a week\n";
[$c, $d] = report_json($owner, 'utilization', ['from' => $w1, 'to' => day($w2, 6)]);
$da = row_of($d, 'member_name', 'SMOKE Dana');
ok(same($da['capacity_hours'], 80) && same($da['logged_hours'], 9) && same($da['billable_hours'], 9) && same($da['approved_billable_hours'], 7) && same($da['utilization_pct'], 11.3), 'two weeks: Dana 9 h of 80 h = 11.3 %, of which 7 h are "approved" (the written-off 2 h count as worked, not as approved)');
ok(same($d['totals'][0]['capacity_hours'], 480) && same($d['totals'][0]['logged_hours'], 20) && same($d['totals'][0]['utilization_pct'], 4.2), 'the totals: 480 h, 20 h, 4.2 %');
[$c, $d] = report_json($owner, 'utilization', ['from' => day($w1, 3), 'to' => day($w1, 6)]);
ok(same($d['rows'][0]['capacity_hours'], 22.86) && same($d['rows'][0]['logged_hours'], 0) && same($d['totals'][0]['capacity_hours'], 137.16), 'Thursday to Sunday: 40 × 4/7 = 22.86 h each (137.16 h in all), nothing logged');

echo "3. Filters and grouping\n";
[$c, $d] = report_json($owner, 'utilization', $wk + ['member' => 26]);
ok(count($d['rows']) === 1 && $d['rows'][0]['member_name'] === 'SMOKE Priya' && same($d['totals'][0]['logged_hours'], 7), 'member=Priya: her row alone, the totals the same');
$dept = (int) one("SELECT id FROM departments WHERE name = 'AI Strategy'");
[$c, $d] = report_json($owner, 'utilization', $wk + ['department' => $dept]);
ok(array_column($d['rows'], 'member_name') === ['SMOKE Marco', 'SMOKE Priya'] && same($d['totals'][0]['capacity_hours'], 80) && same($d['totals'][0]['utilization_pct'], 8.8), 'practice AI Strategy: Marco and Priya — 80 h, 7 h billable, 8.8 % (8.75)');
[$c, $d] = report_json($owner, 'utilization', $wk + ['by' => 'practice']);
$subs = array_values(array_filter($d['rows'], static fn ($r) => !empty($r['is_subtotal'])));
$by = array_column($subs, null, 'department_name');
ok(count($subs) === 4 && count($d['rows']) === 10, 'grouped by practice: four subtotals among ten rows (six people)');
ok(same($by['AI Strategy']['capacity_hours'], 80) && same($by['AI Strategy']['billable_hours'], 7) && same($by['AI Strategy']['utilization_pct'], 8.8) && same($by['Accounting']['capacity_hours'], 80) && same($by['Accounting']['utilization_pct'], 0.0)
   && same($by['ML Engineering']['utilization_pct'], 17.5) && same($by['IT']['billable_hours'], 4) && same($by['IT']['utilization_pct'], 10.0), 'subtotals: AI Strategy 8.8 % (7/80), Accounting 0.0 % (0/80), ML Engineering 17.5 %, IT 10.0 %');
$last = null; $okOrder = true;
foreach ($d['rows'] as $i => $r) { if (!empty($r['is_subtotal'])) { $okOrder = $okOrder && ($d['rows'][$i - 1]['department_name'] === $r['department_name']); } }
ok($okOrder && same($d['totals'][0]['utilization_pct'], 7.5), 'each subtotal follows its practice\'s people; the grand total is unchanged (7.5 %)');
ok(in_array(['Subtotal', 'AI Strategy', '80 h', '7 h', '7 h', '7 h', '8.8 %'], $d['cells']['rows'], true), 'a subtotal as cells');
[$c, $d] = report_json($owner, 'utilization', $wk + ['by' => 'nonsense', 'member' => 'abc']);
ok($c === 200 && count($d['rows']) === 6 && $d['filters']['by'] === '', 'an unknown grouping and a non-numeric person are ignored, not trusted');

echo "4. The page\n";
$r = page($owner, '/reports/utilization?' . http_build_query($wk));
$b = $r['body'];
ok($r['code'] === 200 && str_contains($b, 'id="report-filters"') && str_contains($b, 'id="report-table"') && str_contains($b, 'id="report-totals"') && str_contains($b, 'class="table-responsive"'), 'filters, table in its scrolling wrapper, totals row');
ok(str_contains($b, 'name="from"') && str_contains($b, 'name="to"') && str_contains($b, 'name="member"') && str_contains($b, 'name="department"') && str_contains($b, 'name="by"') && str_contains($b, 'id="report-preset-last_month"'), 'the filter row: period, presets, person, practice, grouping');
ok(substr_count($b, 'bg-soft-danger') >= 6 && !str_contains($b, 'bg-soft-success'), 'utilization under 50 % is danger on every row (17.5, 10.0, 0.0, 7.5)');
ok(str_contains($b, 'id="report-csv-btn"') && str_contains($b, '/reports/csv?report=utilization'), 'the CSV button for a holder of exports.download');
$logs = array_values(array_filter(activity('screen.view', 0), static fn ($l) => $l['screen'] === 'report'));
$after = json_decode((string) end($logs)['after'], true);
ok($after['report'] === 'utilization' && $after['from'] === $w1, 'screen.view logged with the report and the period in after');
$last = last_activity_id();
page($owner, '/reports/utilization?' . http_build_query($wk));
$rows = array_filter(activity('screen.view', $last), static fn ($l) => $l['screen'] === 'report');
ok(count($rows) === 1, 'one screen.view per page load');
$none = page($owner, '/reports/utilization?' . http_build_query(['from' => '2026-01-01', 'to' => '2026-01-02', 'member' => 30]));
ok($none['code'] === 200 && str_contains($none['body'], 'id="report-table"'), 'a period before anyone logged, one person: her row at capacity, 0 logged');
finish();
