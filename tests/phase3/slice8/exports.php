<?php
/**
 * Proof — the exports page and the seven documents (spec "Proof", 10). Each document is the very function a share answers; the proof asserts the COLUMNS of the tool surface's shapes (docs/consultant-tracking-mcp-tool-surface.md, "The share documents") and
 * hand-derived figures from slice 7's world: INV-00001 = time 1,260.00 + 875.00 (Priya 7 h at 180, Dana 7 h at 125), expenses 100.00 + 40.00, usage 50.00 + 4.00 + 30.00 + 120.00 (a 100.00 hosting charge marked up 20 %) = 2,479.00, issued by Bea; INV-00002 = a fixed 300.00 on Bea's engagement.
 * This proof adds: CN-… of 250.00 against INV-00001; three COMPANY-CARD expenses on Acme (meals 60.00 billable with 5.00 tax, meals 25.00 not billable, travel 70.00); 45.00 of approved billable expense on Beta (its WIP).
 */
require __DIR__ . '/lib.php';
$w = report_world(); $acme = $w['acme']; $beta = (int) billing_world()['beta'];
$owner = as_member(1); $priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29);
$p = whole(); $today = today();
function parse_csv(string $text): array { $h = fopen('php://temp', 'r+'); fwrite($h, $text); rewind($h); $l = []; while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) { $l[] = $r; } fclose($h); return $l; }
function exp_dl(string $jar, string $export, string $format, array $q = []): array { return req('GET', '/exports/download.php?' . http_build_query(['export' => $export, 'format' => $format] + $q), ['jar' => $jar]); }

$cnNumber = exports_world()['cn'];

echo "1. Who may; the page\n";
foreach ([['Priya', $priya], ['Marco', $marco]] as [$n, $j]) {
    $r = req('GET', '/exports/download.php?export=invoices&format=csv', ['jar' => $j, 'headers' => JSONH]);
    ok($r['code'] === 403 && str_contains($r['body'], 'You may not download exports.') && page($j, '/exports')['code'] === 403, "$n (no exports.download): the page and the download are 403 \"You may not download exports.\"");
}
ok(page($bea, '/exports')['code'] === 200 && page($ann, '/exports')['code'] === 200 && page($owner, '/exports')['code'] === 200, 'Billing, Finance and the owner open the page');
[$c, $d] = screen($bea, '/exports');
ok($c === 200 && array_column($d['exports'], 'export') === ['invoices', 'credit_notes', 'expenses', 'reimbursements', 'wip', 'time', 'usage'] && $d['exports'][0]['schema'] === 'os.ct-invoices/1' && $d['exports'][3]['window'] === 'as_of' && $d['exports'][0]['formats'] === ['csv', 'json'], 'the page (JSON): the seven exports, their schemas, which are "as of", and the two formats');
$r = page($bea, '/exports');
ok(substr_count($r['body'], 'id="export-card-') === 7 && str_contains($r['body'], 'id="export-invoices-csv-btn"') && str_contains($r['body'], 'id="export-usage-json-btn"') && str_contains($r['body'], 'id="exports-filters"'), 'the page: seven cards, each with a CSV and a JSON button, and the period picker');
[$c, $d] = screen($bea, '/exports');
ok($d['period']['from'] === (string) one("SELECT to_char(date_trunc('month', current_date) - interval '1 month', 'YYYY-MM-DD')") && $d['period']['to'] === (string) one("SELECT to_char(date_trunc('month', current_date) - interval '1 day', 'YYYY-MM-DD')") && $d['as_of'] === $today, 'the default period is last month; "as of" is today');

