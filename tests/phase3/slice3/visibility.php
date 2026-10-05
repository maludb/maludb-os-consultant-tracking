<?php
/** Proof — who sees (spec "Proof", 8): Priya her weeks without rates; Marco every entry on his engagements with rates and cost; Dana hers; Ann all with cost; Bea all; another's week → 404; the team page and missing time → 403 for Priya in words. */
require __DIR__ . '/lib.php';
require_once dirname(__DIR__, 3) . '/app/db.php';
require_once dirname(__DIR__, 3) . '/app/features/time/queries.php';
[$acme, $beta] = time_world();
$priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29); $dana = as_member(30);
$w = monday(2); $tsP = week_id(26, $w); $tsD = week_id(30, $w);

echo "1. The weeks\n";
ok(page($priya, '/time/weeks/' . $tsD)['code'] === 404 && page($dana, '/time/weeks/' . $tsP)['code'] === 404, 'another Consultant\'s week: 404 both ways');
ok(page($priya, '/time/?week=' . $w . '&member=30')['code'] === 404, '?member= for a Consultant: 404');
[$c, $d] = screen($marco, '/time/?week=' . $w . '&member=30');
ok($c === 200 && $d['member_id'] === 30 && $d['status'] === 'approved' && $d['may']['approve'] === true, 'Marco opens Dana\'s week with ?member= (a manager of her engagement)');
$body = page($marco, '/time/?week=' . $w . '&member=30')['body'];
ok(str_contains($body, 'SMOKE Dana') && str_contains($body, 'id="week-review-link"'), 'titled with her name, with "Review as a manager"');
[$c, $d] = screen($ann, '/time/weeks/' . $tsD);
ok($c === 200 && $d['days'][0]['entries'][0]['cost_rate'] === '55.00' && $d['days'][0]['entries'][0]['bill_rate'] === '125.00', 'Ann (Finance) sees every week with rate and cost');
[$c, $d] = screen($bea, '/time/weeks/' . $tsD);
ok($c === 200 && $d['days'][0]['entries'][0]['bill_rate'] === '125.00' && $d['may']['approve'] === false, 'Bea sees it with the rate; it is approved so no Approve');
pdo()->exec("SELECT set_config('app.member_id', '26', false)");
ok((int) one('SELECT count(*) FROM mcp_time_entries WHERE member_id <> 26') === 0 && (int) one('SELECT count(*) FROM mcp_timesheets WHERE member_id <> 26') === 0, 'the views themselves answer Priya nobody else\'s entries or weeks');
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
ok((int) one('SELECT count(*) FROM mcp_time_entries WHERE member_id = 30') > 0 && one('SELECT bill_rate FROM mcp_time_entries WHERE member_id = 30 ORDER BY entry_id LIMIT 1') !== null && one('SELECT cost_rate FROM mcp_time_entries WHERE member_id = 30 ORDER BY entry_id LIMIT 1') !== null, 'and Marco Dana\'s entries on his engagement with rate and cost');
ok(page($priya, '/time/?week=' . $w)['code'] === 200 && page($priya, '/time/weeks/' . $tsP)['code'] === 200, 'Priya opens her own week by date and by id');
[$c, $d] = screen($priya, '/time/weeks/' . $tsP);
ok($d['may']['approve'] === false && $d['may']['reject'] === false && $d['days'][0]['entries'][0]['bill_rate'] === null && $d['days'][0]['entries'][0]['will_apply'] === null, 'her own week view: no Approve, no rate, no "will apply"');

echo "2. The team page and missing time\n";
foreach (['/time/team' => 'approve time', '/time/approvals' => 'approve time'] as $path => $words) {
    $r = page($priya, $path);
    ok($r['code'] === 403 && str_contains(html_entity_decode($r['body'], ENT_QUOTES), 'You may not ' . $words), "$path → 403 for Priya in words");
}
ok(page($priya, '/time/missing')['code'] === 403 && page($priya, '/time/written-off')['code'] === 403, 'missing time and written off: 403 for her');
[$c, $d] = screen($marco, '/time/team?week=' . $w);
$people = array_column($d['people'], null, 'member_id');
ok($c === 200 && isset($people[26], $people[30]) && (float) $people[30]['days'][day($w, 0)] === 8.0 && $people[30]['status'] === 'approved' && (float) $people[26]['total'] === 9.5, 'the team page: Priya and Dana with their days, totals and week status');
$body = page($marco, '/time/team?week=' . $w)['body'];
ok(str_contains($body, 'id="team-cell-26-' . day($w, 3) . '"') && preg_match('/bg-soft-danger[^>]*id="team-cell-26-' . day($w, 3) . '"/', $body) === 1, 'Priya\'s empty Thursday is marked danger');
[$c, $d] = screen($marco, '/time/team?week=' . $w . '&department=8');
ok(count($d['people']) === 1 && $d['people'][0]['member_id'] === 30, 'the practice filter (ML Engineering) leaves Dana alone');
[$c, $d] = screen($ann, '/time/team?week=' . $w);
ok($c === 403, 'Finance (no time.approve) may not open the team page');
[$c, $d] = screen($ann, '/time/missing?week=' . $w);
ok($c === 200, 'but reads missing time (reports.view)');
ok(page($bea, '/time/approvals')['code'] === 200, 'Billing opens the approvals');

echo "3. The home\n";
[$c, $d] = screen($marco, '/');
ok($c === 200 && is_array($d['approvals']) && is_array($d['approvals']['weeks']) && $d['my_week']['label'] === week_label(monday(0)), 'Marco\'s home: Approvals waiting (weeks) and his own week');
$body = page($marco, '/')['body'];
ok(str_contains($body, 'id="home-approvals"') && str_contains($body, 'id="home-my-week"'), 'the regions render');
[$c, $d] = screen($priya, '/');
ok($c === 200 && $d['approvals'] === null && $d['my_week'] !== null, 'Priya\'s home: My week, no approvals region');
finish();
