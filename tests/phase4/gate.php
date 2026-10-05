<?php
/**
 * Proof — the door. Who gets in and what they are offered: no token, a forged, an expired one -> 401; a person's own token, and the command bar's action token -> every tool of the surface and no other; an agent's RUN token ->
 * exactly the tools the kernel's run-facts call grants on THIS endpoint (fail closed: facts that say invalid, facts for another application, a kernel that cannot be reached, an endpoint the agent was not granted); an agent the
 * directory has not admitted is admitted at first contact only when the kernel vouches for it; an evaluation run may read; the kernel's own token reaches app_roles and the six shares only. Servers: records :8304, activity :8305,
 * records-with-a-dead-kernel :8310.
 */
require __DIR__ . '/lib.php';
p4_world();
$run = fn () => random_int(700000, 799999);      // a fresh run id every time: the servers cache a run's facts
$S = surface();

echo "1. Tokens that do not get in\n";
foreach ([REC, ACT] as $port) {
    $n = $port === REC ? 'records' : 'activity';
    ok(mcp_tools($port, '') === null, "$n: no token -> 401");
    ok(mcp_tools($port, 'mcp_' . str_repeat('0', 48)) === null, "$n: a made-up mcp_ token -> 401");
    $t = rtoken(951, $run());
    ok(mcp_tools($port, substr($t, 0, -1) . (substr($t, -1) === '0' ? '1' : '0')) === null, "$n: a run token with a bad signature -> 401");
    ok(mcp_tools($port, rtoken(951, $run(), -5)) === null, "$n: an expired run token -> 401");
    ok(mcp_tools($port, atoken(30, -5)) === null, "$n: an expired action token -> 401");
    ok(mcp_tools($port, atoken(9999)) === null, "$n: an action token for a member the mirror does not know -> 401 (never created)");
    ok(mcp_tools($port, ktoken('someone_else')) === null, "$n: the kernel's token for ANOTHER application -> 401");
    ok(mcp_tools($port, ktoken('consultant_tracking', -5)) === null, "$n: an expired kernel token -> 401");
}
ok(one('SELECT count(*) FROM members WHERE id = 9999') == 0, 'and the unknown member was not created');

echo "2. People: the whole surface, and nothing it does not name\n";
$rec = mcp_tools(REC, ptoken(27));
ok($rec === $S['records'] && count($rec) === 48, 'Marco is offered exactly the surface\'s records tools: ' . count($rec ?? []) . ' (46 named + records_search + app_roles)');
$act = mcp_tools(ACT, ptoken(27));
ok($act === $S['activity'] && count($act) === 6, 'and exactly the 6 activity tools');
ok(count($S['shares']) === 6, 'the surface marks six tools as shares: ' . implode(', ', $S['shares']));
ok(mcp_tools(REC, ptoken(26)) === $rec && mcp_tools(REC, atoken(26)) === $rec && mcp_tools(REC, atoken(1)) === $rec && mcp_tools(REC, ptoken(29)) === $rec, 'a Consultant (own token), the command bar\'s action token, the owner and Finance get the same 48 (the views scope the rows)');
$r = mcp_tool(REC, atoken(26), 'my_timer', []);
ok(!$r['error'] && ($r['data']['running'] ?? null) === false, 'the action token acts as its member: my_timer is Priya\'s (no timer)');
$r = mcp_tool(REC, atoken(30), 'my_timer', []);
ok(!$r['error'] && ($r['data']['running'] ?? null) === true && ($r['data']['timer']['engagement_number'] ?? '') === 'ENG-0001', 'and Dana\'s runs on ENG-0001');
$defs = mcp_tool_defs(REC, ptoken(27));
$ro = array_filter($defs, fn ($d) => ($d['annotations']['readOnlyHint'] ?? false) === true && ($d['annotations']['openWorldHint'] ?? true) === false);
ok(count($ro) === count($defs) && count($defs) === 48, 'every records tool carries readOnlyHint true and openWorldHint false');
$ad = mcp_tool_defs(ACT, ptoken(27));
ok(count(array_filter($ad, fn ($d) => ($d['annotations']['readOnlyHint'] ?? false) === true)) === 6, '...and so does every activity tool');
$noDesc = array_filter($defs, fn ($d) => strlen((string) ($d['description'] ?? '')) < 40);
ok(count($noDesc) === 0, 'every tool describes itself (when to call it, what it answers)');

