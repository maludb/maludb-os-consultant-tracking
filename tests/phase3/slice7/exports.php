<?php
/**
 * Proof — the CSV of every report (spec "Proof", 2): the same rows the page shows (one query function, two shapes), a download named for the report and the period, `export.download` logged with the report, the filters and the ROW COUNT — never a row.
 * Who may: exports.download (Billing, Finance, the admin) AND the report's own right; a Manager and a Consultant are refused in words; an unknown report is 404.
 */
require __DIR__ . '/lib.php';
$w = report_world();
$owner = as_member(1); $priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29);
$p = whole();
/** The CSV body parsed back into lines of cells. */
function parse_csv(string $text): array
{
    $h = fopen('php://temp', 'r+'); fwrite($h, $text); rewind($h); $lines = [];
    while (($row = fgetcsv($h, 0, ',', '"', '')) !== false) { $lines[] = $row; }
    fclose($h);
    return $lines;
}
$cases = [
    'utilization' => ['from' => $w['w1'], 'to' => day($w['w1'], 6)], 'realization' => $p, 'wip' => [], 'revenue' => $p + ['by' => 'kind'], 'margin' => $p, 'budget-burn' => [],
    'ai-passthrough' => $p, 'expenses' => $p, 'missing-time' => ['week' => $w['w1']],
];

echo "1. Every report: the CSV equals the page's cells\n";
foreach ($cases as $report => $q) {
    $since = last_activity_id();
    $r = req('GET', '/reports/csv?' . http_build_query(['report' => $report] + $q), ['jar' => $owner]);
    [$c, $d] = report_json($owner, $report, $q);
    $lines = parse_csv($r['body']);
    $expect = array_merge([$d['cells']['header']], $d['cells']['rows'], $d['cells']['totals']);
    ok($r['code'] === 200 && str_contains($r['headers'], 'Content-Type: text/csv') && $lines === $expect, "$report: " . count($lines) . ' lines = the header, ' . count($d['cells']['rows']) . ' rows, ' . count($d['cells']['totals']) . ' totals — identical to the page\'s cells');
    $log = activity('export.download', $since);
    $after = json_decode((string) ($log[0]['after'] ?? 'null'), true);
    ok(count($log) === 1 && $log[0]['entity_type'] === 'report' && $after['report'] === $report && $after['rows'] === $d['row_count'] && !str_contains((string) $log[0]['after'], 'SMOKE'), "$report: export.download logged once with the report and rows = {$d['row_count']} — no row, no name");
}

echo "2. The file's name and the filters in the log\n";
$r = req('GET', '/reports/csv?' . http_build_query(['report' => 'utilization'] + $cases['utilization']), ['jar' => $owner]);
ok(str_contains($r['headers'], 'filename="utilization-' . $w['w1'] . '_' . day($w['w1'], 6) . '.csv"') && str_contains($r['headers'], 'attachment'), 'utilization-<from>_<to>.csv as an attachment');
$r = req('GET', '/reports/csv?report=wip&as_of=' . $w['w1'], ['jar' => $owner]);
ok(str_contains($r['headers'], 'filename="wip-' . $w['w1'] . '.csv"'), 'wip-<as of>.csv');
$since = last_activity_id();
req('GET', '/reports/csv?' . http_build_query(['report' => 'revenue', 'by' => 'engagement'] + $p), ['jar' => $owner]);
$after = json_decode((string) activity('export.download', $since)[0]['after'], true);
ok($after['by'] === 'engagement' && $after['from'] === $p['from'] && $after['to'] === $p['to'] && $after['rows'] === 2, 'the log carries the filters: by, from, to and rows = 2');
$r = req('GET', '/reports/csv?' . http_build_query(['report' => 'utilization', 'by' => 'practice'] + $cases['utilization']), ['jar' => $owner]);
$lines = parse_csv($r['body']);
ok(count($lines) === 1 + 10 + 1 && in_array(['Subtotal', 'AI Strategy', '80 h', '7 h', '7 h', '7 h', '8.8 %'], $lines, true), 'utilization by practice: the subtotals are in the file');

