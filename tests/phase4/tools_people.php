<?php
/**
 * Proof — people, the vocabulary, rates, clients and engagements (find_members, find_departments, ct_settings, rate_cards, resolve_rate, find_clients, get_client, client_statement, find_engagements, get_engagement, engagement_staffing,
 * milestones, budget_status, budget_alerts), each as Priya (Consultant, staffed on Acme and Beta), Marco (Manager of Acme and Beta), Bea (Billing), Ann (Finance) and the owner. The world's hand-derived figures (tests/phase4/lib.php and slices 3, 6, 7):
 * the Standard card prices Consultant 180 and Analyst 125; Priya, Dana, Marco and the owner (in an unpriced role) are staffed on Acme (ENG-0001), Priya on Beta (ENG-0002); Acme's hours: Priya 4+3+3 = 10, Dana 5+2+2+6 = 15, the owner 4 = 29 of 400;
 * INV-00001 (Acme, 2,479.00) and a 250.00 credit note against it; Beta's AI cost is 40 % of the Researcher's two lines (100.00 + 30.335) = 40.00 + 12.13 = 52.13 of a 100.00 AI budget; Beta's milestone 5,000.00 accepted, unbilled.
 */
require __DIR__ . '/lib.php';
$w = p4_world();
$acme = $w['acme']; $beta = $w['beta']; $beas = $w['beas'];

echo "1. People\n";
$d = tdata(27, 'find_members', ['kind' => 'agent']);
$ids = array_column($d['members'], 'member_id');
ok(in_array(40, $ids, true) && in_array(41, $ids, true) && array_values(array_unique(array_column($d['members'], 'member_kind'))) === ['agent'] && !in_array(27, $ids, true), 'find_members kind=agent: the Clerk (40) and the expert (41) among the agents, every row marked an agent, no human');
$d = tdata(27, 'find_members', ['q' => 'dana']);
ok($d === [['member_id' => 30, 'display_name' => 'SMOKE Dana']], 'q alone answers the plain list the kernel resolves with: [{member_id, display_name}]');
$d = tdata(26, 'find_members', ['kind' => 'human']);
$mine = find_row($d['members'], 'member_id', 26); $his = find_row($d['members'], 'member_id', 27);
ok(count($d['members']) === 6 && $mine['email'] !== null && $his['email'] === null, 'a Consultant sees all six people by name; the email only on her own row (' . ($mine['email'] ?? 'null') . ' / ' . var_export($his['email'], true) . ')');
$viewRoles = array_column(sql_as(26, "SELECT member_id, roles FROM mcp_members WHERE member_id IN (28, 29)"), 'roles', 'member_id');
ok(in_array('billing', find_row($d['members'], 'member_id', 28)['roles'], true) && in_array('finance', find_row($d['members'], 'member_id', 29)['roles'], true) && count($viewRoles) === 2, 'roles ride along: Bea holds billing, Ann finance');
$d = tdata(27, 'find_members', ['department_id' => 7]);
ok(count($d['members']) >= 1 && in_array(27, array_column($d['members'], 'member_id'), true) || count($d['members']) === 0, 'department_id filters by membership');
$d = tdata(27, 'find_departments', ['q' => 'account']);
ok($d === [['department_id' => (int) one("SELECT id FROM departments WHERE name = 'Accounting'"), 'name' => 'Accounting']], 'find_departments q alone: [{department_id, name}] — Accounting');
$d = tdata(26, 'find_departments');
$names = array_column($d['departments'], 'name');
ok(in_array('AI Strategy', $names, true) && in_array('Accounting', $names, true) && has_key($d, 'manager_name'), 'a Consultant lists the practices too (the vocabulary is for every member)');
ok(tool(27, 'find_members', ['bogus' => 1])['error'] || tdata(27, 'find_members', ['bogus' => 1]) === null, 'an unknown argument is refused, not ignored');

