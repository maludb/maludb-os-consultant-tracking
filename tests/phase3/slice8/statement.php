<?php
/**
 * Proof — the OS statement poll (spec "Proof", 4): with the poll on, the worker pulls the kernel's months (the closed one kept verbatim, the open one re-pulled while it moves) and allocates ONLY the closed one — the Researcher's agent 60/40 across Acme and Beta
 * (four drafts), billing told with the remainder no rule placed, the managers told what landed; the open month is ignored until the OS closes it; a second pass makes nothing twice; the kernel down or a changed document for an allocated month is said in the report and changes nothing;
 * the poll off asks the kernel nothing. Hand-derived: 2026-08 = Researcher on Anthropic 100.0000 + on OpenAI 30.3350, the Writer on Anthropic 50.0000 (no rule); 60 % / 40 %.
 */
require __DIR__ . '/lib.php';
[$acme, $beta] = usage_world();
$marco = as_member(27); $bea = as_member(28);
$docs = ledger_fixture();
$klog = need('FAKE_KERNEL_STATE') . '.ledger';
@unlink($klog);
drain_outbox();
foreach ([['SMOKE s8 rule A', $acme, '60'], ['SMOKE s8 rule B', $beta, '40']] as [$name, $e, $pct]) {
    [$c, $b] = act($marco, '/settings/allocation-rules/save.php', ['name' => $name, 'engagement' => $e, 'agent' => 901, 'share_pct' => $pct, 'valid_from' => '2026-01-01']);
    if ($c !== 200) { fwrite(STDERR, "rule failed: " . json_encode($b) . "\n"); }
}
$asked = fn (): array => array_values(array_filter(array_map('trim', is_file($klog) ? file($klog) : [])));

echo "1. The poll off asks the kernel nothing\n";
q('UPDATE ct_settings SET ai_statement_poll = false WHERE id = 1');
[$code, $rep] = worker('statement');
ok($code === 0 && step_counts($rep, 'statement')['polled'] === false && $asked() === [] && (int) one('SELECT count(*) FROM ai_statements') === 0, 'ai_statement_poll off: polled false, the kernel was not asked, nothing pulled');
q('UPDATE ct_settings SET ai_statement_poll = true WHERE id = 1');

echo "2. The first pass: pull both, allocate the closed month\n";
$since = last_activity_id();
[$code, $rep] = worker('statement', hours_from_now(0));
$c = step_counts($rep, 'statement');
ok($code === 0 && $c['polled'] === true && $c['pulled'] === 2 && $c['allocated'] === 1 && $c['charges'] === 4 && $c['open'] === 1 && $c['error'] === null, 'statement: two months pulled, one allocated (the closed one) into four draft charges, one still open, no error');
$aug = statement_row('2026-08'); $sep = statement_row('2026-09');
ok($aug['kernel_status'] === 'closed' && $aug['status'] === 'allocated' && $sep['kernel_status'] === 'open' && $sep['status'] === 'pulled' && json_decode($aug['document'], true) == $docs['2026-08'], '2026-08: closed in the OS, allocated here, kept verbatim; 2026-09: open, pulled, NOT allocated');
$ch = q("SELECT engagement_id, provider_id, billable_amount, status, source, created_by FROM usage_charges WHERE statement_id = :s ORDER BY engagement_id, billable_amount", ['s' => $aug['id']]);
$byEng = [];
foreach ($ch as $x) { $byEng[$x['engagement_id']][] = $x['billable_amount']; }
ok(count($ch) === 4 && array_unique(array_column($ch, 'status')) === ['draft'] && array_unique(array_column($ch, 'source')) === ['kernel_ledger'] && array_unique(array_column($ch, 'created_by')) === [null] && $byEng === [$acme => ['18.20', '60.00'], $beta => ['12.13', '40.00']], 'four drafts of source kernel_ledger, no creator: Acme 60.00 (Anthropic) + 18.20 (OpenAI), Beta 40.00 + 12.13 — each piece rounded once');
pdo()->exec("SELECT set_config('app.member_id', '1', false)");
$sum = array_column(q('SELECT * FROM ct_ai_statement_summary(:s)', ['s' => $aug['id']]), null, 'provider');
ok($sum['anthropic']['remainder'] === '50.00' && $sum['openai']['statement_amount'] === '30.34' && $sum['openai']['remainder'] === '0.01', 'the remainder no rule placed, in cents: 50.00 on Anthropic (the Writer), 0.01 on OpenAI (30.34 less the 18.20 + 12.13 placed)');
ok((int) one("SELECT count(*) FROM usage_charges WHERE statement_id = :s", ['s' => $sep['id']]) === 0, 'the open month made no charge');
$pull = activity('ai_statement.pull', $since); $alloc = activity('ai_statement.allocate', $since);
$ap = json_decode((string) $alloc[0]['after'], true);
ok(count($pull) === 2 && $pull[0]['source'] === 'cron' && $pull[0]['actor_member_id'] === null && count($alloc) === 1 && $alloc[0]['source'] === 'cron' && $ap['period'] === '2026-08' && $ap['charges'] === 4 && $ap['currency'] === 'USD' && abs((float) $ap['remainder'] - 50.01) < 0.001 && abs((float) $ap['allocated_amount'] - 130.33) < 0.001 && $ap['via'] === 'worker', 'ai_statement.pull (two) and .allocate (one) logged: cron, no actor, period, charges, allocated 130.33, the remainder, currency');
$bn = q("SELECT member_id, title, body FROM notifications WHERE kind = 'ai_statement_allocated' ORDER BY member_id");
$byM = array_column($bn, null, 'member_id');
ok(array_keys($byM) === [1, 27, 28] && str_contains($byM[28]['title'], '2026-08') && str_contains($byM[28]['title'], '4 draft charges') && preg_match('/remainder no rule placed: 50\.0\d USD of 180\.3\d USD/', $byM[28]['body']) === 1 && str_contains($byM[28]['body'], '/usage/os?period=2026-08'), 'billing (Bea, the owner) told with the remainder and the month\'s total and a link; the manager (Marco) too');
ok(str_contains($byM[27]['title'], 'placed 4 draft charges on') && str_contains($byM[27]['body'], '/usage/?statement=' . $aug['id']), 'Marco: "placed 4 draft charges on …" and a link to approve them');

