<?php
/**
 * Proof — the reads through the kernel (K7) (spec "Proof", 7). The ledger's receipts (`gl.invoice_receipts`, G1): a connection nobody approved is a 403 `no_connection` said once a day and nothing changed; an approved one records a payment of source `ledger` on the
 * invoice the receipt names by NUMBER, once per (reference, received date) — never again, even when a person voids it —, sets the invoice's `external_ref` when the ledger names one, refuses what the invoice cannot take. HR's paid reimbursements (`hr.reimbursements_paid`, H2)
 * mark the expense reimbursed with the pay run's reference through the database's verb. Everything here is the fake kernel's; Consultant Tracking never writes to another application.
 */
require __DIR__ . '/lib.php';
$w = report_world();
$bea = as_member(28);
$today = today();
$inv = (int) invoice_by_number('INV-00001')['id'];
$total = (string) inv($inv)['total'];
function receipt(string $number, string $amount, string $ref, array $extra = []): array { return $extra + ['external_ref' => $number, 'received_on' => today(), 'amount' => $amount, 'currency' => 'USD', 'method' => 'transfer', 'reference' => $ref]; }
function gl(array $rows, string $mode = 'ok'): void { set_reads(['gl' => ['invoice_receipts' => ['mode' => $mode, 'result' => ['schema' => 'os.gl-receipts/1', 'rows' => $rows]]]] + (kernel_state()['reads'] ?? [])); }
drain_outbox(); reset_fakes();

echo "1. No connection approved\n";
$since = last_activity_id();
[$code, $rep] = worker('receipts', $today . 'T07:00:00Z');
$c = step_counts($rep, 'receipts');
ok($code === 0 && $c['status'] === 403 && $c['code'] === 'no_connection' && $c['recorded'] === 0 && $rep['errors'] === [], 'receipts: the kernel answers 403 no_connection; the step says so and does nothing (exit 0, no error)');
$r = reads_log();
ok(count($r) === 1 && $r[0]['provider'] === 'gl' && $r[0]['tool'] === 'invoice_receipts' && $r[0]['argument_keys'] === ['from', 'to'] && $r[0]['arguments']['to'] === $today && $r[0]['arguments']['from'] === plus_days($today, -60), 'the read: provider gl, tool invoice_receipts, arguments from (60 days ago) and to (today) — nothing else leaves');
$log = activity('share.read', $since);
$a = json_decode((string) $log[0]['after'], true); ksort($a);
ok(count($log) === 1 && $log[0]['source'] === 'cron' && $log[0]['actor_member_id'] === null && $a === ['code' => 'no_connection', 'direction' => 'out', 'keys' => ['from', 'to'], 'provider' => 'gl', 'rows' => null, 'status' => 403, 'tool' => 'invoice_receipts'], 'share.read logged: the provider, the tool, the argument keys, the outcome — never an answer');
worker('receipts', $today . 'T08:00:00Z');
ok(count(activity('share.read', $since)) === 1 && count(reads_log()) === 2, 'asked again an hour later: the kernel is asked, but the refusal is logged once a day');

