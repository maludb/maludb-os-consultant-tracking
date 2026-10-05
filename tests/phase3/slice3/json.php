<?php
/** Proof — JSON mode (spec "Proof", 10): every handler under a signed action token: {ok, did, record_id, location}; the expert's run token logs an entry for the acting person and submits; _partial=1 keeps the entry's fields; the agent's refusals. */
require __DIR__ . '/lib.php';
[$acme, $beta] = time_world();
$priyaTok = ['X-Action-Token: ' . person_token(26)];
$marcoTok = ['X-Action-Token: ' . person_token(27)];
$w = monday(3);

echo "1. A person's action token\n";
[$c, $b] = act_token('/time/entries/save.php', ['engagement' => $acme, 'date' => day($w, 0), 'hours' => '2', 'note' => 'SMOKE by token'], $priyaTok);
$e = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $b['ok'] === true && $e > 0 && $b['week_start'] === $w && isset($b['timesheet_id']) && $b['refresh'] === 'timesheetChanged', 'time_entry_save: {ok, did, record_id, week_start, timesheet_id, refresh}');
[$c, $b] = act_token('/time/entries/save.php', ['entry' => $e, 'hours' => '2.5', '_partial' => '1'], $priyaTok);
ok($c === 200 && q('SELECT hours, note, engagement_id, entry_date FROM time_entries WHERE id = :e', ['e' => $e])[0] === ['hours' => '2.50', 'note' => 'SMOKE by token', 'engagement_id' => $acme, 'entry_date' => day($w, 0)], '_partial=1 with the hours only keeps the note, engagement and date');
[$c, $b] = act_token('/time/timer/start.php', ['engagement' => $acme], $priyaTok);
[$c2, $b2] = act_token('/time/timer/stop.php', [], $priyaTok);
ok($c === 200 && $c2 === 200 && $b2['hours'] === '0.25' && ($b2['record_id'] ?? 0) > 0, 'timer_start and timer_stop answer JSON (0.25 h)');
q('DELETE FROM time_entries WHERE id = :e', ['e' => (int) $b2['record_id']]);
[$c, $b] = act_token('/time/weeks/submit.php', ['week' => $w], $priyaTok);
$ts = (int) $b['record_id'];
ok($c === 200 && $b['status'] === 'submitted' && $ts > 0, 'timesheet_submit answers the status and the week\'s id');
[$c, $b] = act_token('/time/weeks/reopen.php', ['timesheet' => $ts], $priyaTok);
ok($c === 200 && $b['status'] === 'open', 'timesheet_reopen');
[$c, $b] = act_token('/time/weeks/submit.php', ['timesheet' => $ts], $priyaTok);
[$c, $b] = act_token('/time/weeks/approve.php', ['timesheet' => $ts], $marcoTok);
ok($c === 200 && $b['approved'] === 1 && $b['week_status'] === 'approved', 'timesheet_approve answers approved, left_submitted, week_status');
[$c, $b] = act_token('/time/entries/write-off.php', ['entry' => $e, 'reason' => 'SMOKE token write-off'], $marcoTok);
ok($c === 200 && $b['did'] === 'Wrote off 2.5 h on ' . one('SELECT number FROM engagements WHERE id = :e', ['e' => $acme]), 'time_entry_write_off');
[$c, $b] = act_token('/time/entries/save.php', ['engagement' => $acme, 'date' => 'yesterday', 'hours' => 'x'], $priyaTok);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($b['error']['fields']['date'], $b['error']['fields']['hours']), 'a refusal: 422 {error: {code: invalid, fields}}');
[$c, $b] = act_token('/time/weeks/nudge.php', ['member' => 30, 'week' => monday(6)], $marcoTok);
ok($c === 200 && isset($b['queued']), 'timesheet_nudge_send answers queued');

echo "2. The expert's run token\n";
kernel_state(function ($s) { $s['incremental'] = incr(['members' => [['id' => 902, 'member_kind' => 'agent', 'display_name' => 'SMOKE CT expert', 'email' => null, 'business_role' => 'user', 'is_external' => false, 'status' => 'active', 'updated_at' => '2026-01-01T00:00:00Z', 'departments' => [['id' => 7, 'name' => 'AI Strategy', 'is_admin' => false, 'is_primary' => true]]]],
    'access' => [['member_id' => 902, 'role' => 'manager', 'roles' => ['manager', 'user'], 'capability' => 'write', 'scopes' => []]]], '2026-06-01T00:00:00.000000Z'); return $s; });
sync();
[$tok, $relay] = run_token(902, 778);
kernel_state(function ($s) { $s['facts']['778'] = ['valid' => true, 'is_agent' => true, 'member_id' => 902, 'run_id' => 778, 'request_id' => 'req-778', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$h = ['X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay];
$w5 = monday(5);
[$c, $b] = act_token('/time/entries/save.php', ['engagement' => $acme, 'date' => day($w5, 1), 'hours' => '3', 'member' => 26], $h);
ok($c === 403, 'the expert (not Acme\'s manager) may not log for Priya on it: 403');
// make it Acme's manager's peer: a Manager of the practice? no — Bea staffs the agent as the engagement's manager is Marco; give the agent an engagement of its own
[$c, $b] = act_token('/engagements/save.php', ['client' => (int) one("SELECT id FROM clients WHERE name = 'SMOKE Acme'"), 'name' => 'SMOKE Expert eng', 'kind' => 'time_and_materials', 'start_date' => '2026-01-05', 'start_now' => 'yes'], $h);
$ee = (int) $b['record_id'];
[$c, $b] = act_token('/engagements/staffing/save.php', ['engagement' => $ee, 'member' => 26, 'role' => role('consultant'), 'valid_from' => '2026-01-05'], $h);
$since = last_activity_id();
[$c, $b] = act_token('/time/entries/save.php', ['engagement' => $ee, 'date' => day($w5, 1), 'hours' => '3', 'member' => 26], $h);
ok($c === 200 && (int) one('SELECT member_id FROM time_entries WHERE id = :e', ['e' => (int) $b['record_id']]) === 26, 'on its own engagement it logs an entry for Priya (the acting person)');
$log = activity('time_entry.save', $since);
ok($log[0]['source'] === 'agent' && (int) $log[0]['agent_run_id'] === 778 && json_decode((string) $log[0]['after'], true)['logged_by'] === 902, 'its row: source agent, run 778, logged_by the agent');
[$c, $b] = act_token('/time/weeks/submit.php', ['week' => $w5, 'member' => 26], $h);
ok($c === 200 && $b['status'] === 'submitted', 'and submits her week');
[$c, $b] = act_token('/time/weeks/approve.php', ['timesheet' => (int) $b['record_id']], $h);
ok($c === 200 && $b['week_status'] === 'approved', 'timesheet_approve by the agent on its own engagement: 200 here — on the MCP path the kernel\'s hook pauses it as `other` (Phase 4)');
kernel_state(function ($s) { unset($s['incremental'], $s['facts']); return $s; });
finish();