echo "3. An agent sees exactly what the kernel granted on this endpoint\n";
$clerk = agent_grants('biller');
$r1 = $run();
facts($r1, 40, $clerk);
$tok = rtoken(40, $r1);
$want = array_keys($clerk['Records MCP']); sort($want);
ok(mcp_tools(REC, $tok) === $want, 'the Clerk: exactly its ' . count($want) . ' granted records tools (maludb-os.json)');
$wantA = array_keys($clerk['Activity MCP']); sort($wantA);
ok(mcp_tools(ACT, $tok) === $wantA, 'activity: exactly record_history — a grant on one endpoint is not a grant on the other');
$c = mcp_tool(REC, $tok, 'ar_aging', []);
ok(!$c['error'] && isset($c['data']['rows']), 'a granted tool answers as the agent (the Clerk holds billing: ar_aging answers)');
$c = mcp_tool(REC, $tok, 'margin', ['from' => '2026-09-01', 'to' => '2026-10-31']);
ok($c['error'] && str_contains($c['text'], 'not among the tools'), 'a tool it was not granted is refused: ' . substr($c['text'], 0, 100));
ok(mcp_tool(REC, $tok, 'invoices_issued', ['from' => '2026-09-01', 'to' => '2026-09-30'])['error'], 'a share it was not granted is refused for an agent too');
$exp = agent_grants('expert');
$r2 = $run(); facts($r2, 41, $exp);
$want = array_keys($exp['Records MCP']); sort($want);
ok(mcp_tools(REC, rtoken(41, $r2)) === $want, 'the expert: exactly its ' . count($want) . ' granted records tools');
$ex = mcp_tool(REC, rtoken(41, $r2), 'find_clients', []);
ok(!$ex['error'] && isset($ex['data']['clients']), 'a granted tool answers as the expert (a Manager-role agent)');

echo "4. Fail closed\n";
$r = $run(); facts($r, 40, ['Records MCP' => ['my_timer' => []]], ['valid' => false]);
$t = rtoken(40, $r);
ok(mcp_tools(REC, $t) === [], 'the kernel says the token is not valid: an admitted agent is offered NOTHING');
ok(mcp_tool(REC, $t, 'my_timer', [])['error'], 'and cannot call anything');
$r = $run(); facts($r, 40, []);
ok(mcp_tools(REC, rtoken(40, $r)) === [], "facts with no endpoint for this application (another application's agent): nothing offered");
ok(mcp_tools(REC, rtoken(40, $run())) === [], 'a run the kernel does not know (valid:false by default): nothing offered');
$r = $run(); facts($r, 40, ['Records MCP' => ['my_timer' => []]], ['is_agent' => false]);
ok(count(mcp_tools(REC, rtoken(40, $r)) ?? []) === 48, 'facts that say is_agent false are a person: never filtered');
$r = $run(); facts($r, 40, ['Records MCP' => ['my_timer' => []]]);
$t = rtoken(40, $r);
ok(mcp_tools(REC_DEAD, $t) === [], 'the kernel cannot be reached (:8310, dead URL): an admitted agent is offered nothing');
ok(mcp_tool(REC_DEAD, $t, 'my_timer', [])['error'], 'and cannot call');
ok(count(mcp_tools(REC_DEAD, ptoken(27)) ?? []) === 48 && count(mcp_tools(REC_DEAD, atoken(27)) ?? []) === 48, "while a person's own token and the command bar's action token still work with no kernel");