echo "3. Money keeps its currency; no cost rate leaves\n";
$m = parse_csv(req('GET', '/reports/csv?' . http_build_query(['report' => 'margin'] + $p), ['jar' => $ann])['body']);
ok($m[0] === ['Engagement', 'Client', 'Bill value', 'Time cost', 'Expense cost', 'Usage cost', 'Total cost', 'Margin', 'Margin %'] && $m[1][2] === '3,099.00 USD' && $m[1][7] === '1,500.00 USD', 'Finance\'s margin CSV: the cost columns the function answered, every amount with its currency');
$bad = [];
foreach ($cases as $report => $q) {
    $t = req('GET', '/reports/csv?' . http_build_query(['report' => $report] + $q), ['jar' => $bea])['body'];
    foreach ($t === '' ? [] : [$t] as $x) { if (preg_match('/cost rate|bill rate|hourly/i', parse_csv($x)[0] ? implode(',', parse_csv($x)[0]) : '')) { $bad[] = $report; } }
}
ok($bad === [], 'no report\'s header names a rate (a person\'s cost rate is never a column, in any CSV)');

echo "4. Who may download\n";
foreach ([['Marco', $marco], ['Priya', $priya]] as [$n, $j]) {
    $r = req('GET', '/reports/csv?report=realization&' . http_build_query($p), ['jar' => $j, 'headers' => JSONH]);
    ok($r['code'] === 403 && str_contains($r['body'], 'You may not download exports.'), "$n (no exports.download): 403 \"You may not download exports.\"");
}
$bad = [];
foreach (array_keys($cases) as $report) { foreach ([['Bea', $bea], ['Ann', $ann], ['owner', $owner]] as [$n, $j]) { if (req('GET', '/reports/csv?' . http_build_query(['report' => $report] + $cases[$report]), ['jar' => $j])['code'] !== 200) { $bad[] = "$n/$report"; } } }
ok($bad === [], 'Billing, Finance and the owner download all nine (27 downloads)' . ($bad ? ' — not: ' . implode(' ', $bad) : ''));
ok(req('GET', '/reports/csv?report=nonsense', ['jar' => $owner, 'headers' => JSONH])['code'] === 404 && req('GET', '/reports/csv', ['jar' => $owner, 'headers' => JSONH])['code'] === 404, 'an unknown or missing report: 404');
ok(req('GET', '/reports/csv?report=utilization', ['jar' => jar(), 'headers' => JSONH])['code'] === 401, 'no session: 401');
$r = req('GET', '/reports/csv?report=realization&from=2024-01-01&to=2026-10-02', ['jar' => $owner, 'headers' => JSONH]);
ok($r['code'] === 422 && str_contains($r['body'], 'at most 24 months'), 'a period over 24 months is refused here too');
$since = last_activity_id();
$r = req('GET', '/reports/csv?' . http_build_query(['report' => 'realization'] + $p), ['headers' => ['X-Action-Token: ' . person_token(28)]]);
$log = activity('export.download', $since);
ok($r['code'] === 200 && str_contains($r['headers'], 'text/csv') && count($log) === 1 && $log[0]['source'] === 'assistant', 'under Bea\'s action token the CSV downloads and logs as `assistant`');
$r = req('GET', '/reports/csv?' . http_build_query(['report' => 'realization'] + $p), ['headers' => ['X-Action-Token: ' . person_token(27)]]);
ok($r['code'] === 403, 'under Marco\'s token: 403');

echo "5. The aging keeps its own CSV (slice 6)\n";
$since = last_activity_id();
$r = req('GET', '/reports/ar-aging?format=csv', ['jar' => $bea]);
$after = json_decode((string) activity('export.download', $since)[0]['after'], true);
ok($r['code'] === 200 && $after['report'] === 'ar-aging' && str_contains($r['headers'], 'text/csv'), 'ar-aging?format=csv still logs export.download for report ar-aging');
finish();
