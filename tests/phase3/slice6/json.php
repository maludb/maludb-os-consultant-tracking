<?php
/** Proof — JSON mode (spec "Proof", 8): every handler under a signed action token answers {ok, did, record_id, location, …} and logs as `assistant`; a partial update of a draft; the Clerk's run token drafts and adds unbilled as an agent (no pause); the pauses are the kernel's hook, declared by the registry and maludb-os.json (money_out for issue, void, write-off, credit and payment; external_send for send and remind). */
require __DIR__ . '/lib.php';
$w = billing_world(); $acme = $w['acme']; $beta = $w['beta'];
$bea = as_member(28); $marco = as_member(27);
$bTok = ['X-Action-Token: ' . person_token(28)]; $mTok = ['X-Action-Token: ' . person_token(27)]; $pTok = ['X-Action-Token: ' . person_token(26)];
/** One token call, checked: [status, body, the log rows of the event since]. */
function call(string $path, array $form, array $tok, ?string $event = null): array
{
    $since = last_activity_id();
    [$c, $b] = act_token($path, $form, $tok);
    return [$c, $b, $event === null ? [] : activity($event, $since)];
}
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true)['actions'];
$mani = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true);
$cats = array_column($mani['approvals'], 'category', 'action');

echo "1. A person's action token: the manager drafts\n";
act($marco, '/engagements/milestones/deliver.php', ['milestone' => (int) one("SELECT id FROM milestones WHERE name = 'SMOKE M2 Build'")]);
act($marco, '/engagements/milestones/accept.php', ['milestone' => (int) one("SELECT id FROM milestones WHERE name = 'SMOKE M2 Build'"), 'acceptance_note' => 'SMOKE json']);
[$c, $b, $log] = call('/invoices/save.php', ['engagement' => $beta, 'add_unbilled' => 'yes', 'period_end' => '2026-09-30'], $mTok, 'invoice.draft');
$d = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $b['ok'] === true && $d > 0 && isset($b['did']) && $b['location'] === '/invoices/' . $d && $b['refresh'] === 'invoiceChanged' && $b['status'] === 'draft' && $b['total'] === '8000.00' && $b['counts']['milestone_lines'] === 1, 'invoice_draft: {ok, did, record_id, location, refresh, status, total, counts}');
ok(count($log) === 1 && $log[0]['source'] === 'assistant' && $log[0]['agent_run_id'] === null && (int) $log[0]['actor_member_id'] === 27, 'logged as `assistant` for the acting person (no run)');
[$c, $b] = act_token('/invoices/save.php', ['engagement' => $acme], $pTok);
ok($c === 403 && ($b['error']['code'] ?? '') === 'forbidden', 'Priya\'s token: 403 {error: {code: forbidden}}');
[$c, $b, $log] = call('/invoices/save.php', ['invoice' => $d, 'memo' => 'SMOKE json memo', '_partial' => '1'], $mTok, 'invoice.update');
$i = inv($d);
ok($c === 200 && $i['memo'] === 'SMOKE json memo' && $i['po_number'] === 'PO-77' && $i['period_end'] === '2026-09-30' && $i['issue_date'] === date('Y-m-d') && count($log) === 1, 'invoice_update with _partial: the memo changed, the PO, the period and the dates filled from the base row');
[$c, $b, $log] = call('/invoices/lines/save.php', ['invoice' => $d, 'kind' => 'fixed', 'description' => 'SMOKE json fixed', 'unit_price' => '250.00', 'quantity' => '2'], $mTok, 'invoice.line_save');
$fl = (int) ($b['line_id'] ?? 0);
ok($c === 200 && $b['ok'] === true && $fl > 0 && inv($d)['total'] === '8500.00' && count($log) === 1, 'invoice_line_save: a fixed line (2 × 250.00): the total 8,500.00');
[$c, $b, $log] = call('/invoices/lines/remove.php', ['line' => $fl], $mTok, 'invoice.line_remove');
ok($c === 200 && $b['items_released'] === 0 && inv($d)['total'] === '8000.00' && count($log) === 1, 'invoice_line_remove');
$it = (int) one('SELECT id FROM invoice_line_items WHERE invoice_id = :i LIMIT 1', ['i' => $d]);
[$c, $b, $log] = call('/invoices/items/remove.php', ['item' => $it], $mTok, 'invoice.item_remove');
ok($c === 200 && $b['line_removed'] === true && inv($d)['total'] === '0.00' && count($log) === 1, 'invoice_item_remove: the milestone item leaves; its line goes with it (nothing left on it)');
[$c, $b, $log] = call('/invoices/add-unbilled.php', ['invoice' => $d], $mTok, 'invoice.add_unbilled');
ok($c === 200 && $b['counts']['milestone_lines'] === 1 && inv($d)['total'] === '8000.00' && count($log) === 1, 'invoice_add_unbilled: the milestone comes back');
[$c, $b] = act_token('/invoices/save.php', ['engagement' => $beta, 'add_unbilled' => 'no'], $mTok);
[$c, $b, $log] = call('/invoices/delete.php', ['invoice' => (int) $b['record_id']], $mTok, 'invoice.delete');
ok($c === 200 && $b['ok'] === true && count($log) === 1, 'invoice_delete');
[$c, $b] = act_token('/invoices/save.php', ['engagement' => 'x', 'period_start' => 'never'], $mTok);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($b['error']['fields']['period_start']), 'a refusal: 422 {error: {code: invalid, fields}}');
[$c, $b] = act_token('/invoices/issue.php', ['invoice' => $d], $mTok);
ok($c === 403, 'the manager\'s token issues nothing: 403');