echo "2. Invoices issued — os.ct-invoices/1\n";
$since = last_activity_id();
$r = exp_dl($bea, 'invoices', 'json', $p);
$doc = json_decode($r['body'], true);
ok($r['code'] === 200 && str_contains($r['headers'], 'application/json') && str_contains($r['headers'], 'filename="ct-invoices-' . $p['from'] . '_' . $p['to'] . '.json"') && str_contains($r['headers'], 'attachment'), 'a JSON download named for the export and the period, as an attachment');
ok(array_keys($doc) === ['schema', 'generated_at', 'application', 'from', 'to', 'currency_note', 'rows'] && $doc['schema'] === 'os.ct-invoices/1' && $doc['application'] === 'consultant_tracking' && $doc['from'] === $p['from'] && $doc['to'] === $p['to'] && $doc['currency_note'] === 'amounts carry their own currency; nothing is converted' && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $doc['generated_at']) === 1, 'the head: schema, generated_at, application, from, to, currency_note, rows — exactly');
$rows = array_column($doc['rows'], null, 'number');
$n1 = $rows['INV-00001'];
ok(array_keys($n1) === ['number', 'client', 'engagement_number', 'issue_date', 'due_date', 'terms_days', 'currency', 'subtotal', 'tax_total', 'total', 'status', 'external_ref', 'lines_by_kind'] && array_keys($n1['client']) === ['client_id', 'name', 'legal_name', 'code'], 'a row\'s columns are the tool surface\'s: number, client {client_id, name, legal_name, code}, engagement_number, issue_date, due_date, terms_days, currency, subtotal, tax_total, total, status, external_ref, lines_by_kind');
ok($n1['client']['name'] === 'SMOKE Acme' && $n1['client']['code'] === 'ACME' && $n1['engagement_number'] === 'ENG-0001' && $n1['currency'] === 'USD' && $n1['total'] === '2479.00' && $n1['subtotal'] === '2479.00' && $n1['tax_total'] === '0.00' && $n1['terms_days'] === 30 && in_array($n1['status'], ['issued', 'sent', 'viewed', 'partially_paid', 'paid'], true), 'INV-00001: SMOKE Acme / ACME, ENG-0001, USD, 2,479.00 with no tax, 30 days\' terms');
ok($n1['lines_by_kind'] === [['kind' => 'expense', 'subtotal' => '140.00', 'tax' => '0.00', 'total' => '140.00'], ['kind' => 'time', 'subtotal' => '2135.00', 'tax' => '0.00', 'total' => '2135.00'], ['kind' => 'usage', 'subtotal' => '204.00', 'tax' => '0.00', 'total' => '204.00']], 'its lines by kind: expense 140.00, time 2,135.00 (1,260.00 + 875.00), usage 204.00 (50 + 4 + 30 + 120)');
ok($rows['INV-00002']['lines_by_kind'] === [['kind' => 'fixed', 'subtotal' => '300.00', 'tax' => '0.00', 'total' => '300.00']] && $rows['INV-00002']['client']['name'] === 'SMOKE Beta', 'INV-00002: one fixed line of 300.00, on SMOKE Beta');
$expected = (int) one("SELECT count(*) FROM invoices WHERE status NOT IN ('draft', 'void') AND issue_date >= :f AND issue_date <= :t", ['f' => $p['from'], 't' => $p['to']]);
ok(count($doc['rows']) === $expected && !in_array('draft', array_column($doc['rows'], 'status'), true) && !in_array('void', array_column($doc['rows'], 'status'), true), "every invoice issued in the period and no draft or void ($expected rows)");
$log = activity('export.download', $since);
$la = json_decode((string) $log[0]['after'], true);
ok(count($log) === 1 && $log[0]['entity_type'] === 'export' && $la === ['export' => 'invoices', 'format' => 'json', 'from' => $p['from'], 'to' => $p['to'], 'rows' => $expected] || (ksort($la) && $la === ['export' => 'invoices', 'format' => 'json', 'from' => $p['from'], 'rows' => $expected, 'to' => $p['to']]), 'export.download logged once: the export, the format, from, to and the ROW COUNT — no row');
$csv = parse_csv(exp_dl($bea, 'invoices', 'csv', $p)['body']);
ok($csv[0] === ['number', 'client_client_id', 'client_name', 'client_legal_name', 'client_code', 'engagement_number', 'issue_date', 'due_date', 'terms_days', 'currency', 'subtotal', 'tax_total', 'total', 'status', 'external_ref', 'lines_by_kind'] && count($csv) === $expected + 1, 'the CSV: one column per field, the client flattened, a line per invoice (' . count($csv) . ' lines with the header)');
$line = array_values(array_filter($csv, fn ($l) => $l[0] === 'INV-00001'))[0];
ok($line[2] === 'SMOKE Acme' && $line[12] === '2479.00' && $line[15] === 'expense 140.00 (tax 0.00, total 140.00); time 2135.00 (tax 0.00, total 2135.00); usage 204.00 (tax 0.00, total 204.00)', 'INV-00001 in the CSV: the name, 2479.00 (a plain decimal beside its currency column), the lines by kind in one cell');
$one = exp_dl($bea, 'invoices', 'json', ['from' => $p['from'], 'to' => $p['to'], 'client' => (int) $n1['client']['client_id']]);
ok(count(json_decode($one['body'], true)['rows']) >= 1 && !in_array('INV-00002', array_column(json_decode($one['body'], true)['rows'], 'number'), true), 'a client filter narrows it (Beta\'s INV-00002 is not in Acme\'s)');