echo "2. The settings and the vocabulary\n";
$d = tdata(26, 'ct_settings');
$titles = array_column($d['roles'], 'title'); sort($titles);
ok($titles === ['Analyst', 'Consultant', 'Principal', 'Project Manager', 'SMOKE Unpriced', 'Senior Consultant'], 'the consulting roles: the five seeds and the fixture\'s unpriced one');
ok(count($d['activities']) === 8 && count($d['categories']) === 8 && count($d['providers']) === 8, 'eight activity types, eight expense categories, eight usage providers (the seeds)');
$base = q('SELECT * FROM ct_settings WHERE id = 1')[0];
ok($d['settings']['base_currency'] === trim($base['base_currency']) && same_num($d['settings']['hours_increment'], $base['hours_increment']) && same_num($d['settings']['receipt_threshold'], $base['receipt_threshold']) && (int) $d['settings']['week_start_dow'] === (int) $base['week_start_dow'], 'the settings row is the database\'s: currency, increment, receipt threshold, week start');
ok(has_key($d, 'tax_rates') && !has_key($d, 'csv_mapping'), 'tax rates ride along; a provider\'s CSV mapping does not');
$meals = find_row($d['categories'], 'key', 'meals');
ok($meals !== null && array_key_exists('receipt_required_above', $meals) && array_key_exists('default_markup_pct', $meals), 'a category carries its receipt and markup overrides');
$d = tdata(26, 'ct_settings', ['part' => 'roles']);
ok(array_keys($d) === ['roles'], 'part=roles answers the roles only');
ok(tool(26, 'ct_settings', ['part' => 'nonsense'])['error'], 'an unknown part is refused in words');
$d = tdata(26, 'ct_settings', ['q' => 'analyst']);
ok(count($d) === 1 && $d[0]['title'] === 'Analyst' && isset($d[0]['consulting_role_id']) && !isset($d[0]['activity_type_id']), 'q alone: a flat list whose rows carry their OWN id field — the consulting role Analyst');
$d = tdata(26, 'ct_settings', ['q' => 'travel']);
$parts = array_column($d, 'part'); sort($parts);
ok($parts === ['activities', 'categories'] && isset(find_row($d, 'part', 'categories')['category_id']) && isset(find_row($d, 'part', 'activities')['activity_type_id']), '"travel" is an activity type AND an expense category: two rows, each with its own id field (the kernel filters by id_field)');
$d = tdata(26, 'ct_settings', ['q' => 'anthropic']);
ok(count($d) === 1 && isset($d[0]['provider_id']) && $d[0]['name'] === 'Anthropic', 'a usage provider resolves by name');

echo "3. Rates: bill rates need rates.view\n";
foreach ([26, 30] as $m) { $r = tool($m, 'rate_cards'); ok($r['error'] && str_contains($r['text'], 'rates.view'), "member $m (a Consultant/Analyst): rate_cards refused in words"); }
$d = tdata(27, 'rate_cards');
ok(count($d['rate_cards']) === 1 && $d['rate_cards'][0]['name'] === 'SMOKE Standard' && $d['rate_cards'][0]['rate_count'] === (int) one('SELECT count(*) FROM rate_card_rates WHERE rate_card_id = 1'), 'Marco: the one card, with its rate count');
$cid = $d['rate_cards'][0]['rate_card_id'];
$d = tdata(27, 'rate_cards', ['rate_card_id' => $cid]);
$by = array_column($d['rate_card']['rates'], 'bill_rate', 'role_title');
ok(same_num($by['Consultant'], 180) && same_num($by['Analyst'], 125) && count($by) === $d['rate_card']['rate_count'], 'one card in full: Consultant 180, Analyst 125 among its rates');
ok(tdata(27, 'rate_cards', ['q' => 'standard']) === [['rate_card_id' => $cid, 'name' => 'SMOKE Standard']], 'q alone: [{rate_card_id, name}]');
$r = tdata(27, 'resolve_rate', ['engagement_id' => $acme, 'member_id' => 26]);
ok(same_num($r['bill_rate'], 180) && $r['rate_source'] === 'rate_card' && $r['role_title'] === 'Consultant' && $r['member_name'] === 'SMOKE Priya', 'resolve_rate: Priya on Acme is 180 from the rate card, as a Consultant');
$r = tdata(27, 'resolve_rate', ['engagement_id' => $acme, 'member_id' => 30, 'on_date' => '2026-03-02']);
ok(same_num($r['bill_rate'], 125) && $r['rate_source'] === 'rate_card' && $r['role_title'] === 'Analyst' && $r['on_date'] === '2026-03-02', 'Dana on a past date: 125 as an Analyst');
$r = tdata(27, 'resolve_rate', ['engagement_id' => $acme, 'member_id' => 1]);
ok($r['bill_rate'] === null && $r['rate_source'] === 'none' && $r['role_title'] === 'SMOKE Unpriced', 'the owner\'s role has no price: bill_rate null, rate_source none');
$r = tdata(27, 'resolve_rate', ['engagement_id' => $acme, 'member_id' => 29]);
ok($r['bill_rate'] === null && $r['rate_source'] === 'none' && $r['role_title'] === null, 'a person not staffed at all: none');
ok(tool(26, 'resolve_rate', ['engagement_id' => $acme, 'member_id' => 26])['error'], 'Priya (no rates.view) cannot read even her own rate');
ok(tool(27, 'resolve_rate', ['engagement_id' => $beas, 'member_id' => 28])['error'], 'Marco cannot resolve a rate on an engagement he cannot see (Bea\'s)');
ok(tool(27, 'resolve_rate', ['engagement_id' => $acme, 'member_id' => 26, 'on_date' => 'soon'])['error'], 'a bad date is a sentence');

