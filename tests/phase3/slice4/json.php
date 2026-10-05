<?php
/** Proof — JSON mode (spec "Proof", 6): every handler under a signed action token answers {ok, did, record_id, location}; the expert's run token records and submits an expense for the acting person (source agent, the run, recorded_by the agent); the agent's refusals. */
require __DIR__ . '/lib.php';
[$acme, $beta] = expense_world();
$priyaTok = ['X-Action-Token: ' . person_token(26)];
$marcoTok = ['X-Action-Token: ' . person_token(27)];
$beaTok = ['X-Action-Token: ' . person_token(28)];

echo "1. A person's action token\n";
[$c, $b] = act_token('/expenses/save.php', ['engagement' => $acme, 'date' => date('Y-m-d'), 'category' => category('meals'), 'amount' => '200.00', 'description' => 'SMOKE by token'], $priyaTok);
$e = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $b['ok'] === true && $e > 0 && $b['refresh'] === 'expenseChanged' && $b['status'] === 'draft' && isset($b['number'], $b['location'], $b['did']), 'expense_save: {ok, did, record_id, location, number, status, refresh}');
[$c, $b] = act_token('/expenses/submit.php', ['expense' => $e], $priyaTok);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && str_contains($b['error']['message'], 'A receipt is required at or above 75.00'), 'expense_submit without the receipt: 422 {error: {code: invalid, message: the threshold\'s sentence}}');
$jpg = jpeg_file();
$r = req('POST', '/expenses/save.php', ['headers' => array_merge(JSONH, $priyaTok), 'raw' => '']);   // an empty body is a refusal, not a crash
ok($r['code'] === 422, 'an empty save: 422 with fields');
$ch = curl_init(BASE . '/expenses/save.php');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array_merge(JSONH, $priyaTok), CURLOPT_POSTFIELDS => ['expense' => $e, 'file' => new CURLFile($jpg, 'image/jpeg', 'token.jpg')]]);
$body = json_decode((string) curl_exec($ch), true);
ok(curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && (bool) one('SELECT ct_expense_has_receipt(:e)', ['e' => $e]), 'a receipt over the token door (multipart `file`) is stored on the expense');
[$c, $b] = act_token('/expenses/submit.php', ['expense' => $e], $priyaTok);
ok($c === 200 && $b['status'] === 'submitted', 'expense_submit answers the status');
[$c, $b] = act_token('/expenses/approve.php', ['expense' => $e], $marcoTok);
ok($c === 200 && $b['status'] === 'approved' && $b['billable_amount'] === '220.00', 'expense_approve answers status and billable_amount');
[$c, $b] = act_token('/expenses/reject.php', ['expense' => $e, 'reason' => 'SMOKE token reject'], $marcoTok);
ok($c === 200 && $b['status'] === 'rejected', 'expense_reject');
[$c, $b] = act_token('/expenses/save.php', ['expense' => $e, 'amount' => '150.00', 'submit' => '1', '_partial' => '1'], $priyaTok);
[$c, $b] = act_token('/expenses/approve.php', ['expense' => $e], $marcoTok);
[$c, $b] = act_token('/expenses/reimburse.php', ['expense' => $e, 'reference' => 'PAYRUN-TOKEN'], $beaTok);
ok($c === 200 && $b['reimbursed'] === 1 && $b['reference'] === 'PAYRUN-TOKEN', 'expense_mark_reimbursed answers reimbursed and the reference');
[$c, $b] = act_token('/expenses/reimburse.php', ['expense' => $e, 'reference' => 'X'], $marcoTok);
ok($c === 403, 'Marco\'s token may not mark reimbursed: 403');
[$c, $b] = act_token('/expenses/save.php', ['engagement' => $acme, 'date' => 'yesterday', 'category' => 'x', 'amount' => 'x'], $priyaTok);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($b['error']['fields']['date'], $b['error']['fields']['amount'], $b['error']['fields']['description']), 'a refusal: 422 {error: {code: invalid, fields}}');
[$c, $b] = act_token('/expenses/save.php', ['engagement' => $acme, 'date' => date('Y-m-d'), 'category' => category('meals'), 'amount' => '5.00', 'description' => 'SMOKE to remove'], $priyaTok);
[$c2, $b2] = act_token('/expenses/delete.php', ['expense' => (int) $b['record_id']], $priyaTok);
ok($c2 === 200 && $b2['ok'] === true && (int) one('SELECT count(*) FROM expenses WHERE id = :e', ['e' => (int) $b['record_id']]) === 0, 'expense_delete');
[$sc, $d] = [req('GET', '/expenses/' . $e, ['headers' => array_merge(JSONH, $priyaTok)])['code'], null];
ok($sc === 200, 'the screens answer JSON under the token too');