echo "3. Credit notes — os.ct-credit-notes/1\n";
$doc = json_decode(exp_dl($bea, 'credit_notes', 'json', $p)['body'], true);
$cr = array_column($doc['rows'], null, 'number')[$cnNumber] ?? [];
ok($doc['schema'] === 'os.ct-credit-notes/1' && array_keys($cr) === ['number', 'client', 'engagement_number', 'invoice_number', 'issue_date', 'currency', 'subtotal', 'tax_total', 'total', 'status', 'external_ref', 'lines_by_kind'], 'the columns: number, client, engagement_number, invoice_number, issue_date, currency, subtotal, tax_total, total, status, external_ref, lines_by_kind');
ok($cr['invoice_number'] === 'INV-00001' && $cr['total'] === '250.00' && $cr['subtotal'] === '250.00' && $cr['status'] === 'issued' && $cr['currency'] === 'USD' && $cr['issue_date'] === $today && $cr['client']['name'] === 'SMOKE Acme' && $cr['lines_by_kind'] === [['kind' => 'adjustment', 'subtotal' => '250.00', 'tax' => '0.00', 'total' => '250.00']], "$cnNumber: against INV-00001, 250.00 (positive — the reader negates), one adjustment line");

echo "4. Expenses by category — os.ct-expenses/1\n";
$doc = json_decode(exp_dl($bea, 'expenses', 'json', $p)['body'], true);
$month = substr($today, 0, 7);
ok($doc['schema'] === 'os.ct-expenses/1' && array_keys($doc['rows'][0]) === ['month', 'category', 'engagement_number', 'currency', 'amount', 'tax_amount', 'billable', 'count'] && array_keys($doc['rows'][0]['category']) === ['key', 'label'], 'the columns: month, category {key, label}, engagement_number, currency, amount, tax_amount, billable, count');
ok($doc['rows'] === [
    ['month' => $month, 'category' => ['key' => 'meals', 'label' => 'Meals'], 'engagement_number' => 'ENG-0001', 'currency' => 'USD', 'amount' => '60.00', 'tax_amount' => '5.00', 'billable' => true, 'count' => 1],
    ['month' => $month, 'category' => ['key' => 'meals', 'label' => 'Meals'], 'engagement_number' => 'ENG-0001', 'currency' => 'USD', 'amount' => '25.00', 'tax_amount' => '0.00', 'billable' => false, 'count' => 1],
    ['month' => $month, 'category' => ['key' => 'travel', 'label' => 'Travel'], 'engagement_number' => 'ENG-0001', 'currency' => 'USD', 'amount' => '70.00', 'tax_amount' => '0.00', 'billable' => true, 'count' => 1]], 'the three company-card expenses by month, category, engagement and billable: meals 60.00 (5.00 tax), meals 25.00 not billable, travel 70.00 — personal-card expenses are not here, and no person is named');
ok(!str_contains(json_encode($doc), 'SMOKE Priya') && !str_contains(json_encode($doc), 'member'), 'no person in the document');

echo "5. Reimbursements — os.ct-reimbursements/1 (people)\n";
$doc = json_decode(exp_dl($bea, 'reimbursements', 'json', ['to' => $today])['body'], true);
$due = (int) one("SELECT count(*) FROM expenses WHERE status = 'approved' AND paid_with = 'personal' AND reimbursed_at IS NULL AND expense_date <= :d", ['d' => $today]);
$rt = array_values(array_filter($doc['rows'], fn ($r) => $r['description'] === 'SMOKE rpt taxi'));
ok($doc['schema'] === 'os.ct-reimbursements/1' && $doc['as_of'] === $today && !isset($doc['from']) && array_keys($doc['rows'][0]) === ['member_id', 'expense_number', 'expense_date', 'currency', 'amount', 'description'] && count($doc['rows']) === $due, "approved, unreimbursed, personal-card, as of today: $due rows with member_id, expense_number, expense_date, currency, amount, description");
ok(count($rt) === 1 && $rt[0]['member_id'] === 26 && $rt[0]['amount'] === '20.00' && $rt[0]['currency'] === 'USD' && $rt[0]['expense_date'] === day($w['w4'], 0) && !in_array('SMOKE exports card 60.00', array_column($doc['rows'], 'description'), true), 'Priya\'s 20.00 taxi of this week is owed; a company-card expense is not');
ok(exp_dl($ann, 'reimbursements', 'json', ['to' => $today])['code'] === 403, 'Finance may download exports but not this one: reimbursements are people data — Billing\'s (billing.write): 403');