echo "4. Clients: the views decide\n";
foreach ([26, 27, 28, 29, 1] as $m) {
    $d = tdata($m, 'find_clients');
    $want = array_column(sql_as($m, 'SELECT client_id FROM mcp_clients WHERE archived_at IS NULL ORDER BY client_id'), 'client_id');
    $got = array_column($d['clients'], 'client_id'); sort($got);
    ok(array_map('intval', $got) === array_map('intval', $want) && count($got) >= 1, "member $m: find_clients lists exactly the clients the view admits (" . count($got) . ')');
}
$acmeRow = find_row(tdata(26, 'find_clients')['clients'], 'client_id', 1);
ok($acmeRow['email'] === null && $acmeRow['billing_address'] === null && $acmeRow['tax_id'] === null, 'a Consultant sees Acme with no contact details (null, not omitted)');
$base = q("SELECT email, billing_address FROM clients WHERE id = 1")[0];
$bea = find_row(tdata(28, 'find_clients')['clients'], 'client_id', 1);
ok(($base['email'] === null) === ($bea['email'] === null) && $bea['email'] === $base['email'], 'Billing sees the contact details the base row holds (' . var_export($bea['email'], true) . ')');
ok(tdata(28, 'find_clients', ['q' => 'acme']) === [['client_id' => 1, 'name' => 'SMOKE Acme']], 'q alone: [{client_id, name}]');
ok(count(tdata(1, 'find_clients', ['q' => 'smoke'])) >= 2, 'a word matches at the start of a word in the name');
$d = tdata(27, 'get_client', ['client_id' => 1]);
ok($d['client']['name'] === 'SMOKE Acme' && count($d['engagements']) === 1 && $d['engagements'][0]['number'] === 'ENG-0001' && $d['engagements'][0]['label'] === 'ENG-0001 — SMOKE Time Acme', 'get_client: Acme and its one engagement, labelled');
ok(array_column($d['open_invoices'], 'number') === ['INV-00001'] && same_num($d['balance_by_currency']['USD'], 2479) && $d['statement_last_lines'] === [], 'a Manager (invoices.draft on a staffed engagement) sees the open invoice and the balance, but no statement (receivables are Billing\'s and Finance\'s)');
$p = tdata(26, 'get_client', ['client_id' => 1]);
ok($p['open_invoices'] === [] && $p['balance_by_currency'] === [] && $p['statement_last_lines'] === [] && $p['contacts'] === [], 'a Consultant sees the client and its engagements, no invoice, no balance, no statement');
$d = tdata(28, 'get_client', ['client_id' => 1]);
$open = q("SELECT sum(balance_due) AS b, count(*) AS n FROM invoices WHERE client_id = 1 AND status IN ('issued','sent','viewed','partially_paid')")[0];
ok(count($d['open_invoices']) === (int) $open['n'] && same_num($d['balance_by_currency']['USD'] ?? 0, $open['b']) && $d['open_invoices'][0]['number'] === 'INV-00001', 'Billing: the open invoice INV-00001 and the balance by currency = the database\'s (' . ($d['balance_by_currency']['USD'] ?? '-') . ')');
ok(count($d['statement_last_lines']) >= 2, 'and the last statement lines');
ok(tool(26, 'get_client', ['client_id' => 3])['error'] || count(tdata(26, 'find_clients')['clients']) === 2, 'Priya cannot get a client she is not staffed for (or none exists to get)');
$beaClient = (int) one("SELECT id FROM clients WHERE name = 'SMOKE Beta'");
ok(tool(26, 'get_client', ['client_id' => 9999])['error'], 'an unknown client is a sentence');
$r = tool(27, 'client_statement', ['client_id' => 1]);
ok($r['error'] && str_contains($r['text'], 'receivables'), 'client_statement: refused to a Manager in words');
ok(tool(26, 'client_statement', ['client_id' => 1])['error'], '...and to a Consultant');
foreach ([28, 29, 1] as $m) {
    $d = tdata($m, 'client_statement', ['client_id' => 1]);
    $inv = find_row($d['lines'], 'number', 'INV-00001');
    $cnl = array_values(array_filter($d['lines'], fn ($l) => $l['kind'] === 'credit_note'));
    $last = end($d['lines']);
    ok($inv !== null && same_num($inv['charge'], 2479) && count($cnl) === 1 && same_num($cnl[0]['credit'], 250) && same_num($last['running_balance'], sum_key($d['lines'], 'charge') - sum_key($d['lines'], 'credit')), "member $m: the statement — INV-00001 charge 2,479.00, a credit note 250.00, the running balance is charges less credits");
}
$d = tdata(28, 'client_statement', ['client_id' => 1, 'from' => '2999-01-01']);
ok($d['lines'] === [], 'a period with nothing in it answers no lines');

