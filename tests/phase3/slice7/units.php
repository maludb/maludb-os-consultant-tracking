<?php
/**
 * Proof — the pure parts (spec "Proof", 4 and the status vocabulary): the period picker with a fixed "today", the status colours at their thresholds, the cells and the CSV text, the sums and percentages of a totals row.
 * No server and no database: queries.php and money.php are loaded as they are; `refuse()` is a stub that throws the status and the words.
 */
require __DIR__ . '/lib.php';
function refuse(int $status, string $message): never { throw new RuntimeException($status . ' ' . $message); }
require dirname(__DIR__, 3) . '/app/features/reports/queries.php';
require dirname(__DIR__, 3) . '/app/views/shared/money.php';
$day = static fn (string $d): DateTimeImmutable => new DateTimeImmutable($d);
$period = static fn (array $q, string $today): array => report_period($q, $day($today));
$refused = static function (array $q, string $today = '2026-10-05'): string { try { report_period($q, new DateTimeImmutable($today)); return ''; } catch (RuntimeException $e) { return $e->getMessage(); } };

echo "1. The picker's presets\n";
ok($period([], '2026-10-05') === ['from' => '2026-10-01', 'to' => '2026-10-31', 'preset' => 'this_month'], 'nothing asked, on 2026-10-05: this month, 10-01 to 10-31');
ok($period(['preset' => 'this_month'], '2026-02-10') === ['from' => '2026-02-01', 'to' => '2026-02-28', 'preset' => 'this_month'], 'this month in February 2026: to the 28th');
ok($period(['preset' => 'this_month'], '2028-02-10')['to'] === '2028-02-29', 'and in a leap February: the 29th');
ok($period(['preset' => 'last_month'], '2026-10-05') === ['from' => '2026-09-01', 'to' => '2026-09-30', 'preset' => 'last_month'], 'last month on 10-05: September');
ok($period(['preset' => 'last_month'], '2026-01-15') === ['from' => '2025-12-01', 'to' => '2025-12-31', 'preset' => 'last_month'], 'last month in January: December of the year before');
ok($period(['preset' => 'last_month'], '2026-03-31')['from'] === '2026-02-01' && $period(['preset' => 'last_month'], '2026-03-31')['to'] === '2026-02-28', 'last month on March 31: February (no overflow)');
ok($period(['preset' => 'this_quarter'], '2026-10-05') === ['from' => '2026-10-01', 'to' => '2026-12-31', 'preset' => 'this_quarter'], 'this quarter in October: Q4, 10-01 to 12-31');
ok($period(['preset' => 'this_quarter'], '2026-02-20')['from'] === '2026-01-01' && $period(['preset' => 'this_quarter'], '2026-02-20')['to'] === '2026-03-31', 'in February: Q1 to 03-31');
ok($period(['preset' => 'this_quarter'], '2026-06-30')['from'] === '2026-04-01' && $period(['preset' => 'this_quarter'], '2026-06-30')['to'] === '2026-06-30', 'on June 30: Q2 ends that day');
ok($period(['preset' => 'ytd'], '2026-10-05') === ['from' => '2026-01-01', 'to' => '2026-10-05', 'preset' => 'ytd'], 'year to date: January 1 to today');
ok($period(['preset' => 'nonsense'], '2026-10-05')['preset'] === 'this_month', 'an unknown preset is this month');

echo "2. Dates asked for, and the limit\n";
ok($period(['from' => '2026-03-01', 'to' => '2026-03-15', 'preset' => 'ytd'], '2026-10-05') === ['from' => '2026-03-01', 'to' => '2026-03-15', 'preset' => ''], 'from and to win over a preset');
ok($period(['from' => '2026-03-01'], '2026-10-05')['to'] === '2026-10-05', 'from alone: to today');
ok($period(['to' => '2026-03-15'], '2026-10-05')['from'] === '2026-03-01', 'to alone: from the first of its month');
ok($period(['from' => '2024-10-05', 'to' => '2026-10-05'], '2026-10-05')['from'] === '2024-10-05', 'exactly 24 months: allowed');
ok(str_contains($refused(['from' => '2024-10-05', 'to' => '2026-10-06']), '422 A report covers at most 24 months; choose a shorter period.'), '24 months and a day: refused in words (422)');
ok(str_contains($refused(['from' => '2024-09-01', 'to' => '2026-10-05']), 'at most 24 months'), '25 months: refused');
ok(str_contains($refused(['from' => '2026-03-15', 'to' => '2026-03-01']), '422 The period ends before it begins.'), 'backwards: refused');
ok(str_contains($refused(['from' => '2026-13-01']), '422 The first day of the period is a date') && str_contains($refused(['from' => 'yesterday']), 'is a date') && str_contains($refused(['to' => '2026-02-30']), '422 The last day of the period is a date'), 'not a date (13th month, a word, February 30th): refused');
ok(str_contains($refused(['from' => '2026-10-05', 'to' => '2026-10-05']) . 'x', 'x') && $refused(['from' => '2026-10-05', 'to' => '2026-10-05']) === '', 'a one-day period is fine');

echo "3. Status colours\n";
ok(report_status('utilization', '49.9') === 'danger' && report_status('utilization', '0.0') === 'danger' && report_status('utilization', '50.0') === 'warning' && report_status('utilization', '70.0') === 'warning' && report_status('utilization', '70.1') === 'success' && report_status('utilization', '95') === 'success', 'utilization: under 50 danger; 50 to 70 warning; over 70 success');
ok(report_status('realization', '79.9') === 'warning' && report_status('realization', '80.0') === '' && report_status('realization', '100.0') === '', 'realization: under 80 warning');
ok(report_status('margin', '-0.1') === 'danger' && report_status('margin', '0.0') === '' && report_status('margin', '48.4') === '', 'margin: negative danger');
ok(report_status('utilization', null) === '' && report_status('other', '5') === '', 'no value, or another kind: no colour');