echo "6. Work in progress — os.ct-wip/1\n";
$doc = json_decode(exp_dl($bea, 'wip', 'json', ['to' => $today])['body'], true);
$bw = array_values(array_filter($doc['rows'], fn ($r) => $r['engagement_number'] === 'ENG-0002'));
ok($doc['schema'] === 'os.ct-wip/1' && $doc['as_of'] === $today && array_keys($doc['rows'][0]) === ['as_of', 'engagement_number', 'client_name', 'currency', 'time_amount', 'expense_amount', 'usage_amount', 'milestone_amount', 'total_amount'], 'the columns: as_of, engagement_number, client_name, currency, time_amount, expense_amount, usage_amount, milestone_amount, total_amount');
ok(count($bw) === 1 && $bw[0] === ['as_of' => $today, 'engagement_number' => 'ENG-0002', 'client_name' => 'SMOKE Beta', 'currency' => 'USD', 'time_amount' => '0.00', 'expense_amount' => '45.00', 'usage_amount' => '0.00', 'milestone_amount' => '0.00', 'total_amount' => '45.00'], 'Beta: 45.00 of expense, nothing else, 45.00 in all');
$ey = exp_dl($bea, 'wip', 'json', ['to' => plus_days($today, -400)]);
ok(json_decode($ey['body'], true)['rows'] === [], 'as of a day long ago: nothing (nothing was approved yet)');

echo "7. Time detail — os.ct-time/1 and usage detail — os.ct-usage/1\n";
$wk = ['from' => $w['w1'], 'to' => day($w['w1'], 6)];
$doc = json_decode(exp_dl($bea, 'time', 'json', $wk)['body'], true);
ok($doc['schema'] === 'os.ct-time/1' && array_keys($doc['rows'][0]) === ['entry_date', 'member_id', 'member_name', 'engagement_number', 'hours', 'billable', 'bill_value', 'currency', 'note', 'billed'] && !str_contains(json_encode($doc), 'cost') && !str_contains(json_encode($doc), 'internal'), 'time: the columns entry_date, member_id, member_name, engagement_number, hours, billable, bill_value, currency, note, billed — no cost, no internal note');
$t = array_map(fn ($r) => [$r['entry_date'], $r['member_name'], $r['hours'], $r['bill_value']], $doc['rows']);
ok($t === [[day($w['w1'], 0), 'SMOKE Dana', '5.00', '625.00'], [day($w['w1'], 0), 'SMOKE Priya', '4.00', '720.00'], [day($w['w1'], 1), 'SMOKE Dana', '2.00', '250.00'], [day($w['w1'], 1), 'SMOKE Priya', '3.00', '540.00'], [day($w['w1'], 2), 'SMOKE Owner', '4.00', null]], 'the week of ' . $w['w1'] . ': Dana 5 h = 625.00 and 2 h = 250.00, Priya 4 h = 720.00 and 3 h = 540.00, the owner\'s 4 h in a role the card does not price (no bill value) — written-off and unapproved time is not here');
ok($doc['rows'][0]['note'] === 'SMOKE work 30 day 0' && $doc['rows'][0]['billed'] === true && $doc['rows'][4]['billed'] === false, 'the client-visible note; billed true for what INV-00001 took, false for what it could not price');
$doc = json_decode(exp_dl($bea, 'usage', 'json', ['from' => '2026-09-01', 'to' => '2026-09-30'])['body'], true);
$manual = array_values(array_filter($doc['rows'], fn ($r) => $r['source'] === 'manual' && $r['period_start'] === '2026-09-01'));
ok($doc['schema'] === 'os.ct-usage/1' && array_keys($doc['rows'][0]) === ['period_start', 'period_end', 'engagement_number', 'provider', 'kind', 'quantity', 'unit', 'cost_amount', 'billable_amount', 'billable', 'billed', 'currency', 'status', 'source'], 'usage: the columns period_start, period_end, engagement_number, provider, kind, quantity, unit, cost_amount, billable_amount, billable, billed, currency, status, source');
$u = array_map(fn ($r) => [$r['cost_amount'], $r['billable_amount']], $manual);
sort($u);
ok($u === [['100.00', '120.00'], ['30.00', '30.00'], ['4.00', '4.00'], ['50.00', '50.00']] || (function () use ($u) { usort($u, fn ($a, $b) => (float) $a[0] <=> (float) $b[0]); return $u; })() === [['4.00', '4.00'], ['30.00', '30.00'], ['50.00', '50.00'], ['100.00', '120.00']], 'the September hand-entered charges: cost 50.00, 4.00, 30.00 and 100.00 (billable 120.00, a 20 % markup) — Finance and Billing see cost');
$docAnn = json_decode(exp_dl($ann, 'usage', 'json', ['from' => '2026-09-01', 'to' => '2026-09-30'])['body'], true);
ok(count($docAnn['rows']) === count($doc['rows']) && $docAnn['rows'][0]['cost_amount'] !== null, 'Finance downloads the same usage detail, cost included (cost.view)');

