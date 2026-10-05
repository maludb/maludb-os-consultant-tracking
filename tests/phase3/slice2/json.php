<?php
/** Proof — JSON mode (spec "Proof", 6): every handler under a signed action token; _partial=1 keeps the engagement's fields; a run token for the expert may engagement_save; the agent's refusals. */
require __DIR__ . '/lib.php';
[$acme, $card] = fixture_client();
$marcoTok = ['X-Action-Token: ' . person_token(27)];
$priyaTok = ['X-Action-Token: ' . person_token(26)];

echo "1. A person's action token\n";
[$c, $b] = act_token('/engagements/save.php', ['client' => $acme, 'name' => 'SMOKE JSON eng', 'kind' => 'time_and_materials', 'budget_hours' => '50', 'po_number' => 'PO-J', 'start_now' => 'yes'], $marcoTok);
$eng = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $b['ok'] === true && $eng > 0 && str_starts_with((string) $b['number'], 'ENG-') && $b['refresh'] === 'engagementChanged', 'engagement_save: {ok, did, record_id, number, refresh}');
[$c, $b] = act_token('/engagements/save.php', ['engagement' => $eng, 'name' => 'SMOKE JSON eng renamed', '_partial' => '1'], $marcoTok);
$row = q('SELECT name, budget_hours, po_number, kind, status FROM engagements WHERE id = :e', ['e' => $eng])[0];
ok($c === 200 && $row['name'] === 'SMOKE JSON eng renamed' && $row['budget_hours'] === '50.00' && $row['po_number'] === 'PO-J' && $row['kind'] === 'time_and_materials' && $row['status'] === 'active', '_partial=1 with the name only: the hours, PO, kind and status kept');
[$c, $b] = act_token('/engagements/staffing/save.php', ['engagement' => $eng, 'member' => 26, 'role' => role('consultant')], $marcoTok);
$st = (int) $b['record_id'];
ok($c === 200 && $st > 0 && $b['staffing_id'] === $st, 'engagement_member_save: record_id and staffing_id');
[$c, $b] = act_token('/engagements/staffing/save.php', ['staffing' => $st, 'note' => 'SMOKE partial', '_partial' => '1'], $marcoTok);
ok($c === 200 && (int) one('SELECT consulting_role_id FROM engagement_members WHERE id = :s', ['s' => $st]) === role('consultant'), '_partial on a staffing keeps the role');
[$c, $b] = act_token('/engagements/rates/save.php', ['engagement' => $eng, 'role' => role('consultant'), 'bill_rate' => '190'], $marcoTok);
ok($c === 200 && $b['bill_rate'] === '190.00', 'engagement_role_rate_save answers the rate');
[$c, $b] = act_token('/engagements/milestones/save.php', ['engagement' => $eng, 'name' => 'SMOKE JSON M1', 'amount' => '100'], $marcoTok);
$m = (int) $b['record_id'];
[$c2, $b2] = act_token('/engagements/milestones/save.php', ['milestone' => $m, 'due_date' => '2026-12-01', '_partial' => '1'], $marcoTok);
ok($c === 200 && $c2 === 200 && q('SELECT name, amount, due_date FROM milestones WHERE id = :m', ['m' => $m])[0] === ['name' => 'SMOKE JSON M1', 'amount' => '100.00', 'due_date' => '2026-12-01'], 'milestone_save and _partial keep the name and amount');
[$c, $b] = act_token('/engagements/milestones/deliver.php', ['milestone' => $m], $marcoTok);
[$c2, $b2] = act_token('/engagements/milestones/accept.php', ['milestone' => $m, 'acceptance_note' => 'SMOKE by token'], $marcoTok);
ok($c === 200 && $c2 === 200 && $b2['status'] === 'accepted' && $b2['attachment_id'] === null, 'deliver and accept under the token (no file: attachment_id null)');
[$c, $b] = act_token('/engagements/hold.php', ['engagement' => $eng, 'reason' => 'SMOKE'], $marcoTok);
ok($c === 200 && $b['status'] === 'on_hold', 'engagement_hold answers the status');
[$c, $b] = act_token('/engagements/save.php', ['client' => $acme, 'name' => '', 'kind' => 'x'], $marcoTok);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($b['error']['fields']['name'], $b['error']['fields']['kind']), 'a refusal: 422 {error: {code: invalid, fields}}');
[$c, $b] = act_token('/engagements/hold.php', ['engagement' => $eng], $priyaTok);
ok($c === 403 && ($b['error']['code'] ?? '') === 'forbidden', 'Priya\'s token on a move: 403 forbidden');
[$c, $b] = act_token('/usage/budgets/check.php', ['engagement' => $eng], $marcoTok);
ok($c === 200 && isset($b['new_alerts'], $b['checked']), 'budget_alert_write answers the counts');

echo "2. The expert's run token\n";
kernel_state(function ($s) { $s['incremental'] = incr(['members' => [['id' => 901, 'member_kind' => 'agent', 'display_name' => 'SMOKE CT expert', 'email' => null, 'business_role' => 'user', 'is_external' => false, 'status' => 'active', 'updated_at' => '2026-01-01T00:00:00Z', 'departments' => [['id' => 7, 'name' => 'AI Strategy', 'is_admin' => false, 'is_primary' => true]]]],
    'access' => [['member_id' => 901, 'role' => 'manager', 'roles' => ['manager', 'user'], 'capability' => 'write', 'scopes' => []]]], '2026-05-01T00:00:00.000000Z'); return $s; });
sync();
[$tok, $relay] = run_token(901, 777);
kernel_state(function ($s) { $s['facts']['777'] = ['valid' => true, 'is_agent' => true, 'member_id' => 901, 'run_id' => 777, 'request_id' => 'req-777', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$since = last_activity_id();
[$c, $b] = act_token('/engagements/save.php', ['client' => $acme, 'name' => 'SMOKE by the expert', 'kind' => 'time_and_materials', 'start_now' => 'yes'], ['X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay]);
$byExpert = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $byExpert > 0, 'the expert (Manager role, a run token with the relay) opens an engagement');
$log = activity('engagement.create', $since);
ok(count($log) === 1 && $log[0]['source'] === 'agent' && (int) $log[0]['agent_run_id'] === 777 && (int) $log[0]['actor_member_id'] === 901 && (int) one('SELECT manager_member_id FROM engagements WHERE id = :e', ['e' => $byExpert]) === 901, 'its row is source agent, run 777; the agent is the manager (and staffed as principal)');
[$c, $b] = act_token('/engagements/delete.php', ['engagement' => $byExpert], ['X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay]);
ok($c === 422, 'it may not delete an active one (the trigger) — and `deletion` would pause it at the kernel\'s hook on the MCP path (Phase 4)');
[$c, $b] = act_token('/engagements/staffing/save.php', ['engagement' => $eng, 'member' => 30, 'role' => role('analyst')], ['X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay]);
ok($c === 404, 'nor staff Marco\'s engagement (not its manager, not the practice\'s admin — not even visible): 404');
kernel_state(function ($s) { unset($s['incremental'], $s['facts']); return $s; });
finish();