echo "3. A second pass makes nothing twice\n";
@unlink($klog);
[$code, $rep] = worker('statement', hours_from_now(1));
$c = step_counts($rep, 'statement');
ok($c['allocated'] === 0 && $c['charges'] === 0 && $c['pulled'] === 1 && $asked() === ['list', '2026-09'], 'an hour later: the open month is re-pulled (it moves until the close), nothing allocated, the closed month is not asked for again');
ok((int) one("SELECT count(*) FROM usage_charges WHERE statement_id = :s", ['s' => $aug['id']]) === 4 && (int) one("SELECT count(*) FROM notifications WHERE kind = 'ai_statement_allocated'") === 3, 'still four charges, still three notices');

echo "4. The OS closes the open month\n";
$docs['2026-09'] = ledger_doc('2026-09', 'closed', [ledger_line('anthropic', 901, 'Researcher', 7, 'AI Strategy', '12.5000')]);
set_ledger($docs);
[$code, $rep] = worker('statement', hours_from_now(2));
$c = step_counts($rep, 'statement');
$sep = statement_row('2026-09');
ok($c['pulled'] === 1 && $c['allocated'] === 1 && $c['charges'] === 2 && $c['open'] === 0 && $sep['status'] === 'allocated' && array_column(q("SELECT billable_amount FROM usage_charges WHERE statement_id = :s ORDER BY billable_amount", ['s' => $sep['id']]), 'billable_amount') === ['5.00', '7.50'], 'the month closed: pulled, allocated 7.50 + 5.00 (60/40 of 12.5000)');

echo "5. The kernel down; a document that changed after allocation\n";
set_ledger($docs, 'down');
$since = last_activity_id();
[$code, $rep] = worker('statement', hours_from_now(3));
$c = step_counts($rep, 'statement');
ok($code === 0 && $c['pulled'] === 0 && $c['allocated'] === 0 && str_contains((string) $c['error'], 'The OS answered 503') && $rep['errors'] === [], 'the kernel answers 503: said in the report ("The OS answered 503…"), the step did not throw, nothing changed');
$changed = $docs;
$changed['2026-08']['lines'][0]['amount'] = '101.0000';
set_ledger($changed);
@unlink($klog);
[$code, $rep] = worker('statement', hours_from_now(4));
$c = step_counts($rep, 'statement');
ok($c['pulled'] === 0 && $c['allocated'] === 0 && $c['error'] === null && $asked() === ['list'] && statement_row('2026-08')['document_hash'] === $aug['document_hash'] && (int) one("SELECT count(*) FROM usage_charges WHERE statement_id = :s", ['s' => $aug['id']]) === 4, 'a closed month the OS later changes is not asked for again: the held document and its four charges stay as they were (the database would refuse it by name in a hand pull)');
set_ledger($docs);
clear_ledger();
finish();