echo "5. An agent the directory has not admitted yet\n";
make_agent(953, 'SMOKE Unadmitted P4', ['billing', 'user'], null);
ok(one('SELECT capability FROM members WHERE id = 953') === null, 'agent 953 is in the mirror with no capability');
$r = $run(); $t = rtoken(953, $r);
ok(mcp_tools(REC, $t) === null, 'a run the kernel does not vouch for: 401, and it is not admitted');
ok(one('SELECT capability FROM members WHERE id = 953') === null, '...capability still null');
facts($r, 953, ['Records MCP' => ['my_timer' => [], 'find_clients' => []]]);
ok(mcp_tools(REC, $t) === ['find_clients', 'my_timer'], 'the kernel vouches for it with endpoints here: admitted at first contact, offered its two tools');
ok(one('SELECT capability FROM members WHERE id = 953') === 'write', '...and its capability is now write');
make_agent(956, 'SMOKE Unadmitted P4 B', ['billing', 'user'], null);
$r = $run(); facts($r, 956, []);
ok(mcp_tools(REC, rtoken(956, $r)) === null && one('SELECT capability FROM members WHERE id = 956') === null, 'facts with NO endpoint here do not admit: 401, capability still null');

echo "6. An evaluation run may read, and marks nothing\n";
$before = [(int) one('SELECT count(*) FROM activity_log'), (int) one('SELECT count(*) FROM invoices'), (int) one('SELECT count(*) FROM time_entries'), (int) one('SELECT count(*) FROM notification_outbox')];
$r = $run(); facts($r, 40, $clerk, ['trigger' => 'eval']);
$te = rtoken(40, $r);
$c = mcp_tool(REC, $te, 'find_invoices', [], false, ['X-Eval-Run: 1']);
ok(!$c['error'] && isset($c['data']['invoices']), 'an eval run (facts trigger eval, X-Eval-Run header): a read tool answers');
$c = mcp_tool(REC, $te, 'unbilled_items', ['engagement_id' => 1], false, ['X-Eval-Run: 1']);
ok(!$c['error'] && count($c['data']['items'] ?? []) > 0, 'and a report as well');
$after = [(int) one('SELECT count(*) FROM activity_log'), (int) one('SELECT count(*) FROM invoices'), (int) one('SELECT count(*) FROM time_entries'), (int) one('SELECT count(*) FROM notification_outbox')];
ok($before === $after, 'nothing was written: the log, invoices, time entries and the outbox are as they were');
$def = mcp_tool(REC, ptoken(27), 'find_invoices', [], false, ['X-Eval-Run: 1']);
ok(!$def['error'], 'the header on a person\'s call changes nothing either (every tool reads)');

echo "7. The kernel's own token\n";
$kt = ktoken();
$ktools = mcp_tools(REC, $kt);
$exp7 = array_merge($S['shares'], ['app_roles']); sort($exp7);
ok($ktools === $exp7, 'the kernel is offered exactly app_roles and the six shares: ' . implode(', ', $ktools ?? []));
ok(mcp_tools(ACT, ktoken()) === [], 'and nothing on the activity server');
$r = mcp_tool(REC, ktoken(), 'app_roles', [], true);
ok(!$r['error'] && ($r['data']['schema'] ?? '') === 'os.app-roles/1' && count($r['data']['roles'] ?? []) === 5 && count($r['data']['rights'] ?? []) === 21, 'app_roles answers os.app-roles/1: five roles, twenty-one rights');
$roleKeys = array_column($r['data']['roles'], 'key'); sort($roleKeys);
ok($roleKeys === array_column(q('SELECT role_key FROM ct_roles ORDER BY role_key'), 'role_key'), 'the five role keys are the database\'s');
foreach (['find_clients', 'find_invoices', 'my_timer', 'records_search', 'get_engagement', 'margin'] as $t) {
    $r = mcp_tool(REC, ktoken(), $t, $t === 'records_search' ? ['sql' => 'select 1'] : [], $t === 'my_timer');
    ok($r['error'] && str_contains($r['text'], "reaches"), "the kernel's token calling $t is refused in words: " . substr($r['text'], 0, 70));
}
$r = mcp_tool(ACT, ktoken(), 'record_history', ['engagement_id' => 1]);
ok($r['error'], 'and on the activity server');
finish();
