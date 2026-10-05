<?php
/**
 * Proof — agent dispatch retries (spec "Proof", 8, and what it owes the kernel's K8/K16): a hand-off to an agent that FAILED (the kernel did not answer) is retried through the chat endpoint, at most three attempts, oldest first; a dispatch the kernel already began
 * a run for is looked at (GET ?run=), never started a second time; a refusal ends the retries; an answered or exhausted row is left alone. The reply is kept to 200 characters; the log has the kind, the status and the attempts — never the reply.
 */
require __DIR__ . '/lib.php';
$w = report_world(); $acme = $w['acme'];
$chatLog = need('FAKE_KERNEL_STATE') . '.chat';
file_put_contents($chatLog, '');
function chat_calls(): array { return log_lines(need('FAKE_KERNEL_STATE') . '.chat'); }
function mk_dispatch(string $kind, string $status, int $attempts, ?int $run = null, string $req = 'ct-dispatch-x'): int
{
    global $acme;
    return (int) one("INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, acting_member_id, run_id, request_id, status, attempts) VALUES ('engagement', :e, 40, :k, 'chat', 27, :r, :q, :s, :a) RETURNING id",
        ['e' => $acme, 'k' => $kind, 'r' => $run, 'q' => $req, 's' => $status, 'a' => $attempts]);
}
$d1 = mk_dispatch('budget_crossed', 'failed', 1, null, 'ct-d1');
$d2 = mk_dispatch('statement_closed', 'failed', 3);
$d3 = mk_dispatch('claim_submitted', 'failed', 1, 55);
$d4 = mk_dispatch('invoice_viewed', 'answered', 1);
$st = fn (int $d): array => q('SELECT * FROM agent_dispatches WHERE id = :d', ['d' => $d])[0];
kernel_state(function (array $s) { $s['chat'] = ['run_id' => 71, 'status' => 'succeeded', 'finished' => true, 'reply' => str_repeat('R', 400), 'actions' => []]; unset($s['chat_status']); return $s; });
$since = last_activity_id();

echo "1. The kernel answers\n";
[$code, $rep] = worker('dispatch');
$c = step_counts($rep, 'dispatch');
ok($code === 0 && $c === ['retried' => 2, 'answered' => 2, 'failed' => 0, 'refused' => 0], 'dispatch: two failed rows with attempts left were retried (D1, D3) and both answered; D2 (three attempts) and D4 (answered) left alone');
$r1 = $st($d1);
ok($r1['status'] === 'answered' && $r1['attempts'] === 2 && (int) $r1['run_id'] === 71 && $r1['answered_at'] !== null && mb_strlen((string) $r1['reply_excerpt']) === 200 && $r1['detail'] === null, 'D1: answered on its second attempt, the run id kept, the reply cut to 200 characters');
$calls = chat_calls();
$post = array_values(array_filter($calls, fn ($x) => $x['agent'] !== null));
$get = array_values(array_filter($calls, fn ($x) => $x['agent'] === null));
ok(count($post) === 1 && $post[0]['agent'] === '40' && $post[0]['acting'] === '27' && str_contains((string) $post[0]['utterance'], 'budget threshold') && str_contains((string) $post[0]['utterance'], 'engagement ' . $acme), 'D1 went through the chat endpoint: agent 40, acting as member 27, in words about the record (a budget threshold, the engagement)');
ok(count($get) === 1 && $st($d3)['status'] === 'answered' && (int) $st($d3)['run_id'] === 71 && $st($d3)['attempts'] === 2, 'D3 already had a run (55): the kernel was LOOKED AT (GET ?run=), no second turn started for it');
ok($st($d2)['status'] === 'failed' && $st($d2)['attempts'] === 3 && $st($d4)['status'] === 'answered' && $st($d4)['attempts'] === 1, 'D2 (three attempts used) and D4 (answered) untouched');
$log = activity('agent.dispatch', $since);
$a = json_decode((string) $log[0]['after'], true);
ok(count($log) === 2 && $log[0]['source'] === 'cron' && $log[0]['actor_member_id'] === null && $a['kind'] === 'budget_crossed' && $a['status'] === 'answered' && $a['attempts'] === 2 && $a['agent_member_id'] === 40 && !str_contains(json_encode($log), 'RRRR'), 'agent.dispatch logged: cron, the kind, status, attempts — never the reply');
[$code, $rep] = worker('dispatch');
ok(step_counts($rep, 'dispatch')['retried'] === 0 && count(chat_calls()) === 2, 'another pass: nothing to retry, the kernel is not called');

echo "2. The kernel does not answer; a refusal; a turn that waits for approval\n";
$d5 = mk_dispatch('timesheet_submitted', 'failed', 1);
kernel_state(function (array $s) { $s['chat_status'] = 500; return $s; });
[$code, $rep] = worker('dispatch');
$r5 = $st($d5);
ok(step_counts($rep, 'dispatch') === ['retried' => 1, 'answered' => 0, 'failed' => 1, 'refused' => 0] && $r5['status'] === 'failed' && $r5['attempts'] === 2 && str_contains((string) $r5['detail'], 'HTTP 500'), 'the kernel answers 500: failed again, attempts 2, the detail says so');
[$code, $rep] = worker('dispatch');
$r5 = $st($d5);
ok($r5['status'] === 'failed' && $r5['attempts'] === 3, 'the third attempt: failed, attempts 3');
[$code, $rep] = worker('dispatch');
ok(step_counts($rep, 'dispatch')['retried'] === 0 && $st($d5)['attempts'] === 3, 'a fourth is never made: three attempts at most');
$d6 = mk_dispatch('ask', 'failed', 1);
kernel_state(function (array $s) { $s['chat_status'] = 409; $s['chat_message'] = 'The expert is busy with another turn.'; return $s; });
[$code, $rep] = worker('dispatch');
$r6 = $st($d6);
ok(step_counts($rep, 'dispatch')['refused'] === 1 && $r6['status'] === 'refused' && $r6['detail'] === 'The expert is busy with another turn.' && $r6['attempts'] === 2, 'the kernel refuses (409 busy): refused with its words — a refusal ends the retries');
[$code, $rep] = worker('dispatch');
ok(step_counts($rep, 'dispatch')['retried'] === 0, 'a refused dispatch is not retried');
$d7 = mk_dispatch('statement_closed', 'failed', 1);
kernel_state(function (array $s) { unset($s['chat_status'], $s['chat_message']); $s['chat'] = ['run_id' => 88, 'status' => 'awaiting_approval', 'finished' => false, 'reply' => '', 'actions' => []]; return $s; });
worker('dispatch');
ok($st($d7)['status'] === 'awaiting_approval' && (int) $st($d7)['run_id'] === 88 && $st($d7)['answered_at'] !== null, 'a turn paused for a person\'s approval: awaiting_approval, the run id kept');
kernel_state(function (array $s) { unset($s['chat'], $s['chat_status']); return $s; });
finish();
