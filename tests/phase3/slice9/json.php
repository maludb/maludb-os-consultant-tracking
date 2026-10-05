<?php
/**
 * Proof — JSON mode and the registry (spec "Proof"): the registry reads every screen and action of the manifest as built — 78 screens, 108 actions, none a stub; the two screens of this slice answer JSON under a signed action token as under a session (the whitelisted shapes — no raw row, no
 * token, no hash, no cost); the home answers every region by name under a token; the agents' own run tokens are refused the admin pages; the manifest rows of this slice.
 */
require __DIR__ . '/lib.php';
home_world(); pulled_month();
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$mani = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/consultant-tracking-action-manifest.md');
$oTok = ['X-Action-Token: ' . person_token(1)]; $mTok = ['X-Action-Token: ' . person_token(27)]; $bTok = ['X-Action-Token: ' . person_token(28)];
$owner = as_member(1);

echo "1. The registry\n";
$screens = $reg['screens']; $actions = $reg['actions'];
ok(count($screens) === 78 && count(array_filter($screens, fn ($s) => !empty($s['built']))) === 78, 'the registry: 78 screens, every one built — nothing is a stub');
ok(count($actions) === 108 && count(array_filter($actions, fn ($a) => !empty($a['built']))) === 108, 'the registry: 108 actions, every one built');
ok($screens['agents']['url'] === '/settings/agents' && $screens['connections']['url'] === '/settings/connections' && !empty($screens['agents']['built']) && !empty($screens['connections']['built']), 'agents → /settings/agents, connections → /settings/connections, both built');
$stubs = [];
foreach (glob(dirname(__DIR__, 3) . '/html/{,*/,*/*/,*/*/*/}*.php', GLOB_BRACE) as $f) { if (str_contains((string) file_get_contents($f), 'render_nav_stub(')) { $stubs[] = str_replace(dirname(__DIR__, 3) . '/', '', $f); } }
ok($stubs === [], 'no controller calls render_nav_stub() any more' . ($stubs ? ' — still: ' . implode(', ', $stubs) : ''));
ok(substr_count($mani, '`agents`') >= 1 && substr_count($mani, '`connections`') >= 1, 'the manifest still names agents and connections (no row deleted)');

echo "2. Under a person's action token\n";
[$c, $d, $raw] = screen_token($oTok, '/settings/agents');
ok($c === 200 && array_keys($d) === ['agents', 'os_url'] && count($d['agents']) >= 2 && array_keys($d['agents'][0]) === ['key', 'declared', 'hired', 'member', 'last_activity', 'activity_7d', 'last_dispatch', 'dispatches', 'last_note'], 'the administrator\'s token: /settings/agents → {agents[], os_url}, each agent {key, declared, hired, member, last_activity, activity_7d, last_dispatch, dispatches, last_note}');
ok(array_keys($d['agents'][1]['member']) === ['member_id', 'name', 'job_title', 'status', 'capability', 'roles'] && array_keys($d['agents'][1]['last_dispatch']) === ['dispatch_id', 'kind', 'via', 'status', 'attempts', 'at', 'answered_at'] && !str_contains($raw['body'], 'reply_excerpt') && !str_contains($raw['body'], 'Looked at it'), 'the member and the dispatch are whitelists — the reply\'s words, the dispatch\'s detail and the request id are not in it');
[$c, $d] = screen_token($oTok, '/settings/connections');
ok($c === 200 && array_keys($d) === ['shares', 'reads', 'os_url'] && array_keys($d['shares'][0]) === ['tool', 'description', 'people', 'scoped', 'reads', 'last_read'] && array_keys($d['reads'][0]) === ['provider', 'tool', 'why', 'declared', 'state', 'last'], '/settings/connections → {shares[], reads[], os_url}, each a whitelist');
foreach ([['Marco', $mTok], ['Bea', $bTok]] as [$n, $t]) {
    foreach (['/settings/agents', '/settings/connections'] as $path) {
        [$c, $d, $raw] = screen_token($t, $path);
        ok($c === 403 && ($d['error']['code'] ?? '') === 'forbidden', "$n's token: $path is 403 {error: {code: forbidden}}");
    }
}
$since = last_activity_id();
[$c, $d] = screen_token($oTok, '/settings/agents');
ok(count(activity('screen.view', $since)) === 0, 'an action token reading a screen as JSON is not a screen view (nothing logged unless asked)');

echo "3. The home under a token\n";
[$c, $d] = screen_token($bTok, '/');
ok($c === 200 && array_keys($d) === ['may', 'my_week', 'timer', 'my_expenses', 'approvals', 'budget_alerts', 'billing', 'clerk_note', 'bell'] && $d['billing']['statement'] !== null && array_keys($d['billing']['statement']) === ['period', 'status', 'kernel_status', 'amount', 'allocated', 'remainder'] && array_keys($d['approvals']) === ['weeks', 'expenses', 'usage', 'counts'], 'Bea\'s token: / answers every region — the billing region carries the statement {period, status, kernel_status, amount, allocated, remainder}; approvals {weeks, expenses, usage, counts}');
ok(!str_contains(json_encode($d), 'cost_rate') && !str_contains(json_encode($d), 'hourly_cost') && !str_contains(json_encode($d), 'token_hash'), 'no cost rate and no hash anywhere in it');
[$c, $d] = screen_token($mTok, '/');
ok($c === 200 && $d['billing']['statement'] === null && $d['clerk_note'] === null, 'Marco\'s token: the same page, with the statement and the Clerk\'s note null');

echo "4. An agent's run token\n";
expert_member();
[$tok, $relay] = run_token(40, 991);
kernel_state(function ($s) { $s['facts']['991'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 991, 'request_id' => 'req-991', 'trigger' => 'duty', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$r = req('GET', '/settings/agents', ['headers' => array_merge(JSONH, ['X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay])]);
ok($r['code'] === 403, 'the Clerk\'s run token: /settings/agents is 403 — an agent does not read the admin pages (' . $r['code'] . ')');
$r = req('GET', '/settings/connections', ['headers' => array_merge(JSONH, ['X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay])]);
ok($r['code'] === 403, 'and /settings/connections (' . $r['code'] . ')');
finish();