echo "5. Engagements\n";
foreach ([26, 27, 28, 29, 1, 30] as $m) {
    $d = tdata($m, 'find_engagements', ['limit' => 100]);
    $want = array_column(sql_as($m, 'SELECT engagement_id FROM mcp_engagements ORDER BY engagement_id'), 'engagement_id');
    $got = array_column($d['engagements'], 'engagement_id'); sort($got);
    ok(array_map('intval', $got) === array_map('intval', $want), "member $m: find_engagements lists exactly what the view admits (" . count($got) . ')');
}
ok(count(tdata(1, 'find_engagements')['engagements']) === 3 && count(tdata(26, 'find_engagements')['engagements']) === 2, 'the owner sees three engagements, Priya two (she is not on Bea\'s)');
ok(tdata(26, 'find_engagements', ['q' => 'beta']) === [['engagement_id' => $beta, 'label' => 'ENG-0002 — SMOKE Time Beta']], 'q alone: [{engagement_id, label = "ENG-0002 — SMOKE Time Beta"}]');
$d = tdata(27, 'find_engagements', ['kind' => 'fixed_bid']);
ok(array_column($d['engagements'], 'number') === ['ENG-0002'], 'kind=fixed_bid: Beta');
$d = tdata(27, 'find_engagements', ['manager' => 'me']);
ok(count($d['engagements']) === 2 && array_unique(array_column($d['engagements'], 'i_manage')) === [true], 'manager=me: Marco\'s two, each marked i_manage');
$d = tdata(26, 'find_engagements', ['mine' => true]);
ok(count($d['engagements']) === 2 && array_unique(array_column($d['engagements'], 'i_am_staffed')) === [true] && array_unique(array_column($d['engagements'], 'i_manage')) === [false], 'mine=true for Priya: two, staffed, not managed');
ok(tdata(1, 'find_engagements', ['manager' => '28'])['engagements'][0]['number'] === 'ENG-0003', 'manager=<id>: Bea\'s ENG-0003');
ok(tdata(27, 'find_engagements', ['status' => ['closed']])['engagements'] === [] && count(tdata(27, 'find_engagements', ['status' => ['active']])['engagements']) === 2, 'status filters');
ok(tdata(27, 'find_engagements', ['ending_within_days' => 30])['engagements'] === [], 'ending_within_days: none of the world ends in the next month');
$d = tdata(26, 'find_engagements', ['client_id' => 1]);
ok(count($d['engagements']) === 1 && $d['engagements'][0]['staffed_count'] === 4 && $d['engagements'][0]['manager_name'] === 'SMOKE Marco', 'client_id filters; a row carries staffed_count and the manager\'s name');

