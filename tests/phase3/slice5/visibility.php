<?php
/** Proof — who sees (spec "Proof", 5): Priya nothing of /usage (403, the views answer her no row); Marco his engagements' charges with cost, an engagement he is only staffed on without the cost; Bea all; Ann (Finance) reads through the view but has no screen; an unseen charge answers 404 on every handler. */
require __DIR__ . '/lib.php';
[$acme, $beta] = usage_world();
$priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29); $dana = as_member(30);

echo "1. A Consultant sees nothing\n";
foreach (['/usage/', '/usage/new', '/usage/import', '/usage/os', '/settings/allocation-rules/', '/settings/allocation-rules/new', '/settings/schedules/', '/settings/schedules/new'] as $path) {
    $r = page($priya, $path);
    ok($r['code'] === 403 && str_contains($r['body'], 'You may not record usage charges.'), "Priya: $path → 403 in words");
}
pdo()->exec("SELECT set_config('app.member_id', '26', false)");
foreach (['mcp_usage_charges', 'mcp_usage_imports', 'mcp_allocation_rules', 'mcp_recurring_charges', 'mcp_ai_statements', 'mcp_ai_statement_lines', 'mcp_ai_statement_allocations'] as $view) {
    ok((int) one("SELECT count(*) FROM $view") === 0, "the view $view answers Priya no row");
}
[$c, $b] = draft_charge($marco, $acme, ['description' => 'SMOKE visibility seed', 'unit_cost' => '1']);
$some = (int) ($b['record_id'] ?? 0);
ok($some > 0 && page($priya, '/usage/' . $some)['code'] === 403 && page($dana, '/usage/' . $some)['code'] === 403, 'a charge\'s page: 403 for the Consultants (the right is checked before the record)');

echo "2. Bea's engagement: Marco does not see its charges\n";
[$c, $b] = act($bea, '/engagements/save.php', ['client' => (int) one("SELECT id FROM clients WHERE name = 'SMOKE Beta'"), 'name' => 'SMOKE Beas visibility eng', 'kind' => 'time_and_materials', 'start_date' => '2026-01-05', 'manager' => 28, 'start_now' => 'yes']);
$beas = (int) $b['record_id'];
[$c, $b] = draft_charge($bea, $beas, ['description' => 'SMOKE Bea charge', 'unit_cost' => '77']);
$bc = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $bc > 0, 'Bea drafts a 77.00 charge on her engagement');
[$c, $b] = draft_charge($marco, $acme, ['description' => 'SMOKE Marco charge', 'unit_cost' => '33']);
$mc = (int) $b['record_id'];
[$c, $d] = screen($marco, '/usage/');
$ids = array_column($d['charges'], 'usage_charge_id');
ok($c === 200 && in_array($mc, $ids, true) && !in_array($bc, $ids, true), 'Marco\'s list has his engagement\'s charge and not Bea\'s');
ok(array_unique(array_column(array_column($d['charges'], 'engagement'), 'number')) !== [] && !in_array((string) one('SELECT number FROM engagements WHERE id = :e', ['e' => $beas]), array_column(array_column($d['charges'], 'engagement'), 'number'), true), 'no row of her engagement');
$mine = array_values(array_filter($d['charges'], fn ($x) => $x['usage_charge_id'] === $mc))[0];
ok($mine['cost_amount'] === '33.00' && $mine['unit_cost'] === '33.000000', 'Marco reads the cost of his own engagement\'s charge (cost.view on what he manages)');
foreach (['/usage/' . $bc, '/usage/' . $bc . '/edit'] as $path) {
    ok(page($marco, $path)['code'] === 404, "Marco: $path → 404 (an unseen charge does not exist to him)");
}
foreach (['save' => ['charge' => $bc, 'description' => 'x'], 'approve' => ['charge' => $bc], 'void' => ['charge' => $bc, 'reason' => 'x'], 'delete' => ['charge' => $bc]] as $h => $form) {
    [$c, $b] = act($marco, "/usage/$h.php", $form);
    ok($c === 404, "Marco: /usage/$h.php on Bea's charge → 404");
}
[$c, $b] = act($marco, '/usage/save.php', ['engagement' => $beas, 'kind' => 'hosting', 'description' => 'x', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'unit_cost' => '1']);
ok($c === 404, 'and a new charge on her engagement → 404');
ok((int) one('SELECT count(*) FROM usage_charges WHERE description = \'x\'') === 0, 'nothing was made');
[$c, $d] = screen($bea, '/usage/');
$ids = array_column($d['charges'], 'usage_charge_id');
ok($c === 200 && in_array($mc, $ids, true) && in_array($bc, $ids, true) && count($ids) === (int) one('SELECT count(*) FROM usage_charges'), 'Bea sees every charge (Billing sees all)');
$totals = array_column($d['totals'], null, 'currency');
ok($totals['USD']['cost_amount'] !== null && (float) $totals['USD']['billable_amount'] > 0, 'with the totals per currency, cost included');

