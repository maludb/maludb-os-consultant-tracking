<?php
/**
 * Proof — JSON mode (spec "Proof", 5 and the manifest): every report answers {report, title, filters, columns, rows, totals, cells, row_count, …} the same rows the page shows; the index answers its cards; a signed action token reads them
 * as the person it carries (and is refused as the person would be); a JSON read logs no screen.view unless it asks (X-Screen-View: 1); a refusal is {error: {code, message}}.
 */
require __DIR__ . '/lib.php';
$w = report_world();
$owner = as_member(1); $priya = as_member(26); $marco = as_member(27); $bea = as_member(28);
$mTok = ['X-Action-Token: ' . person_token(27)]; $pTok = ['X-Action-Token: ' . person_token(26)]; $bTok = ['X-Action-Token: ' . person_token(28)];
$cases = [
    'utilization' => ['from' => $w['w1'], 'to' => day($w['w1'], 6)], 'realization' => whole(), 'wip' => [], 'revenue' => whole(), 'margin' => whole(), 'budget-burn' => [],
    'ai-passthrough' => whole(), 'expenses' => whole(), 'missing-time' => ['week' => $w['w1']],
];
$types = ['text', 'hours', 'money', 'pct', 'int', 'date', 'budget', 'number', 'number0'];

echo "1. The shape of every report\n";
foreach ($cases as $report => $q) {
    [$c, $d] = report_json($owner, $report, $q);
    $keys = array_keys($d);
    $okKeys = $c === 200 && count(array_diff(['report', 'title', 'filters', 'columns', 'rows', 'totals', 'cells', 'row_count', 'may'], $keys)) === 0 && $d['report'] === $report;
    $okCols = $d['columns'] !== [] && count(array_filter($d['columns'], static fn ($x) => array_keys($x) === ['key', 'label', 'type'] && in_array($x['type'], $types, true))) === count($d['columns']) && array_column($d['columns'], 'label') === $d['cells']['header'];
    $okCells = count($d['cells']['rows']) === count($d['rows']) && count($d['cells']['totals']) === count($d['totals']) && $d['row_count'] === count($d['rows']) && array_reduce($d['cells']['rows'], static fn ($a, $r) => $a && count($r) === count($d['columns']), true);
    ok($okKeys && $okCols && $okCells, "$report: keys, typed columns (key, label, type), cells as wide as the columns, row_count = " . $d['row_count']);
}
[$c, $d] = report_json($owner, 'realization', whole());
ok($d['title'] === 'Realization' && $d['filters'] === ['from' => whole()['from'], 'to' => whole()['to'], 'preset' => '', 'engagement' => null, 'client' => null], 'the filters echoed: the period, no preset, the pickers null');
ok(is_int($d['rows'][0]['engagement_id']) && is_string($d['rows'][0]['approved_hours']) && !isset($d['rows'][0]['engagement_href']), 'typed rows: ids are integers, amounts are strings as the database prints them; no link keys leak');
[$c, $d] = report_json($owner, 'revenue');
ok($d['filters']['preset'] === 'this_month' && $d['filters']['from'] === date('Y-m-01') && $d['filters']['to'] === date('Y-m-t') && $d['filters']['by'] === 'client', 'no period asked: this month (' . date('Y-m-01') . ' to ' . date('Y-m-t') . '), by client');
[$c, $d] = report_json($owner, 'margin', whole());
ok($d['scope'] === 'Every engagement' && $d['may']['csv'] === true, 'margin carries its scope and the CSV right');
[$c, $d] = report_json($owner, 'missing-time', ['week' => $w['w1']]);
ok($d['week_start'] === $w['w1'], 'missing time carries the week it looked at');
[$c, $d] = screen($owner, '/reports/');
ok($c === 200 && count($d['reports']) === 10 && array_keys($d['reports'][0]) === ['key', 'title', 'blurb', 'url'] && $d['reports'][0]['url'] === '/reports/utilization', 'the index: ten cards with key, title, blurb and url');