echo "4. Sums, percentages, cells\n";
ok(report_sum([['a' => '1.25'], ['a' => '2.10'], ['a' => null]], 'a') === '3.35' && report_sum([['a' => '0.1'], ['a' => '0.2']], 'a') === '0.30', 'a sum to the cent (nulls count as nothing; 0.1 + 0.2 is 0.30)');
ok(report_sum([], 'a') === '0.00' && report_sum([['a' => '1000000']], 'a', 0) === '1000000', 'no rows: 0.00; tokens to no decimals');
ok(report_pct('7.00', '80.00') === '8.8' && report_pct('18.00', '240.00') === '7.5' && report_pct('1500.00', '3099.00') === '48.4' && report_pct('5', '0') === null && report_pct('-110', '100') === '-110.0', 'a percentage of two sums, one decimal (8.75 → 8.8), nothing over nothing');
$usd = ['currency' => 'USD'];
ok(report_cell(['key' => 'h', 'type' => 'hours'], ['h' => '7.50']) === '7.5 h' && report_cell(['key' => 'h', 'type' => 'hours'], ['h' => null]) === '—', 'hours: 7.5 h; none a dash');
ok(report_cell(['key' => 'm', 'type' => 'money'], ['m' => '-110.00'] + $usd) === '(110.00) USD' && report_cell(['key' => 'm', 'type' => 'money'], ['m' => '1234567.8'] + $usd) === '1,234,567.80 USD' && report_cell(['key' => 'm', 'type' => 'money'], ['m' => '5.00', 'currency' => 'EUR']) === '5.00 EUR' && report_cell(['key' => 'm', 'type' => 'money'], ['m' => null] + $usd) === '—', 'money: negative in parentheses, thousands, ITS OWN currency, a dash for none — never a bare figure');
ok(report_cell(['key' => 'p', 'type' => 'pct'], ['p' => '48.40']) === '48.4 %' && report_cell(['key' => 'i', 'type' => 'int'], ['i' => 3]) === '3' && report_cell(['key' => 'n', 'type' => 'number'], ['n' => '639.0']) === '639' && report_cell(['key' => 'n', 'type' => 'number0'], ['n' => '1000000']) === '1,000,000', 'pct, int, a number without a trailing zero, tokens');
$bc = ['key' => 'x', 'type' => 'budget', 'used' => 'u', 'of' => 'b', 'pct' => 'p', 'unit' => 'money'];
ok(report_cell($bc, ['u' => '10', 'b' => '100', 'p' => '10.0'] + $usd) === '10.00 USD of 100.00 USD (10.0 %)' && report_cell($bc, ['u' => '10', 'b' => null, 'p' => null] + $usd) === '10.00 USD (no budget)' && report_cell(['unit' => 'hours'] + $bc, ['u' => '2', 'b' => '8', 'p' => '25.0'] + $usd) === '2 h of 8 h (25.0 %)', 'a budget cell: used of budget (pct), or "(no budget)"');

echo "5. The CSV text\n";
$rep = ['columns' => [['key' => 'n', 'label' => 'Name', 'type' => 'text'], ['key' => 'm', 'label' => 'Amount', 'type' => 'money']],
        'rows' => [['n' => 'Smith, "Bob" & Co', 'm' => '1250.5', 'currency' => 'USD'], ['n' => "=HYPERLINK(\"http://x\")", 'm' => '-3', 'currency' => 'USD'], ['n' => '+1 call', 'm' => null, 'currency' => 'USD'], ['n' => 'Zoë ünïcode', 'm' => '1', 'currency' => 'EUR']],
        'totals' => [['label' => 'Total', 'currency' => 'USD', 'm' => '1247.50'], ['label' => 'Total', 'currency' => 'EUR', 'm' => '1.00']]];
$csv = report_csv($rep);
$h = fopen('php://temp', 'r+'); fwrite($h, $csv); rewind($h); $lines = []; while (($l = fgetcsv($h, 0, ',', '"', '')) !== false) { $lines[] = $l; }
ok($lines[0] === ['Name', 'Amount'] && $lines[1] === ['Smith, "Bob" & Co', '1,250.50 USD'], 'a comma and quotes in a name survive the round trip; the amount keeps thousands and currency');
ok($lines[2][0] === "'=HYPERLINK(\"http://x\")" && $lines[3][0] === "'+1 call" && $lines[2][1] === '(3.00) USD' && $lines[3][1] === '—' && $lines[4] === ['Zoë ünïcode', '1.00 EUR'], 'a name that begins like a formula gets an apostrophe; a negative is in parentheses; UTF-8 is kept');
ok($lines[5] === ['Total USD', '1,247.50 USD'] && $lines[6] === ['Total EUR', '1.00 EUR'] && count($lines) === 7, 'the totals: one per currency, labelled with it when there are several');
ok(!str_contains($csv, "\xEF\xBB\xBF") && str_ends_with($csv, "\n"), 'plain UTF-8 text (no byte-order mark), a newline at the end');
$one = report_cells(['columns' => $rep['columns'], 'rows' => [], 'totals' => [$rep['totals'][0]]]);
ok($one['totals'][0][0] === 'Total', 'a single currency: the label is just "Total"');
ok(array_keys(report_defs()) === ['utilization', 'realization', 'wip', 'revenue', 'margin', 'budget-burn', 'ai-passthrough', 'expenses', 'missing-time'], 'the nine reports of the allow-list, in the spec\'s order');
finish();
