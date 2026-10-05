<?php
/**
 * Proof — AI pass-through (spec "Proof", 1). ct_ai_passthrough over the report world, by hand, the whole period (a charge counts by its period end):
 *   Acme · hosting (no provider shown as a dash): four charges 50 + 30 + 100 + 60 = cost 240.00; billable 50 + 30 + 120 (20 % markup) + 60 = 260.00; billed 200.00 (the first three are on INV-00001); unbilled 60.00; markup 260 − 240 = 20.00.
 *   Acme · Anthropic · model usage: one charge of 1,000,000 tokens at 0.000004 = cost 4.00, billable 4.00, billed 4.00, unbilled 0, markup 0.
 *   Total: 5 charges, 1,000,000 tokens, cost 244.00, billable 264.00, billed 204.00, unbilled 60.00, markup 20.00.
 * A period that ends before the charges' periods (W1): none.
 */
require __DIR__ . '/lib.php';
$w = report_world();
$owner = as_member(1); $priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29);

echo "1. The figures\n";
[$c, $d] = report_json($owner, 'ai-passthrough', whole());
ok($c === 200 && count($d['rows']) === 2, 'two lines: hosting (no provider) and Anthropic model usage');
$h = row_of($d, 'provider', '—'); $m = row_of($d, 'provider', 'Anthropic');
ok($h['charges'] === 4 && same($h['cost_amount'], 240) && same($h['billable_amount'], 260) && same($h['billed_amount'], 200) && same($h['unbilled_amount'], 60) && same($h['markup'], 20) && $h['tokens'] === null, 'hosting: 4 charges, cost 240.00, billable 260.00, billed 200.00, unbilled 60.00, markup 20.00');
ok($m['charges'] === 1 && same($m['tokens'], 1000000) && same($m['cost_amount'], 4) && same($m['billable_amount'], 4) && same($m['billed_amount'], 4) && same($m['unbilled_amount'], 0) && same($m['markup'], 0), 'model usage: 1,000,000 tokens, cost 4.00 = billable 4.00 = billed, markup 0');
$t = $d['totals'][0];
ok($t['charges'] === 5 && same($t['tokens'], 1000000) && same($t['cost_amount'], 244) && same($t['billable_amount'], 264) && same($t['billed_amount'], 204) && same($t['unbilled_amount'], 60) && same($t['markup'], 20) && $t['currency'] === 'USD', 'totals: 5 charges, cost 244.00, billable 264.00, billed 204.00, unbilled 60.00, markup 20.00');
ok($d['cells']['header'] === ['Engagement', 'Provider', 'Kind', 'Charges', 'Tokens', 'Cost', 'Billable', 'Billed', 'Unbilled', 'Markup'], 'the columns (cost and markup for a holder of cost.view)');
ok(in_array(['ENG-0001', 'Anthropic', 'Model Usage', '1', '1,000,000', '4.00 USD', '4.00 USD', '4.00 USD', '0.00 USD', '0.00 USD'], $d['cells']['rows'], true), 'the Anthropic row as cells');
ok($d['cells']['totals'][0] === ['Total', '', '', '5', '1,000,000', '244.00 USD', '264.00 USD', '204.00 USD', '60.00 USD', '20.00 USD'], 'the totals as cells');
[$c, $d] = report_json($owner, 'ai-passthrough', ['from' => $w['w1'], 'to' => day($w['w1'], 6)]);
ok($d['rows'] === [] && $d['totals'] === [], 'W1 (before the charges\' period ends): none');
[$c, $d] = report_json($owner, 'ai-passthrough', whole() + ['engagement' => $w['beta']]);
ok($d['rows'] === [], 'engagement = Beta: none');
[$c, $d] = report_json($owner, 'ai-passthrough', whole() + ['engagement' => $w['acme']]);
ok(count($d['rows']) === 2, 'engagement = Acme: both');

echo "2. Who sees\n";
[$c, $d] = report_json($marco, 'ai-passthrough', whole());
ok($c === 200 && same($d['totals'][0]['cost_amount'], 244), 'Marco: his engagement\'s');
[$c, $d] = report_json($ann, 'ai-passthrough', whole());
ok($c === 200 && same($d['totals'][0]['billable_amount'], 264), 'Finance: all');
ok(req('GET', '/reports/ai-passthrough', ['jar' => $priya, 'headers' => JSONH])['code'] === 403, 'a Consultant: 403');
$b = page($owner, '/reports/ai-passthrough?' . http_build_query(whole()))['body'];
ok(str_contains($b, 'id="report-table"') && str_contains($b, 'id="report-totals"') && str_contains($b, 'name="engagement"') && str_contains($b, '244.00 USD') && str_contains($b, '20.00 USD'), 'the page: table, totals, engagement filter, money with its currency');
finish();
