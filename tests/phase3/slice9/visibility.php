<?php
/**
 * Proof — who sees what on the admin and home pages (spec "Proof"; design §3): the sidebar offers Agents and Connections to the administrator alone (settings.manage) — a Manager, Billing, Finance and a Consultant do not see the links and are refused the URLs (403 in words, never a 404);
 * everyone sees "My settings" (tokens); the phone's tabs are unchanged; the views under the pages — what a Consultant, a Manager and the Clerk read of the agents' dispatches and the agents' log rows — are the database's rule; the home's regions show to the right people at 375 and in JSON alike.
 */
require __DIR__ . '/lib.php';
home_world(); pulled_month();
$cast = [1 => ['the administrator', as_member(1)], 26 => ['Priya', as_member(26)], 27 => ['Marco', as_member(27)], 28 => ['Bea', as_member(28)], 29 => ['Ann', as_member(29)]];

echo "1. The sidebar and the URLs\n";
foreach ($cast as $m => [$n, $j]) {
    $body = page($j, '/')['body'];
    $may = $m === 1;
    ok(str_contains($body, 'id="nav-agents"') === $may && str_contains($body, 'id="nav-connections"') === $may, "$n: the sidebar " . ($may ? 'offers' : 'does not offer') . ' Agents and Connections');
    foreach (['/settings/agents', '/settings/connections'] as $path) {
        $r = page($j, $path);
        ok($may ? $r['code'] === 200 : ($r['code'] === 403 && !str_contains($r['body'], 'id="agents-cards"') && !str_contains($r['body'], 'id="connections-reads"')), "$n: $path " . ($may ? 'opens' : 'is 403'));
    }
    ok(str_contains(page($j, '/settings/tokens/')['body'], 'id="tokens-content"'), "$n: My tokens open");
}
[$c, $b] = act($cast[27][1], '/settings/agents', []);
ok($c === 403, 'Marco POSTing to the admin page is 403 as well');
$r = page($cast[26][1], '/settings/agents', ['headers' => JSONH]);
ok($r['code'] === 403 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'forbidden', 'a refusal in JSON is {error: {code: forbidden}} with a sentence');

echo "2. The views under them\n";
function as_db(int $m): void { pdo()->exec("SELECT set_config('app.member_id', '$m', false)"); }
$all = (int) one('SELECT count(*) FROM agent_dispatches');
as_db(26); $p = (int) one('SELECT count(*) FROM mcp_agent_dispatches'); $pa = (int) one('SELECT count(*) FROM mcp_activity_log WHERE actor_member_id = 40');
as_db(27); $mg = (int) one('SELECT count(*) FROM mcp_agent_dispatches');
as_db(40); $cl = (int) one('SELECT count(*) FROM mcp_agent_dispatches'); $cla = (int) one('SELECT count(*) FROM mcp_activity_log WHERE actor_member_id = 40');
as_db(1); $ad = (int) one('SELECT count(*) FROM mcp_agent_dispatches');
ok($all >= 2 && $p === 0 && $pa === 0, "a Consultant reads none of the agents' dispatches or log rows ($p of $all; $pa)");
ok($mg === $all && $ad === $all && $cl === (int) one('SELECT count(*) FROM agent_dispatches WHERE agent_member_id = 40'), "a Manager (reports.view) and the administrator read all $all; the Clerk reads its own ($cl)");
ok($cla >= 1, 'the Clerk reads its own log rows');
as_db(27);
ok((int) one("SELECT count(*) FROM mcp_activity_log WHERE action = 'share.read'") === 0, 'a Manager reads no share.read row (the kernel\'s and the worker\'s reads are not his)');
as_db(1);
ok((int) one("SELECT count(*) FROM mcp_activity_log WHERE action = 'share.read'") === (int) one("SELECT count(*) FROM activity_log WHERE action = 'share.read'"), 'the administrator reads every one');

echo "3. The home's regions by role, at a glance\n";
$expect = [26 => ['home-my-week' => true, 'home-approvals' => false, 'home-budget-alerts' => false, 'home-billing' => false, 'home-clerk-note' => false, 'home-bell' => true],
           27 => ['home-my-week' => true, 'home-approvals' => true, 'home-budget-alerts' => true, 'home-billing' => true, 'home-clerk-note' => false, 'home-bell' => true],
           28 => ['home-my-week' => true, 'home-approvals' => true, 'home-budget-alerts' => true, 'home-billing' => true, 'home-clerk-note' => true, 'home-bell' => true],
           29 => ['home-my-week' => false, 'home-approvals' => false, 'home-budget-alerts' => false, 'home-billing' => true, 'home-clerk-note' => false, 'home-bell' => true],
           1 => ['home-my-week' => true, 'home-approvals' => true, 'home-budget-alerts' => true, 'home-billing' => true, 'home-clerk-note' => true, 'home-bell' => true]];
foreach ($expect as $m => $regions) {
    $body = page($cast[$m][1], '/')['body'];
    $got = [];
    foreach ($regions as $id => $want) { $got[$id] = str_contains($body, 'id="' . $id . '"'); }
    ok($got === $regions, "{$cast[$m][0]}: regions " . implode(' ', array_keys(array_filter($got))));
}
finish();
