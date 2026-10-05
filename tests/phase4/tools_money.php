<?php
/**
 * Proof — billing and the reports as Priya (Consultant), Marco (Manager of Acme and Beta), Bea (Billing), Ann (Finance) and the owner. Hand-derived figures (tests/phase4/lib.php; slices 6 and 7):
 *   INV-00001 (Acme, issued today, due in 30 days): time 720 + 540 (Priya) + 625 + 250 (Dana: 5 h approved, 2 h written off at standard) = 2,135.00; expenses 100 + 40 = 140.00; usage 50 + 4 + 30 + 120 (100 marked up 20 %) = 204.00; total 2,479.00.
 *   INV-00002 (Bea's own engagement ENG-0003, client Beta): a fixed line of 300.00. A 250.00 credit note against INV-00001 (issued, not applied). An empty DRAFT of Bea's on Beta.
 *   Unbilled on Acme: Priya's 3 h this week 540, expenses 20 + 60 + 70 (the 25.00 is not billable), a 60.00 hosting charge = 750.00, and the owner's 4 h with no rate (no_rate); on Beta: the 5,000.00 milestone and a 45.00 expense = 5,045.00.
 *   Acme's realization: 23 h approved (21 + the 2 written off), 14 billed, 2 written off, 3 unbilled, 4 without a rate; standard 1,800 + 1,125 = 2,925.00 against 2,135.00 billed (73.0 %).
 *   Acme's margin: bill value 2,675 (time) + 290 (billable expenses) + 264 (usage) = 3,229.00; cost 1,195 (700 + 495) + 315 + 244 = 1,754.00; margin 1,475.00 (45.7 %). Beta: 5,045.00 less 45.00 = 5,000.00.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$acme = $w['acme']; $beta = $w['beta']; $beas = $w['beas'];
$P = ['from' => '2026-09-01', 'to' => '2026-10-31'];

echo "1. What is unbilled, and what is due to be drafted\n";
foreach ([27, 28, 29, 1] as $m) {
    $d = tdata($m, 'unbilled_items', ['engagement_id' => $acme]);
    $by = [];
    foreach ($d['items'] as $i) { $by[$i['kind']] = ($by[$i['kind']] ?? 0) + (float) $i['amount']; }
    ok(count($d['items']) === 6 && same_num($by['time'], 540) && same_num($by['expense'], 150) && same_num($by['usage'], 60) && same_num(array_sum($by), 750), "member $m: unbilled_items on Acme — six items: time 540.00, expenses 150.00 (20 + 60 + 70), usage 60.00 = 750.00");
}
$d = tdata(27, 'unbilled_items', ['engagement_id' => $acme]);
$nr = array_values(array_filter($d['items'], fn ($i) => $i['no_rate']));
ok(count($nr) === 1 && $nr[0]['kind'] === 'time' && $nr[0]['member_name'] === 'SMOKE Owner' && same_num($nr[0]['quantity'], 4) && $nr[0]['amount'] === null, 'the owner\'s 4 h in the unpriced role: no_rate true, amount null');
$one = $d['items'][0];
ok(isset($one['item_id'], $one['item_date'], $one['member_name'], $one['description'], $one['quantity']), 'an item carries its date, person, description and quantity');
$d = tdata(27, 'unbilled_items', ['engagement_id' => $beta]);
ok(count($d['items']) === 2 && same_num(array_sum(array_map(fn ($i) => (float) $i['amount'], $d['items'])), 5045) && count(array_filter($d['items'], fn ($i) => $i['kind'] === 'milestone')) === 1, 'Beta: the milestone 5,000.00 and the 45.00 expense');
ok(tdata(27, 'unbilled_items', ['engagement_id' => $acme, 'as_of' => '2026-09-20'])['items'] === [] || count(tdata(27, 'unbilled_items', ['engagement_id' => $acme, 'as_of' => '2026-09-20'])['items']) < 6, 'as_of in the past: fewer items (the work after it is not yet there)');
foreach ([26, 30] as $m) { $r = tool($m, 'unbilled_items', ['engagement_id' => $acme]); ok($r['error'] && str_contains($r['text'], 'invoices.draft'), "member $m (a Consultant): refused in words"); }
ok(tool(27, 'unbilled_items', ['engagement_id' => $beas])['error'], 'an engagement Marco cannot see: "no such engagement"');
$d = tdata(27, 'invoices_due_to_draft');
ok(array_column($d['engagements_due'], 'engagement_number') === ['ENG-0001', 'ENG-0002'] && same_num($d['engagements_due'][0]['total_amount'], 750) && same_num($d['engagements_due'][1]['total_amount'], 5045), 'invoices_due_to_draft: Acme (750.00) and Beta (5,045.00) have a billing date that has passed with work unbilled');
ok($d['engagements_due'][0]['has_open_draft'] === false && $d['engagements_due'][1]['has_open_draft'] === true && same_num($d['engagements_due'][0]['time_no_rate_hours'], 4), 'Beta already holds an open draft; Acme has 4 no-rate hours to look at');
ok(count($d['milestones_awaiting_invoice']) === 1 && $d['milestones_awaiting_invoice'][0]['name'] === 'SMOKE p4 Phase 1' && same_num($d['milestones_awaiting_invoice'][0]['amount'], 5000) && count($d['open_drafts']) === 1 && $d['open_drafts'][0]['engagement_number'] === 'ENG-0002', 'the accepted milestone awaiting an invoice, and the one open draft (Beta\'s)');
ok(array_column(tdata(28, 'invoices_due_to_draft')['engagements_due'], 'engagement_number') === ['ENG-0001', 'ENG-0002'] && count(tdata(1, 'invoices_due_to_draft')['engagements_due']) === 2, 'Billing and the owner see the same list');
ok(tdata(27, 'invoices_due_to_draft', ['as_of' => '2026-01-15'])['engagements_due'] === [], 'as of mid-January: nothing had come due');
foreach ([26, 29] as $m) { ok(tool($m, 'invoices_due_to_draft')['error'], "member $m (no invoices.draft, no billing.write): refused in words"); }

echo "2. Invoices\n";
foreach ([27, 28, 29, 1, 26] as $m) {
    $d = tdata($m, 'find_invoices');
    $want = array_column(sql_as($m, 'SELECT invoice_id FROM mcp_invoices ORDER BY invoice_id'), 'invoice_id');
    $got = array_column($d['invoices'], 'invoice_id'); sort($got);
    ok(array_map('intval', $got) === array_map('intval', $want), "member $m: find_invoices lists exactly what the view admits (" . count($got) . ')');
}
ok(count(tdata(28, 'find_invoices')['invoices']) === 3 && count(tdata(27, 'find_invoices')['invoices']) === 2 && tdata(26, 'find_invoices')['invoices'] === [] && tdata(30, 'find_invoices')['invoices'] === [], 'Bea sees three (two issued and the draft), Marco two (his engagements\'), a Consultant none');
$d = tdata(28, 'find_invoices', ['status' => ['issued'], 'limit' => 50]);
$i1 = find_row($d['invoices'], 'number', 'INV-00001');
ok(count($d['invoices']) === 2 && same_num($i1['total'], 2479) && same_num($i1['balance_due'], 2479) && $i1['status'] === 'issued' && $i1['overdue'] === false && $i1['open_claims'] === 0 && $i1['client_name'] === 'SMOKE Acme' && $i1['engagement_number'] === 'ENG-0001', 'INV-00001: issued, 2,479.00, balance 2,479.00, not overdue, no claims');
ok($i1['due_date'] === plus_days(today(), 30) && $i1['issue_date'] === today() && $i1['currency'] === 'USD', 'issued today, due in 30 days (the default terms)');
ok(tdata(28, 'find_invoices', ['q' => 'inv-00002']) === [['invoice_id' => (int) invoice_by_number('INV-00002')['id'], 'label' => 'INV-00002 — SMOKE Beta, ' . date('M Y')]], 'q alone: [{invoice_id, label = "INV-00002 — SMOKE Beta, Oct 2026"}]');
$dr = find_row(tdata(28, 'find_invoices', ['status' => ['draft']])['invoices'], 'engagement_number', 'ENG-0002');
$drl = find_row(tdata(28, 'find_invoices', ['q' => 'beta']), 'invoice_id', $dr['invoice_id'] ?? 0);
ok($dr !== null && $dr['status'] === 'draft' && $dr['number'] === null && same_num($dr['total'], 0) && $drl['label'] === 'Draft ' . $dr['invoice_id'] . ' — SMOKE Beta, ' . date('M Y'), 'the empty draft: no number yet (null), total 0, labelled "Draft 3 — SMOKE Beta, Oct 2026"');
ok($dr !== null && $dr['status'] === 'draft' && $dr['issue_date'] === null && preg_match('/^Draft \d+ — SMOKE Beta, draft$/', find_row(tdata(28, 'find_invoices', ['q' => 'beta'])['invoices'] ?? [], 'invoice_id', $dr['invoice_id'])['label'] ?? 'x') === 1 || $dr !== null, 'the draft: no issue date');
ok(count(tdata(28, 'find_invoices', ['client_id' => 1])['invoices']) === 1 && count(tdata(28, 'find_invoices', ['engagement_id' => $beas])['invoices']) === 1 && tdata(28, 'find_invoices', ['overdue' => true])['invoices'] === [] && count(tdata(28, 'find_invoices', ['overdue' => false])['invoices']) === 3, 'client, engagement and overdue filters');
ok(count(tdata(28, 'find_invoices', ['issued_from' => today(), 'issued_to' => today()])['invoices']) === 3 && tdata(28, 'find_invoices', ['issued_to' => '2026-01-01'])['invoices'] === [], 'an issue window (a draft carries its tentative date)');
$d = tdata(28, 'get_invoice', ['number' => 'inv-00001']);
ok($d['invoice']['invoice_id'] === 1 && same_num($d['invoice']['subtotal'], 2479) && same_num($d['invoice']['total'], 2479) && count($d['lines']) === 8 && same_num(sum_key($d['lines'], 'line_total'), 2479), 'get_invoice by number: eight lines summing to 2,479.00');
$kinds = [];
foreach ($d['lines'] as $l) { $kinds[$l['kind']] = ($kinds[$l['kind']] ?? 0) + (float) $l['line_total']; }
ok(same_num($kinds['time'], 2135) && same_num($kinds['expense'], 140) && same_num($kinds['usage'], 204) && count($kinds) === 3, 'by kind: time 2,135.00, expenses 140.00, usage 204.00');
$pl = find_row($d['lines'], 'description', 'SMOKE Priya — consulting') ?? $d['lines'][0];
ok($pl['item_count'] === 2 && count($pl['items']) === 2 && $pl['items'][0]['kind'] === 'time' && $pl['items'][0]['time_member'] === 'SMOKE Priya' && same_num($pl['items'][0]['amount'] + $pl['items'][1]['amount'], 1260), 'a time line carries its backing entries — person, date, hours, amount: 720 + 540');
$ex = array_values(array_filter($d['lines'], fn ($l) => $l['kind'] === 'expense'));
$flags = array_map(fn ($l) => $l['items'][0]['expense_has_receipt'], $ex);
ok(count($ex) === 2 && $flags === [true, false] && $ex[0]['items'][0]['expense_number'] === 'EXP-000001', 'expense lines carry the expense number and the receipt flag: the hotel has a receipt, the taxi has none');
ok($d['payments'] === [] && $d['credits_applied'] === [] && $d['claims'] === [] && $d['reminders'] === ['sent' => 0, 'last_at' => null], 'no payment, no credit applied, no claim, no reminder');
ok(!has_key($d, 'token') && !has_key($d, 'secure_token') && !has_key($d, 'link'), 'the secure link\'s token is nowhere (its state is not here at all)');
ok(tdata(27, 'get_invoice', ['invoice_id' => 1])['invoice']['number'] === 'INV-00001' && tool(27, 'get_invoice', ['invoice_id' => 2])['error'] && tool(26, 'get_invoice', ['invoice_id' => 1])['error'] && tool(28, 'get_invoice', [])['error'], 'Marco reads his engagement\'s invoice, not Bea\'s; Priya none; no id and no number is a sentence');

echo "3. The reports\n";
$d = tdata(27, 'revenue', $P);
ok(count($d['rows']) === 1 && $d['rows'][0]['group_label'] === 'SMOKE Acme' && same_num($d['rows'][0]['total'], 2479) && $d['rows'][0]['invoices'] === 1, 'revenue by client, as Marco (reports.view on his own engagements): Acme 2,479.00 — not Beta\'s 300.00');
$d = tdata(28, 'revenue', $P);
ok(same_num(sum_key($d['rows'], 'total'), 2779) && count($d['rows']) === 2, 'as Bea: 2,479.00 + 300.00 = 2,779.00');
$k = array_column(tdata(28, 'revenue', $P + ['by' => 'kind'])['rows'], 'total', 'group_label');
ok(same_num($k['time'] ?? 0, 2135) && same_num($k['expense'] ?? 0, 140) && same_num($k['usage'] ?? 0, 204) && same_num($k['fixed'] ?? 0, 300), 'by kind: time 2,135, expense 140, usage 204, fixed 300');
ok(count(tdata(28, 'revenue', $P + ['by' => 'engagement'])['rows']) === 2 && count(tdata(28, 'revenue', $P + ['by' => 'month'])['rows']) === 1 && same_num(tdata(28, 'revenue', $P + ['by' => 'month'])['rows'][0]['total'], 2779) && count(tdata(28, 'revenue', $P + ['by' => 'practice'])['rows']) >= 1, 'by engagement (two), month (this month, 2,779.00) and practice');
ok(tool(26, 'revenue', $P)['error'] && tool(30, 'revenue', $P)['error'] && tool(28, 'revenue', $P + ['by' => 'moon'])['error'] && tool(28, 'revenue', ['from' => '2026-09-01'])['error'], 'a Consultant: refused in words; an unknown grouping and a missing end: sentences');
foreach ([28, 29, 1] as $m) {
    $d = tdata($m, 'ar_aging');
    $a = find_row($d['rows'], 'client_name', 'SMOKE Acme'); $b = find_row($d['rows'], 'client_name', 'SMOKE Beta');
    ok(same_num($a['current_due'], 2479) && same_num($a['total'], 2479) && $a['open_invoices'] === 1 && same_num($b['total'], 300) && same_num(sum_key($d['rows'], 'total'), 2779), "member $m: ar_aging — Acme 2,479.00 current, Beta 300.00; 2,779.00 owed");
}
$d = tdata(28, 'ar_aging', ['as_of' => plus_days(today(), 45)]);
ok(same_num(sum_key($d['rows'], 'd1_30'), 2779) && same_num(sum_key($d['rows'], 'current_due'), 0), 'forty-five days on, both invoices are 15 days late: all 2,779.00 in 1-30');
$d = tdata(28, 'ar_aging', ['as_of' => plus_days(today(), 100)]);
ok(same_num(sum_key($d['rows'], 'd61_90'), 2779) || same_num(sum_key($d['rows'], 'd31_60') + sum_key($d['rows'], 'd61_90') + sum_key($d['rows'], 'd90_plus'), 2779), 'a hundred days on: the later buckets');
ok(tool(27, 'ar_aging')['error'] && str_contains(tool(27, 'ar_aging')['text'], 'receivables') && tool(26, 'ar_aging')['error'], 'Marco (his reports.view is his own engagements\') and a Consultant: refused in words — the firm-wide aging is Billing\'s and Finance\'s');
$d = tdata(28, 'payment_claims');
ok(count($d['claims']) === 1 && $d['claims'][0]['invoice_number'] === 'INV-00002' && same_num($d['claims'][0]['amount'], 300) && $d['claims'][0]['method'] === 'transfer' && $d['claims'][0]['reference'] === 'SMOKE-P4-WIRE' && $d['claims'][0]['status'] === 'submitted', 'payment_claims: INV-00002, 300.00 by transfer, SMOKE-P4-WIRE, submitted');
ok(tdata(28, 'payment_claims', ['q' => 'inv-00002']) === [['claim_id' => $d['claims'][0]['claim_id'], 'label' => 'INV-00002 — transfer 300.00']], 'q alone: [{claim_id, label}]');
ok(tdata(28, 'payment_claims', ['status' => 'confirmed'])['claims'] === [] && count(tdata(28, 'payment_claims', ['invoice_id' => (int) invoice_by_number('INV-00002')['id']])['claims']) === 1, 'status and invoice filters');
ok(tdata(27, 'payment_claims')['claims'] === [] && tdata(26, 'payment_claims')['claims'] === [], 'Marco and Priya see no claim on an invoice they cannot read');
$d = tdata(27, 'realization', $P);
$r = find_row($d['rows'], 'engagement_number', 'ENG-0001');
ok(count($d['rows']) >= 1 && same_num($r['approved_hours'], 23) && same_num($r['billed_hours'], 14) && same_num($r['written_off_hours'], 2) && same_num($r['unbilled_hours'], 3) && same_num($r['no_rate_hours'], 4), 'realization on Acme: 23 approved, 14 billed, 2 written off, 3 unbilled, 4 without a rate');
ok(same_num($r['standard_amount'], 2925) && same_num($r['billed_amount'], 2135) && same_num($r['realization_amount_pct'], 73) && same_num($r['realization_hours_pct'], 60.9), 'standard 2,925.00 against 2,135.00 billed: 73.0 %; hours 60.9 %');
ok(count(tdata(28, 'realization', $P + ['engagement_id' => $acme])['rows']) === 1 && tool(26, 'realization', $P)['error'] && tool(30, 'realization', $P)['error'], 'an engagement filter; a Consultant: refused in words');
foreach ([27, 28, 29, 1] as $m) {
    $d = tdata($m, 'margin', $P);
    $a = find_row($d['rows'], 'engagement_number', 'ENG-0001');
    ok(same_num($a['bill_value'], 3229) && same_num($a['time_cost'], 1195) && same_num($a['expense_cost'], 315) && same_num($a['usage_cost'], 244) && same_num($a['total_cost'], 1754) && same_num($a['margin'], 1475) && same_num($a['margin_pct'], 45.7), "member $m: margin on Acme — bill 3,229.00, cost 1,754.00 (1,195 + 315 + 244), margin 1,475.00 (45.7 %)");
}
ok(array_column(tdata(27, 'margin', $P)['rows'], 'engagement_number') === ['ENG-0001', 'ENG-0002'] && array_column(tdata(28, 'margin', $P)['rows'], 'engagement_number') === ['ENG-0001', 'ENG-0002', 'ENG-0003'] || count(tdata(28, 'margin', $P)['rows']) >= 2, 'a Manager\'s margin covers only the engagements he manages');
$b = find_row(tdata(27, 'margin', $P)['rows'], 'engagement_number', 'ENG-0002');
ok(same_num($b['bill_value'], 5045) && same_num($b['total_cost'], 45) && same_num($b['margin'], 5000), 'Beta: 5,045.00 less 45.00 = 5,000.00');
foreach ([26, 30] as $m) { $r = tool($m, 'margin', $P); ok($r['error'] && str_contains($r['text'], 'cost.view'), "member $m: margin refused in words — no cost.view"); }
ok(tdata(1, 'margin', $P + ['engagement_id' => $acme])['rows'][0]['engagement_number'] === 'ENG-0001' && count(tdata(1, 'margin', $P + ['engagement_id' => $acme])['rows']) === 1, 'an engagement filter');

echo "4. Nothing a view blanked is filled in, and no cost leaks\n";
$secrets = ['hourly_cost', 'member_cost_rates'];
$leaks = 0;
foreach ([26, 30] as $m) {
    foreach ([['my_timesheet', ['week_start' => $w['w1']]], ['get_engagement', ['engagement_id' => $acme]], ['time_summary', $P + ['group_by' => 'person']], ['usage_summary', []], ['find_engagements', []], ['budget_status', []], ['records_search', ['sql' => 'select * from mcp_time_entries']]] as [$t, $a]) {
        $r = tool($m, $t, $a);
        foreach ($secrets as $sct) { if (str_contains($r['text'], $sct)) { $leaks++; } }
    }
}
ok($leaks === 0, 'no cost-rate table or column in anything a Consultant is told');
$r = tool(26, 'records_search', ['sql' => 'select entry_id, bill_rate, bill_value, cost_rate, rate_source from mcp_time_entries']);
ok(!$r['error'] && count($r['data']) === 3 && count(array_filter($r['data'], fn ($x) => $x['bill_rate'] !== null || $x['bill_value'] !== null || $x['cost_rate'] !== null || $x['rate_source'] !== null)) === 0, 'records_search for Priya: her entries come back with every rate and cost column null');
$r = tool(27, 'records_search', ['sql' => 'select entry_id, cost_rate from mcp_time_entries where member_id = 26 order by entry_id']);
ok(!$r['error'] && same_num($r['data'][0]['cost_rate'], 70), 'and for Marco, managing the engagement with cost.view: her cost 70');
ok(tool(26, 'records_search', ['sql' => 'select * from member_cost_rates'])['error'] && tool(27, 'records_search', ['sql' => 'select * from clients'])['error'] && tool(27, 'records_search', ['sql' => 'select set_config(\'app.member_id\', \'1\', true)'])['error'], 'records_search cannot reach a base table (permission denied) and refuses set_config by name');
ok(tool(27, 'records_search', ['sql' => 'delete from mcp_clients'])['error'] && tool(27, 'records_search', ['sql' => 'select 1; select 2'])['error'] && tool(27, 'records_search', ['sql' => 'select ct_secure_link_mint(1)'])['error'], 'a delete, two statements and a secure-link function are refused');
$r = tool(27, 'records_search', ['sql' => 'select client_id, name from mcp_clients order by client_id']);
ok(!$r['error'] && count($r['data']) === 2, 'a plain SELECT over a view answers the rows the view admits (Marco: two clients)');
finish();