echo "2. Under an action token\n";
$get = static fn (string $path, array $tok): array => (static function ($r) { return [(int) $r['code'], json_decode($r['body'], true) ?? []]; })(req('GET', $path, ['headers' => array_merge(JSONH, $tok)]));
[$c, $b] = $get('/reports/margin?' . http_build_query(whole()), $mTok);
ok($c === 200 && $b['data']['scope'] === 'Your engagements' && same($b['data']['rows'][0]['margin'], 1500), 'Marco\'s token: his margin, "Your engagements"');
[$c, $b] = $get('/reports/margin?' . http_build_query(whole()), $pTok);
ok($c === 403 && ($b['error']['code'] ?? '') === 'forbidden' && ($b['error']['message'] ?? '') === 'You may not see cost and margin.', 'Priya\'s token: 403 {error: {code: forbidden, message: …}}');
[$c, $b] = $get('/reports/utilization?' . http_build_query($cases['utilization']), $pTok);
ok($c === 200 && count($b['data']['rows']) === 1 && $b['data']['rows'][0]['member_name'] === 'SMOKE Priya', 'Priya\'s token: her own utilization');
[$c, $b] = $get('/reports/revenue?' . http_build_query(whole()), $bTok);
ok($c === 200 && same($b['data']['totals'][0]['total'], 2779), 'Bea\'s token: revenue');
[$c, $b] = $get('/reports/revenue?' . http_build_query(whole()), $mTok);
ok($c === 200 && same($b['data']['totals'][0]['total'], 2479), 'Marco\'s token: revenue scoped to his engagements, 2,479.00 (db/018)');

echo "3. Logging and refusals\n";
$since = last_activity_id();
req('GET', '/reports/realization?' . http_build_query(whole()), ['jar' => $owner, 'headers' => JSONH]);
ok(count(array_filter(activity('screen.view', $since), static fn ($l) => $l['screen'] === 'report')) === 0, 'a plain JSON read logs no screen.view');
req('GET', '/reports/realization?' . http_build_query(whole()), ['jar' => $owner, 'headers' => array_merge(JSONH, ['X-Screen-View: 1'])]);
$log = array_values(array_filter(activity('screen.view', $since), static fn ($l) => $l['screen'] === 'report'));
$after = json_decode((string) ($log[0]['after'] ?? 'null'), true);
ok(count($log) === 1 && $after['report'] === 'realization' && $after['from'] === whole()['from'] && $after['to'] === whole()['to'], 'X-Screen-View: 1 logs it, with the report and the period in after');
ok(!array_key_exists('engagement', $after) && !array_key_exists('client', $after), 'filters that are not set are not in the log');
$since = last_activity_id();
page($owner, '/reports/');
ok(count(array_filter(activity('screen.view', $since), static fn ($l) => $l['screen'] === 'reports')) === 1, 'the index page logs screen `reports`');
$r = req('GET', '/reports/realization?from=2024-01-01&to=2026-10-02', ['jar' => $owner, 'headers' => JSONH]);
$e = json_decode($r['body'], true)['error'] ?? [];
ok($r['code'] === 422 && str_contains($e['message'] ?? '', 'A report covers at most 24 months; choose a shorter period.'), 'a period over 24 months: 422 in words');
$r = req('GET', '/reports/realization?from=2026-09-01&to=2026-08-01', ['jar' => $owner, 'headers' => JSONH]);
ok($r['code'] === 422 && str_contains($r['body'], 'The period ends before it begins.'), 'a backwards period: 422 in words');
$r = req('GET', '/reports/wip?as_of=soon', ['jar' => $owner, 'headers' => JSONH]);
ok($r['code'] === 422, 'an "as of" that is no date: 422');
$r = req('GET', '/reports/nonsense', ['jar' => $owner, 'headers' => JSONH]);
ok($r['code'] === 404 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'not_found', 'an unknown report: 404 {error: {code: not_found}}');
$r = req('GET', '/reports/realization?from=2024-01-01&to=2026-10-02', ['jar' => $owner]);
ok($r['code'] === 422 && str_contains($r['body'], 'at most 24 months'), 'in the browser the same refusal is a page that says so (422)');
$r = page($owner, '/reports/margin?from=2026-09-01&to=2026-09-30&engagement=abc');
ok($r['code'] === 200, 'a picker value that is not a number is dropped, not an error');
finish();