echo "2. Billing's token\n";
[$c, $b, $log] = call('/invoices/issue.php', ['invoice' => $d], $bTok, 'invoice.issue');
ok($c === 200 && $b['number'] === 'INV-00007' && $b['status'] === 'issued' && $b['total'] === '8000.00' && $b['currency'] === 'USD' && json_decode((string) $log[0]['after'], true)['amount'] === '8000.00' && $log[0]['source'] === 'assistant', 'invoice_issue: INV-00007, amount and currency in the log');
[$c, $b] = act_token('/invoices/issue.php', ['invoice' => $d], $bTok);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && $b['error']['message'] === 'Invoice INV-00007 is already issued', 'again: 422 with the database\'s sentence');
[$c, $b, $log] = call('/invoices/external-ref.php', ['invoice' => $d, 'external_ref' => 'GL-7'], $bTok, 'invoice.external_ref');
ok($c === 200 && inv($d)['external_ref'] === 'GL-7' && count($log) === 1, 'invoice_external_ref_set');
[$c, $b, $log] = call('/invoices/send.php', ['invoice' => $d], $bTok, 'invoice.send');
ok($c === 200 && $b['recipients'] >= 1 && $b['queued'] >= 1 && count($log) === 1 && !str_contains(json_encode($b), '/i/'), 'invoice_send: the count, no link in the answer');
[$c, $b, $log] = call('/invoices/secure-link.php', ['invoice' => $d], $bTok, 'invoice.secure_link_rotate');
ok($c === 200 && $b['ok'] === true && !preg_match('/[a-f0-9]{48}/', json_encode($b)) && count($log) === 1, 'secure_link_rotate: the answer carries no token');
[$c, $b, $log] = call('/invoices/remind.php', ['invoice' => (int) one("SELECT id FROM invoices WHERE number = 'INV-00005'")], $bTok, 'invoice.remind');
ok($c === 200 && $b['reminder_count'] === 3 && count($log) === 1, 'invoice_remind: the third reminder of INV-00005');
[$c, $b, $log] = call('/payments/save.php', ['invoice' => $d, 'amount' => '100.00', 'method' => 'card', 'reference' => 'SMOKE-JSON'], $bTok, 'payment.record');
$pay = (int) ($b['payment_id'] ?? 0);
ok($c === 200 && $pay > 0 && $b['status'] === 'partially_paid' && json_decode((string) $log[0]['after'], true)['currency'] === 'USD' && json_decode((string) $log[0]['after'], true)['amount'] === '100.00', 'payment_record: partially paid; amount and currency logged');
[$c, $b, $log] = call('/payments/void.php', ['payment' => $pay, 'reason' => 'SMOKE json void'], $bTok, 'payment.void');
ok($c === 200 && in_array($b['status'], ['sent', 'viewed', 'issued'], true), 'payment_void: the invoice is open again (' . ($b['status'] ?? '?') . ')');
q("INSERT INTO payment_claims (invoice_id, claimed_on, method, reference, amount) VALUES (:i, current_date, 'transfer', 'SMOKE-C1', 50), (:i, current_date, 'cash', 'SMOKE-C2', 20)", ['i' => $d]);
$k1 = (int) one("SELECT id FROM payment_claims WHERE reference = 'SMOKE-C1'"); $k2 = (int) one("SELECT id FROM payment_claims WHERE reference = 'SMOKE-C2'");
[$c, $b, $log] = call('/billing/claims/confirm.php', ['claim' => $k1, 'amount' => '50'], $bTok, 'payment_claim.confirm');
ok($c === 200 && $b['amount'] === '50.00' && $b['status'] === 'partially_paid' && count($log) === 1, 'payment_claim_confirm');
[$c, $b, $log] = call('/billing/claims/dismiss.php', ['claim' => $k2, 'reason' => 'SMOKE json'], $bTok, 'payment_claim.dismiss');
ok($c === 200 && $b['status'] === 'dismissed' && count($log) === 1, 'payment_claim_dismiss');
[$c, $b, $log] = call('/credit-notes/save.php', ['invoice' => $d, 'reason' => 'SMOKE json credit'], $bTok, 'credit_note.draft');
$cn = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $cn > 0 && str_starts_with((string) $b['location'], '/credit-notes/' . $cn . '/edit') && count($log) === 1, 'credit_note_draft');
[$c, $b, $log] = call('/credit-notes/lines/save.php', ['credit_note' => $cn, 'description' => 'SMOKE json credit line', 'unit_price' => '75'], $bTok, 'credit_note.line_save');
ok($c === 200 && cn($cn)['total'] === '75.00' && count($log) === 1, 'credit_note_line_save');
[$c, $b, $log] = call('/credit-notes/issue.php', ['credit_note' => $cn], $bTok, 'credit_note.issue');
ok($c === 200 && $b['number'] === 'CN-00003' && count($log) === 1, 'credit_note_issue');
[$c, $b, $log] = call('/credit-notes/apply.php', ['credit_note' => $cn, 'invoice' => $d, 'amount' => '25'], $bTok, 'credit_note.apply');
ok($c === 200 && $b['balance_due'] === '7925.00' && count($log) === 1, 'credit_note_apply: 25.00 off INV-00007');
[$c, $b, $log] = call('/credit-notes/unapply.php', ['credit_note' => $cn, 'invoice' => $d], $bTok, 'credit_note.unapply');
ok($c === 200 && count($log) === 1, 'credit_note_unapply');
[$c, $b, $log] = call('/credit-notes/void.php', ['credit_note' => $cn, 'reason' => 'SMOKE json'], $bTok, 'credit_note.void');
ok($c === 200 && $b['status'] === 'void' && count($log) === 1, 'credit_note_void');
[$c, $b] = act_token('/credit-notes/save.php', ['client' => $w['client']], $bTok);
[$c, $b, $log] = call('/credit-notes/delete.php', ['credit_note' => (int) $b['record_id']], $bTok, 'credit_note.delete');
ok($c === 200 && $b['ok'] === true && count($log) === 1, 'credit_note_delete (a draft by client)');
[$c, $b, $log] = call('/clients/statement-send.php', ['client' => $w['client']], $bTok, 'client.statement_send');
ok($c === 200 && $b['recipients'] >= 1 && count($log) === 1, 'statement_send');
$payClaim = (int) one("SELECT id FROM invoice_payments WHERE invoice_id = :i AND source = 'claim'", ['i' => $d]);
act_token('/payments/void.php', ['payment' => $payClaim, 'reason' => 'SMOKE json clear'], $bTok);
[$c, $b, $log] = call('/invoices/void.php', ['invoice' => $d, 'reason' => 'SMOKE json void'], $bTok, 'invoice.void');
ok($c === 200 && $b['status'] === 'void' && count($log) === 1 && wip($beta) === '8000.00', 'invoice_void: void, the milestone is unbilled again (8,000.00)');
$six = (int) one("SELECT id FROM invoices WHERE number = 'INV-00006'");
[$c, $b, $log] = call('/invoices/write-off.php', ['invoice' => $six, 'reason' => 'SMOKE json write-off'], $bTok, 'invoice.write_off');
ok($c === 200 && $b['status'] === 'written_off' && $b['amount'] === '300.00' && count($log) === 1, 'invoice_write_off: INV-00006, 300.00');
foreach (['/invoices/' => 'invoices', '/billing/unbilled' => 'unbilled', '/payments/' => 'payments', '/billing/claims' => 'claims', '/credit-notes/' => 'credit_notes', '/reports/ar-aging' => 'rows', '/billing/due' => 'due', '/invoices/' . $d . '/preview' => 'document', '/invoices/' . $d => 'invoice'] as $path => $key) {
    $r = req('GET', $path, ['headers' => array_merge(JSONH, $bTok)]);
    $data = json_decode($r['body'], true)['data'] ?? [];
    $shape[] = $r['code'] === 200 && array_key_exists($key, $data);
}
ok(!in_array(false, $shape, true), 'the screens answer JSON under the token: invoices, unbilled, payments, claims, credit notes, aging, due, a preview, an invoice');
$pj = json_encode(json_decode(req('GET', '/invoices/' . $d, ['headers' => array_merge(JSONH, $bTok)])['body'], true));
ok(!str_contains($pj, 'cost_rate') && !str_contains($pj, 'token_hash') && !preg_match('/[a-f0-9]{48}/', $pj), 'an invoice as JSON: no cost, no hash, no token');

