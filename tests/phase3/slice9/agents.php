<?php
/**
 * Proof — the Agents screen (spec "Proof", 2): the two shipped agents declared in maludb-os.json (the expert — manager, the Billing Clerk — billing, with its duty "The morning billing" daily at 07:00) matched to the mirror's agent members (the directory feed's facts: the Clerk is member 40,
 * the expert is member 41 here) by the roles they hold; what they last did here (the activity log), the last dispatch and its status, the dispatch counts, the Clerk's last morning note; an agent not yet hired is said so; an agent the manifest does not declare is listed; the OS's pages are linked,
 * never a button that hires, grants or runs; settings.manage only; nothing is changed by looking.
 */
require __DIR__ . '/lib.php';
$w = report_world();
$owner = as_member(1); $bea = as_member(28); $marco = as_member(27); $priya = as_member(26);

echo "1. Before the expert is hired: what is declared, what is held\n";
[$c, $d] = screen($owner, '/settings/agents');
ok($c === 200 && array_keys($d) === ['agents', 'os_url'] && count($d['agents']) === 2 && array_column($d['agents'], 'key') === ['expert', 'biller'], '/settings/agents (JSON): {agents[2], os_url} — the expert and the biller, in the order maludb-os.json declares them');
[$expert, $biller] = $d['agents'];
ok($expert['hired'] === false && $expert['member'] === null && $expert['last_activity'] === null && $expert['declared']['roles'] === ['manager'] && $expert['declared']['duty'] === null && $expert['declared']['tools'] > 40 && $expert['declared']['skills'] === 3, 'the expert: declared (role manager, no duty, its tool grants and three skills), NOT hired yet — the mirror holds no such agent');
ok($biller['hired'] === true && $biller['member']['member_id'] === 40 && $biller['member']['name'] === 'SMOKE Clerk' && $biller['member']['job_title'] === 'Billing Clerk' && $biller['member']['roles'] === ['billing', 'user'] && $biller['member']['status'] === 'active', 'the Billing Clerk: hired — member 40, Billing Clerk, roles billing and user, active');
ok($biller['declared']['duty'] === ['name' => 'The morning billing', 'schedule' => 'daily at 07:00'], 'the Clerk\'s duty: "The morning billing", daily at 07:00 (the cron expression 0 7 * * * in words)');
$r = page($owner, '/settings/agents');
ok($r['code'] === 200 && str_contains($r['body'], 'id="agents-cards"') && str_contains($r['body'], 'id="agent-expert-unhired"') && str_contains($r['body'], 'Not hired here yet') && str_contains($r['body'], 'id="agent-biller"') && !str_contains($r['body'], 'slice 9') && !str_contains($r['body'], 'is not built yet'), 'the page: a card each, the expert says it is not hired here yet, no placeholder');
ok(str_contains($r['body'], 'href="https://os.example.invalid/"') && str_contains($r['body'], 'id="agents-os-link"') && $d['os_url'] === 'https://os.example.invalid/', 'the page points to the OS (the launcher\'s app. name turned into os.) for hiring, grants and duties');
$inner = explode('id="agents-content"', $r['body'])[1]; $inner = substr($inner, 0, (int) strpos($inner, 'id="agents-cards"') + 4000);
ok(!preg_match('/<form|type="submit"|<button/', substr($inner, 0, strpos($inner, 'agent-biller-grants') ?: 6000)), 'no form and no submit button on the page: hiring, granting and running are the kernel\'s');

echo "2. What they did here\n";
home_world();
$since = last_activity_id();
[$c, $d] = screen($owner, '/settings/agents');
[$expert, $biller] = $d['agents'];
ok($expert['hired'] === true && $expert['member']['member_id'] === 41 && $expert['member']['name'] === 'SMOKE Expert' && $expert['member']['roles'] === ['manager'] && $expert['last_activity'] === null && $expert['activity_7d'] === 0 && $expert['last_dispatch'] === null, 'the expert, once in the mirror: hired (member 41, role manager), nothing done yet — no activity, no dispatch');
ok($biller['last_activity']['action'] === 'morning_note.send' && $biller['last_activity']['source'] === 'agent' && $biller['activity_7d'] === 1, 'the Clerk\'s last activity: morning_note.send as an agent, one action in the last seven days');
ok($biller['last_dispatch']['kind'] === 'claim_submitted' && $biller['last_dispatch']['status'] === 'failed' && $biller['last_dispatch']['attempts'] === 1 && $biller['dispatches'] === ['answered' => 1, 'failed' => 1], 'the last dispatch: claim_submitted, failed, one attempt; counts answered 1, failed 1');
ok($biller['last_note']['title'] === 'The morning note, SMOKE s9' && $biller['last_note']['for'] === 'SMOKE Bea' && $expert['last_note'] === null, 'the Clerk\'s duty left its morning note for Bea; the expert has none');
$r = page($owner, '/settings/agents');
ok(str_contains($r['body'], 'id="agent-biller-dispatch"') && str_contains($r['body'], 'claim submitted') && str_contains($r['body'], 'The morning note, SMOKE s9') && str_contains($r['body'], 'morning_note.send') && str_contains($r['body'], 'id="agent-expert-last"') && str_contains($r['body'], 'none yet') && !str_contains($r['body'], 'id="agent-expert-unhired"'), 'the page shows the Clerk\'s dispatch, its note and last action; the expert says "none yet"');
$other = (int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action <> 'screen.view'", ['s' => $since]);
ok($other === 0 && (int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action = 'screen.view' AND route = 'GET /settings/agents'", ['s' => $since]) === 1, 'looking is logged as screen.view and nothing else: the page writes no record');

echo "3. An agent the manifest does not declare; an inactive one\n";
q("INSERT INTO members (id, member_kind, display_name, business_role, is_external, status, capability, roles, job_title, timezone, directory_updated_at, synced_at) VALUES (77, 'agent', 'SMOKE Stranger', 'user', false, 'active', 'read', '{finance}', 'Auditor', 'UTC', now(), now())");
[$c, $d] = screen($owner, '/settings/agents');
ok(count($d['agents']) === 3 && $d['agents'][2]['key'] === null && $d['agents'][2]['declared'] === null && $d['agents'][2]['member']['member_id'] === 77 && $d['agents'][2]['hired'] === true, 'an agent in the mirror that no declaration matches is listed after the declared ones (key null, declared null)');
q("UPDATE members SET status = 'inactive' WHERE id = 77");
q("UPDATE members SET status = 'inactive' WHERE id = 41");
[$c, $d] = screen($owner, '/settings/agents');
ok(count($d['agents']) === 2 && $d['agents'][0]['hired'] === false, 'a deactivated agent is no longer "hired here" (the mirror shows active members only): the expert reads not hired again, the stranger is gone');
q("UPDATE members SET status = 'active' WHERE id = 41"); q('DELETE FROM members WHERE id = 77');

echo "4. Who may look\n";
foreach ([['Priya (a Consultant)', $priya], ['Marco (a Manager)', $marco], ['Bea (Billing)', $bea]] as [$n, $j]) {
    $r = page($j, '/settings/agents');
    ok($r['code'] === 403, "$n: 403 — the page is settings.manage's");
}
$r = req('GET', '/settings/agents', ['jar' => '/dev/null']);
ok(in_array($r['code'], [302, 303, 401], true), 'no session: sent to sign in (' . $r['code'] . ')');
finish();