echo "2. A receipt: a payment of source ledger\n";
gl([receipt('INV-00001', '1000.00', 'BANK-77', ['ledger_ref' => 'GL-INV-9001'])]);
$since = last_activity_id();
[$code, $rep] = worker('receipts', $today . 'T09:00:00Z');
$c = step_counts($rep, 'receipts');
$p = q("SELECT * FROM invoice_payments WHERE invoice_id = :i AND source = 'ledger'", ['i' => $inv]);
$i = inv($inv);
ok($c['status'] === 200 && $c['rows'] === 1 && $c['recorded'] === 1 && $c['refs_set'] === 1 && count($p) === 1 && $p[0]['amount'] === '1000.00' && $p[0]['reference'] === 'BANK-77' && $p[0]['paid_on'] === $today && $p[0]['method'] === 'transfer' && $p[0]['recorded_by'] === null && $p[0]['status'] === 'recorded' && trim($p[0]['currency']) === 'USD', 'one payment: 1,000.00 USD, source ledger, reference BANK-77, the received day, recorded by nobody');
ok($i['status'] === 'partially_paid' && $i['amount_paid'] === '1000.00' && $i['balance_due'] === bcsubx($total, '1000.00') && $i['external_ref'] === 'GL-INV-9001' && $i['paid_at'] === null, 'the invoice: partially paid, 1,000.00 paid, the balance ' . $i['balance_due'] . ', external_ref set to what the ledger calls it (GL-INV-9001)');
$pl = activity('payment.record', $since); $xl = activity('invoice.external_ref', $since);
$pa = json_decode((string) $pl[0]['after'], true);
ok(count($pl) === 1 && $pl[0]['source'] === 'cron' && $pl[0]['actor_member_id'] === null && (int) $pl[0]['invoice_id'] === $inv && $pa['amount'] === '1000.00' && $pa['currency'] === 'USD' && $pa['source'] === 'ledger' && $pa['via'] === 'ledger' && $pa['invoice_status'] === 'partially_paid' && count($xl) === 1 && $xl[0]['source'] === 'cron', 'payment.record logged (cron, source ledger, amount and currency) and invoice.external_ref');
$a = json_decode((string) activity('share.read', $since)[0]['after'], true);
ok($a['status'] === 200 && $a['rows'] === 1 && $a['code'] === null && !str_contains(json_encode(activity('share.read', $since)), 'BANK-77'), 'share.read: status 200, one row answered — the answer itself is not in the log');

echo "3. The same receipt again, a voided one, what cannot be taken\n";
[$code, $rep] = worker('receipts', $today . 'T10:00:00Z');
$c = step_counts($rep, 'receipts');
ok($c['recorded'] === 0 && $c['duplicates'] === 1 && (int) one("SELECT count(*) FROM invoice_payments WHERE invoice_id = :i", ['i' => $inv]) === 1, 'the same receipt again: a duplicate, nothing recorded (one payment on the invoice)');
$pid = (int) $p[0]['id'];
[$cc, $bb] = act($bea, '/payments/void.php', ['payment' => $pid, 'reason' => 'SMOKE keyed twice by the bank']);
[$code, $rep] = worker('receipts', $today . 'T11:00:00Z');
ok($cc === 200 && step_counts($rep, 'receipts')['duplicates'] === 1 && step_counts($rep, 'receipts')['recorded'] === 0 && inv($inv)['amount_paid'] === '0.00', 'a payment a person VOIDED is not recorded again: the receipt is a duplicate of it — the person decided');
gl([receipt('INV-00001', '1000.00', 'BANK-79'), receipt('INV-99999', '5.00', 'BANK-X'), receipt('INV-00001', '5.00', 'BANK-EUR', ['currency' => 'EUR']), receipt('INV-00001', '9999999.00', 'BANK-BIG')]);
[$code, $rep] = worker('receipts', $today . 'T12:00:00Z');
$c = step_counts($rep, 'receipts');
ok($c['rows'] === 4 && $c['recorded'] === 1 && $c['unknown'] === 1 && $c['refused'] === 2 && (int) one("SELECT count(*) FROM invoice_payments WHERE invoice_id = :i AND status = 'recorded'", ['i' => $inv]) === 1, 'four receipts: one recorded (BANK-79), one for a number we do not have (unknown), two refused (another currency; more than is owed — the database\'s guard)');

echo "4. The rest arrives: the invoice is paid\n";
gl([receipt('INV-00001', '1000.00', 'BANK-79'), receipt('INV-00001', bcsubx($total, '1000.00'), 'BANK-80')]);
$since = last_activity_id();
[$code, $rep] = worker('receipts', $today . 'T13:00:00Z');
$c = step_counts($rep, 'receipts');
$i = inv($inv);
ok($c['recorded'] === 1 && $c['duplicates'] === 1 && $i['status'] === 'paid' && $i['balance_due'] === '0.00' && $i['paid_at'] !== null, 'the second receipt pays it: paid, balance 0.00, paid_at stamped (the first was a duplicate)');
$told = q("SELECT member_id FROM notifications WHERE kind = 'invoice_paid' AND record_id = :i ORDER BY member_id", ['i' => $inv]);
ok(array_column($told, 'member_id') === [1, 27, 28], 'billing and the manager are told it is paid (invoice_paid), once each');
worker('receipts', $today . 'T14:00:00Z');
ok((int) one("SELECT count(*) FROM notifications WHERE kind = 'invoice_paid' AND record_id = :i", ['i' => $inv]) === 3, 'and not again');