echo "6. get_engagement\n";
$d = tdata(27, 'get_engagement', ['engagement_id' => $acme]);
$e = $d['engagement'];
ok($e['number'] === 'ENG-0001' && $e['kind'] === 'time_and_materials' && $e['i_manage'] === true && $e['manager_name'] === 'SMOKE Marco' && $e['budget_hours'] == 400 && $e['budget_amount'] == 60000, 'Marco: every field of Acme');
ok(array_column($d['staffing'], 'member_name') === ['SMOKE Dana', 'SMOKE Marco', 'SMOKE Owner', 'SMOKE Priya'] && array_unique(array_column($d['staffing'], 'is_current')) === [true], 'the staffing: four people, all current');
ok($d['role_rates'] === [] && $d['milestones'] === [] && $d['open_alerts'] === [] && $d['documents'] === [], 'role rates (he holds rates.view: an empty list, not null), milestones, alerts and documents are empty lists');
ok($d['budget']['hours_used'] == 29 && same_num($d['budget']['hours_pct'], 7.3) && same_num($d['budget']['invoiced_total'], 2479), 'the budget: 29 of 400 hours (7.3 %), 2,479.00 invoiced');
$p = tdata(26, 'get_engagement', ['engagement_id' => $acme]);
ok($p['role_rates'] === null && array_unique(array_column($p['staffing'], 'bill_rate_override')) === [null] && $p['engagement']['rate_card_id'] === null, 'Priya: role rates null (no rates.view); overrides and the rate card null');
ok(!has_key($p, 'hourly_cost') && !has_key($p, 'cost_rate'), 'no cost rate anywhere in the answer');
$b = tdata(27, 'get_engagement', ['number' => 'eng-0002']);
ok($b['engagement']['engagement_id'] === $beta && $b['budget']['ai_budget_amount'] == 100 && same_num($b['budget']['ai_cost'], 52.13) && same_num($b['budget']['ai_pct'], 52.1), 'by number: Beta; Marco (usage.write) sees the AI budget 100.00, its cost 52.13 (40 % of 130.335) and 52.1 %');
ok(count($b['milestones']) === 1 && $b['milestones'][0]['name'] === 'SMOKE p4 Phase 1' && $b['milestones'][0]['status'] === 'accepted' && $b['milestones'][0]['billed'] === false && same_num($b['milestones'][0]['amount'], 5000), 'Beta\'s milestone: accepted, unbilled, 5,000.00');
$bp = tdata(26, 'get_engagement', ['engagement_id' => $beta]);
ok($bp['budget']['ai_budget_amount'] === 'set' && $bp['budget']['ai_cost'] === null && $bp['budget']['ai_pct'] === null && $bp['engagement']['ai_budget_amount'] === 'set', 'Priya: the AI budget is only "set" — a cost, never shown (the amount, the cost and the percentage are null)');
ok(tool(26, 'get_engagement', ['engagement_id' => $beas])['error'] && tool(27, 'get_engagement', ['number' => 'ENG-0003'])['error'], 'an engagement the caller may not see is "no such engagement" — never a 403');
ok(tool(27, 'get_engagement', [])['error'], 'no id and no number: a sentence');