echo "3. The Clerk's run token (a Billing agent)\n";
[$tok, $relay] = run_token(40, 883);
kernel_state(function ($s) { $s['facts']['883'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 883, 'request_id' => 'req-883', 'trigger' => 'duty', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$h = ['X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay];
[$c, $b, $log] = call('/invoices/save.php', ['engagement' => $beta, 'add_unbilled' => 'no', 'period_end' => '2026-09-30'], $h, 'invoice.draft');
$cd = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $cd > 0 && $log[0]['source'] === 'agent' && (int) $log[0]['agent_run_id'] === 883 && (int) $log[0]['actor_member_id'] === 40 && (int) one('SELECT created_by FROM invoices WHERE id = :i', ['i' => $cd]) === 40, 'the Clerk drafts: source agent, run 883, created_by the Clerk (no pause: a draft is free)');
[$c, $b, $log] = call('/invoices/add-unbilled.php', ['invoice' => $cd], $h, 'invoice.add_unbilled');
ok($c === 200 && $b['counts']['milestone_lines'] === 1 && $log[0]['source'] === 'agent', 'the Clerk adds the unbilled items (the released milestone): an agent row');
[$c, $b, $log] = call('/invoices/lines/save.php', ['invoice' => $cd, 'kind' => 'fixed', 'description' => 'SMOKE Clerk line', 'unit_price' => '10'], $h, 'invoice.line_save');
ok($c === 200 && $log[0]['source'] === 'agent', 'the Clerk saves a line');
[$c, $b, $log] = call('/invoices/delete.php', ['invoice' => $cd], $h, 'invoice.delete');
ok($c === 200 && $log[0]['source'] === 'agent' && wip($beta) === '8000.00', 'and deletes its own draft (the kernel pauses `deletion` before this handler on the MCP path)');

echo "4. What pauses an agent is declared\n";
$billing = array_filter($reg, static fn (array $a): bool => $a['section'] === 'Billing (slice 6)' || $a['action'] === 'statement_send');
ok(count($billing) === 26 && count(array_filter($billing, static fn (array $a): bool => $a['built'])) === 26, 'the registry holds the 26 billing actions (25 and statement_send), every one built');
$bad = [];
foreach ($billing as $name => $a) {
    if (($a['approval'] ?? null) !== ($cats[$name] ?? null)) { $bad[] = $name . ': ' . json_encode($a['approval']) . ' vs ' . json_encode($cats[$name] ?? null); }
}
ok($bad === [], 'each action\'s approval category in the registry is exactly the one in maludb-os.json approvals[]' . ($bad ? ' — ' . implode('; ', $bad) : ''));
ok(($reg['invoice_issue']['approval'] ?? '') === 'money_out' && ($reg['invoice_send']['approval'] ?? '') === 'external_send' && ($reg['invoice_remind']['approval'] ?? '') === 'external_send' && ($reg['statement_send']['approval'] ?? '') === 'external_send'
    && ($reg['invoice_void']['approval'] ?? '') === 'money_out' && ($reg['invoice_write_off']['approval'] ?? '') === 'money_out' && ($reg['payment_record']['approval'] ?? '') === 'money_out' && ($reg['credit_note_issue']['approval'] ?? '') === 'money_out'
    && ($reg['invoice_delete']['approval'] ?? '') === 'deletion' && ($reg['payment_claim_dismiss']['approval'] ?? '') === 'other', 'issuing, voiding, writing off, crediting and paying pause as money_out; sending, reminding and the statement as external_send; deleting a draft as deletion; dismissing a claim as other');
ok($reg['invoice_draft']['approval'] === null && $reg['invoice_add_unbilled']['approval'] === null && $reg['invoice_line_save']['approval'] === null && $reg['credit_note_draft']['approval'] === null, 'drafting an invoice, adding the unbilled, a line and a credit note draft do not pause (the Clerk does them alone)');
ok(count(array_filter($reg, static fn (array $a): bool => str_contains($a['action'], 'refund') || str_contains($a['action'], 'payout') || str_contains($a['action'], 'pay_out'))) === 0, 'no money-out action exists here: nothing pays anyone (D14) — no refund, no payout');
kernel_state(function ($s) { unset($s['facts']); return $s; });
finish();