echo "2. The expert's run token\n";
kernel_state(function ($s) { $s['incremental'] = incr(['members' => [['id' => 903, 'member_kind' => 'agent', 'display_name' => 'SMOKE CT expert expenses', 'email' => null, 'business_role' => 'user', 'is_external' => false, 'status' => 'active', 'updated_at' => '2026-01-01T00:00:00Z', 'departments' => [['id' => 7, 'name' => 'AI Strategy', 'is_admin' => false, 'is_primary' => true]]]],
    'access' => [['member_id' => 903, 'role' => 'manager', 'roles' => ['manager', 'user'], 'capability' => 'write', 'scopes' => []]]], '2026-06-02T00:00:00.000000Z'); return $s; });
sync();
[$tok, $relay] = run_token(903, 881);
kernel_state(function ($s) { $s['facts']['881'] = ['valid' => true, 'is_agent' => true, 'member_id' => 903, 'run_id' => 881, 'request_id' => 'req-881', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$h = ['X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay];
[$c, $b] = act_token('/expenses/save.php', ['engagement' => $acme, 'date' => date('Y-m-d'), 'category' => category('meals'), 'amount' => '20.00', 'description' => 'SMOKE expert', 'member' => 26], $h);
ok($c === 403, 'the expert (not Acme\'s manager) may not record for Priya on it: 403');
[$c, $b] = act_token('/engagements/save.php', ['client' => (int) one("SELECT id FROM clients WHERE name = 'SMOKE Acme'"), 'name' => 'SMOKE Expert expense eng', 'kind' => 'time_and_materials', 'start_date' => '2026-01-05', 'start_now' => 'yes'], $h);
$ee = (int) $b['record_id'];
act_token('/engagements/staffing/save.php', ['engagement' => $ee, 'member' => 26, 'role' => role('consultant'), 'valid_from' => '2026-01-05'], $h);
$since = last_activity_id();
[$c, $b] = act_token('/expenses/save.php', ['engagement' => $ee, 'date' => date('Y-m-d'), 'category' => category('meals'), 'amount' => '33.00', 'description' => 'SMOKE expert records', 'member' => 26, 'submit' => '1'], $h);
$x = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $x > 0 && (int) expense_row($x)['member_id'] === 26 && $b['status'] === 'submitted', 'on its own engagement it records and submits an expense for Priya (the acting person); 33.00 is under the threshold');
$log = activity('expense.save', $since);
ok($log[0]['source'] === 'agent' && (int) $log[0]['agent_run_id'] === 881 && json_decode((string) $log[0]['after'], true)['recorded_by'] === 903 && (int) $log[0]['engagement_id'] === $ee, 'its row: source agent, run 881, recorded_by the agent, the engagement');
ok(activity('expense.submit', $since)[0]['source'] === 'agent', 'and the submit row is the agent\'s too');
[$c, $b] = act_token('/expenses/approve.php', ['expense' => $x], $h);
ok($c === 200 && $b['status'] === 'approved', 'expense_approve by the agent on its own engagement: 200 here — on the MCP path the kernel\'s hook pauses it as `other` (Phase 4)');
[$c, $b] = act_token('/expenses/reimburse.php', ['expense' => $x, 'reference' => 'X'], $h);
ok($c === 403, 'the expert has no billing.write: expense_mark_reimbursed → 403');
kernel_state(function ($s) { unset($s['incremental'], $s['facts']); return $s; });
finish();