echo "7. Staffing, milestones, budgets, alerts\n";
$d = tdata(27, 'engagement_staffing', ['engagement_id' => $acme]);
ok(count($d['staffing']) === 4 && $d['on_date'] === gmdate('Y-m-d'), 'engagement_staffing: four people today');
$d = tdata(27, 'engagement_staffing', ['member_id' => 26]);
ok(array_column($d['staffing'], 'engagement_number') === ['ENG-0001', 'ENG-0002'], 'one person\'s staffing: Priya is on Acme and Beta');
ok(tdata(27, 'engagement_staffing', ['engagement_id' => $acme, 'on_date' => '2026-01-01'])['staffing'] === [] && count(tdata(27, 'engagement_staffing', ['engagement_id' => $acme, 'on_date' => '2026-01-01', 'include_ended' => true])['staffing']) === 4, 'before the staffing began: nobody; include_ended: all four');
ok(tool(27, 'engagement_staffing', [])['error'], 'neither an engagement nor a person: a sentence');
$d = tdata(27, 'engagement_staffing', ['engagement_id' => $acme]);
ok(array_unique(array_column($d['staffing'], 'bill_rate_override')) === [null], 'overrides are null when none was set');
$d = tdata(27, 'milestones');
ok(count($d['milestones']) === 1 && $d['milestones'][0]['engagement_number'] === 'ENG-0002' && $d['milestones'][0]['currency'] === 'USD', 'milestones: the one, with its engagement');
ok(tdata(27, 'milestones', ['q' => 'phase']) === [['milestone_id' => $d['milestones'][0]['milestone_id'], 'name' => 'SMOKE p4 Phase 1']], 'q alone: [{milestone_id, name}]');
ok(count(tdata(27, 'milestones', ['unbilled_accepted' => true])['milestones']) === 1 && tdata(27, 'milestones', ['status' => ['planned']])['milestones'] === [] && tdata(27, 'milestones', ['engagement_id' => $acme])['milestones'] === [], 'unbilled_accepted, status and engagement filters');
ok(count(tdata(27, 'milestones', ['due_before' => gmdate('Y-m-d', time() + 20 * 86400)])['milestones']) === 1 && tdata(27, 'milestones', ['due_before' => '2020-01-01'])['milestones'] === [], 'due_before: due in ten days is before twenty, not before 2020');
$d = tdata(27, 'budget_status');
ok(array_column($d['budgets'], 'engagement_number') === ['ENG-0001', 'ENG-0002'], 'budget_status with no argument: every active engagement Marco may see');
ok(array_column(tdata(27, 'budget_status', ['over_pct' => 50])['budgets'], 'engagement_number') === ['ENG-0002'] && tdata(27, 'budget_status', ['over_pct' => 99])['budgets'] === [], 'over_pct 50: only Beta (AI at 52.1 %); 99: none');
ok(array_column(tdata(26, 'budget_status', ['over_pct' => 50])['budgets'], 'engagement_number') === [], 'over_pct for Priya ignores the AI figure she may not see: none at or over 50');
ok(tool(26, 'budget_status', ['engagement_id' => $beas])['error'], 'budget_status of an engagement she cannot see: a sentence');
$one = tdata(27, 'budget_status', ['engagement_id' => $acme]);
ok(count($one['budgets']) === 1 && $one['budgets'][0]['client_name'] === 'SMOKE Acme' && $one['budgets'][0]['hours_used'] == 29, 'one engagement: Acme, 29 hours');
ok(tdata(26, 'budget_alerts')['alerts'] === [] && tdata(27, 'budget_alerts')['alerts'] === [], 'budget_alerts: none written (a Consultant\'s view of alerts is empty by the view\'s rule)');
$alert = null;
q("INSERT INTO budget_alerts (engagement_id, kind, threshold_pct, value_at, budget_at) VALUES (:e, 'ai', 50, 52.13, 100)", ['e' => $beta]);
$d = tdata(27, 'budget_alerts');
ok(count($d['alerts']) === 1 && $d['alerts'][0]['engagement_number'] === 'ENG-0002' && $d['alerts'][0]['kind'] === 'ai' && same_num($d['alerts'][0]['value_at'], 52.13), 'an AI alert written: Marco (manager, usage.write) sees it with its values');
ok(tdata(26, 'budget_alerts')['alerts'] === [], 'Priya sees none (the view needs engagements.manage or reports.view)');
q("DELETE FROM budget_alerts WHERE engagement_id = :e AND kind = 'ai' AND threshold_pct = 50", ['e' => $beta]);
finish();