echo "8. Formats, periods, refusals, the action\n";
ok(exp_dl($bea, 'invoices', 'xml', $p)['code'] === 422 && exp_dl($bea, 'bogus', 'csv', $p)['code'] === 404 && exp_dl($bea, '', 'csv')['code'] === 404, 'an unknown format is 422, an unknown or missing export 404');
$r = req('GET', '/exports/download.php?' . http_build_query(['export' => 'invoices', 'format' => 'csv', 'from' => '2023-01-01', 'to' => '2026-10-02']), ['jar' => $bea, 'headers' => JSONH]);
ok($r['code'] === 422 && str_contains($r['body'], 'at most 24 months'), 'a period over 24 months is refused in words');
$r = req('GET', '/exports/download.php?' . http_build_query(['export' => 'invoices', 'format' => 'csv', 'from' => '2026-13-01', 'to' => '2026-10-02']), ['jar' => $bea, 'headers' => JSONH]);
ok($r['code'] === 422 && str_contains($r['body'], 'a date (YYYY-MM-DD)'), 'a bad date is refused in words');
$r = req('GET', '/exports/download.php?' . http_build_query(['export' => 'invoices', 'format' => 'csv', 'from' => '2026-10-03', 'to' => '2026-10-02']), ['jar' => $bea, 'headers' => JSONH]);
ok($r['code'] === 422 && str_contains($r['body'], 'ends before it begins'), 'a period that ends before it begins is refused');
ok(req('GET', '/exports/download.php?export=invoices&format=csv', ['jar' => jar(), 'headers' => JSONH])['code'] === 401, 'no session: 401');
[$c, $b, $raw] = act($bea, '/exports/download.php', ['export' => 'wip', 'format' => 'json', 'to' => $today]);
ok($c === 200 && $b['ok'] === true && $b['export'] === 'wip' && $b['format'] === 'json' && $b['rows'] === count($b['document']['rows']) && $b['document']['schema'] === 'os.ct-wip/1' && $b['filename'] === 'ct-wip-' . $today . '.json', 'export_download as an action (POST, CSRF, JSON): {ok, export, format, rows, filename, document}');
[$c, $b] = act($bea, '/exports/download.php', ['export' => 'wip', 'format' => 'csv', 'to' => $today]);
ok($c === 200 && str_starts_with($b['csv'], 'as_of,engagement_number,client_name') && !isset($b['document']), '… and as csv: the text in `csv`');
$r = req('POST', '/exports/download.php', ['jar' => $bea, 'headers' => JSONH, 'form' => ['export' => 'wip', 'format' => 'json']]);
ok($r['code'] === 403, 'a POST with no CSRF token: refused');
$since = last_activity_id();
$r = req('POST', '/exports/download.php', ['headers' => JSONH + [1 => 'X-Action-Token: ' . person_token(28)], 'form' => ['export' => 'expenses', 'format' => 'json'] + $p]);
$log = activity('export.download', $since);
ok($r['code'] === 200 && count($log) === 1 && $log[0]['source'] === 'assistant', 'under Bea\'s action token the download works and logs as `assistant`');
$r = req('POST', '/exports/download.php', ['headers' => JSONH + [1 => 'X-Action-Token: ' . person_token(27)], 'form' => ['export' => 'expenses', 'format' => 'json'] + $p]);
ok($r['code'] === 403, 'under Marco\'s token: 403');
finish();