echo "5. The provider fails\n";
gl([receipt('INV-00002', '50.00', 'BANK-FAIL')], 'provider_failed');
[$code, $rep] = worker('receipts', $today . 'T15:00:00Z');
$c = step_counts($rep, 'receipts');
ok($code === 0 && $c['status'] === 502 && $c['code'] === 'provider_failed' && $c['recorded'] === 0 && (int) one("SELECT count(*) FROM invoice_payments WHERE reference = 'BANK-FAIL'") === 0, 'a 502 provider_failed: said, nothing changed');

echo "6. HR's paid reimbursements (H2)\n";
$x = q("SELECT e.number, e.member_id, e.amount, e.currency FROM expenses e WHERE e.status = 'approved' AND e.paid_with = 'personal' AND e.reimbursed_at IS NULL ORDER BY e.id LIMIT 1")[0];
$done = (string) one("SELECT number FROM expenses WHERE reimbursed_at IS NOT NULL ORDER BY id LIMIT 1");
$since = last_activity_id();
[$code, $rep] = worker('reimbursements', $today . 'T07:00:00Z');
ok(step_counts($rep, 'reimbursements')['code'] === 'no_connection' && step_counts($rep, 'reimbursements')['marked'] === 0, 'HR not connected: no_connection, nothing marked');
set_reads(['hr' => ['reimbursements_paid' => ['mode' => 'ok', 'result' => ['rows' => [['expense_number' => $x['number'], 'reference' => 'PAYRUN-42'], ['expense_number' => 'EXP-999999', 'reference' => 'PAYRUN-42'], ['expense_number' => $done, 'reference' => 'PAYRUN-41']]]]]] + (kernel_state()['reads'] ?? []));
[$code, $rep] = worker('reimbursements', $today . 'T08:00:00Z');
$c = step_counts($rep, 'reimbursements');
$e = q('SELECT reimbursed_at, reimbursed_ref FROM expenses WHERE number = :n', ['n' => $x['number']])[0];
ok($c['rows'] === 3 && $c['marked'] === 1 && $c['unknown'] === 1 && $c['already'] === 1 && $c['refused'] === 0 && $e['reimbursed_at'] !== null && $e['reimbursed_ref'] === 'PAYRUN-42', "HR's paid list: " . $x['number'] . ' marked reimbursed with PAYRUN-42; the unknown number and the one already reimbursed are counted and left');
$el = activity('expense.reimburse', $since);
$ea = json_decode((string) $el[0]['after'], true);
ok(count($el) === 1 && $el[0]['source'] === 'cron' && $el[0]['actor_member_id'] === null && $ea['via'] === 'hr' && $ea['reference'] === 'PAYRUN-42' && $ea['amount'] === $x['amount'] && $ea['currency'] === trim($x['currency']) && $ea['reimbursed'] === true, 'expense.reimburse logged: cron, via hr, the reference, amount and currency');
$n = q("SELECT body FROM notifications WHERE kind = 'expense_reimbursed' AND member_id = :m ORDER BY id DESC LIMIT 1", ['m' => $x['member_id']]);
ok($n !== [] && $n[0]['body'] === 'Reference: PAYRUN-42', 'the person is told, with the reference');
[$code, $rep] = worker('reimbursements', $today . 'T09:00:00Z');
ok(step_counts($rep, 'reimbursements')['marked'] === 0 && step_counts($rep, 'reimbursements')['already'] === 2 && count(activity('expense.reimburse', $since)) === 1, 'again: nothing marked twice');
set_reads([]);
finish();

/** Decimal subtraction on two money strings (no bcmath here). */
function bcsubx(string $a, string $b): string { return number_format(round(((float) $a - (float) $b) * 100) / 100, 2, '.', ''); }