echo "3. Staffed, not managing: the charge without its cost\n";
[$c, $b] = act($bea, '/engagements/staffing/save.php', ['engagement' => $beas, 'member' => 27, 'role' => role('consultant'), 'valid_from' => '2026-01-05']);
ok($c === 200, 'Bea staffs Marco on her engagement');
[$c, $d] = screen($marco, '/usage/');
$seen = array_values(array_filter($d['charges'], fn ($x) => $x['usage_charge_id'] === $bc))[0] ?? null;
ok($seen !== null && $seen['billable_amount'] === '77.00' && $seen['cost_amount'] === null && $seen['unit_cost'] === null, 'now Marco sees Bea\'s charge, with the billable amount and NO cost (the view hides it: he does not manage the engagement)');
$body = page($marco, '/usage/' . $bc)['body'];
ok(str_contains($body, 'id="usage-money-cost">Cost —') && !str_contains($body, '77.00 USD</div><div class="fs-12 text-muted" id="usage-money-cost">Cost 77'), 'its page shows "Cost —"');
[$c, $d] = screen($marco, '/usage/' . $bc);
ok($d['may']['approve'] === false && $d['may']['void'] === false && $d['may']['edit'] === false && $d['charge']['cost_amount'] === null, 'and offers him nothing: he does not manage it');
[$c, $b] = act($marco, '/usage/approve.php', ['charge' => $bc]);
ok($c === 403 && msg($b) === 'You do not manage ' . (string) one('SELECT number FROM engagements WHERE id = :e', ['e' => $beas]) . '.', 'approving it: 403 "You do not manage ENG-…"');
[$c, $b] = act($marco, '/usage/void.php', ['charge' => $bc, 'reason' => 'x']);
ok($c === 403, 'voiding it: 403');
[$c, $b] = act($marco, '/usage/save.php', ['charge' => $bc, 'description' => 'x']);
ok($c === 403, 'changing it: 403');
$body = page($marco, '/usage/?engagement=' . $beas)['body'];
ok(str_contains($body, 'id="charge-row-' . $bc . '-cost">—<') && str_contains($body, 'id="usage-total-USD"'), 'the list shows "—" for the cost');
[$c, $d] = screen($marco, '/usage/?engagement=' . $beas);
ok($d['totals'][0]['cost_amount'] === null, 'and the total cost is unknown (null), not a partial sum');

echo "4. Finance reads through the view, has no screen\n";
ledger_fixture();
act($marco, '/usage/os/pull.php', []);
clear_ledger();
ok(page($ann, '/usage/')['code'] === 403 && page($ann, '/usage/os')['code'] === 403, 'Ann: the screens answer 403 (Finance holds no usage.write)');
pdo()->exec("SELECT set_config('app.member_id', '29', false)");
$all = (int) one('SELECT count(*) FROM usage_charges');
ok((int) one('SELECT count(*) FROM mcp_usage_charges') === $all && (int) one('SELECT count(*) FROM mcp_usage_charges WHERE cost_amount IS NULL') === 0, 'but the view shows her every charge with its cost (cost.view everywhere)');
ok((int) one('SELECT count(*) FROM mcp_ai_statements') === 0 && (int) one('SELECT count(*) FROM mcp_allocation_rules') === 0, 'the statement, the rules and the schedules need usage.write: none for her');
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
ok((int) one('SELECT count(*) FROM mcp_ai_statements') >= 2 && (int) one('SELECT count(*) FROM mcp_ai_statement_lines') >= 3, 'Marco reads the statement and its lines (usage.write)');
pdo()->exec("SELECT set_config('app.member_id', '1', false)");

echo "5. Rules, schedules and the statement: gated by the engagement they act on\n";
[$c, $b] = act($bea, '/settings/allocation-rules/save.php', ['name' => 'SMOKE Bea rule', 'engagement' => $beas, 'agent' => 555, 'valid_from' => '2026-01-01']);
$br = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $br > 0, 'Bea adds a rule to her engagement');
[$c, $b] = act($marco, '/settings/allocation-rules/save.php', ['rule' => $br, 'share_pct' => '10']);
ok($c === 403, 'Marco changing it: 403 (he sees the engagement, he does not manage it)');
[$c, $b] = act($marco, '/settings/allocation-rules/delete.php', ['rule' => $br]);
ok($c === 403, 'deleting it: 403');
[$c, $d] = screen($marco, '/settings/allocation-rules/');
ok($c === 200 && in_array($br, array_column($d['rules'], 'rule_id'), true), 'the rules list is the view\'s: every rule is readable by a usage.write holder (it carries no cost)');
[$c, $b] = act($marco, '/settings/allocation-rules/save.php', ['name' => 'SMOKE onto hers', 'engagement' => $beas, 'agent' => 556, 'valid_from' => '2026-01-01']);
ok($c === 403, 'and Marco may not point a rule at an engagement he does not manage: 403');
finish();
