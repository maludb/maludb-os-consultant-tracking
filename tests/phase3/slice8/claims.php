<?php
/**
 * Proof — claims left waiting (spec "Proof", 8): a payment claim a client made on the secure link and nobody decided in 14 days tells billing again (`claim_submitted`) — once per fortnight of waiting, never on a younger claim, never once decided.
 */
require __DIR__ . '/lib.php';
$w = report_world(); $acme = $w['acme'];
$bea = as_member(28);
$today = today();
[$c, $b] = draft($bea, $acme, ['add_unbilled' => 'no', 'po_number' => 'PO-S8C']);
$d = (int) $b['record_id'];
act($bea, '/invoices/lines/save.php', ['invoice' => $d, 'kind' => 'fixed', 'description' => 'SMOKE s8 claim fee', 'unit_price' => '400']);
[$c, $b] = act($bea, '/invoices/issue.php', ['invoice' => $d]);
$number = (string) $b['number'];
q("INSERT INTO payment_claims (invoice_id, claimed_on, method, reference, amount, status, created_at) VALUES (:i, :d, 'transfer', 'SMOKE s8 old claim', 400, 'submitted', now() - interval '15 days')", ['i' => $d, 'd' => plus_days($today, -16)]);
$old = (int) one("SELECT id FROM payment_claims WHERE reference = 'SMOKE s8 old claim'");
q("INSERT INTO payment_claims (invoice_id, claimed_on, method, reference, status, created_at) VALUES (:i, :d, 'cash', 'SMOKE s8 new claim', 'submitted', now() - interval '3 days')", ['i' => $d, 'd' => plus_days($today, -3)]);
$told = fn (): array => q("SELECT member_id, title, body FROM notifications WHERE kind = 'claim_submitted' AND record_type = 'invoice' AND record_id = :i ORDER BY member_id, id", ['i' => $d]);
drain_outbox();
$since = last_activity_id();

echo "1. A fortnight of waiting\n";
[$code, $rep] = worker('claims');
ok($code === 0 && step_counts($rep, 'claims') === ['told' => 1], 'claims: one claim has waited 14 days (the 3-day-old one is not counted) — told');
$t = $told();
ok(array_column($t, 'member_id') === [1, 28] && $t[0]['title'] === "A client's payment claim on $number is still waiting" && str_contains($t[0]['body'], 'It has waited 14 days or more') && str_contains($t[0]['body'], '/billing/claims'), 'billing (the owner, Bea) told: "A client\'s payment claim on ' . $number . ' is still waiting", a link to decide it');
$log = activity('payment_claim.age_notice', $since);
ok(count($log) === 1 && $log[0]['source'] === 'cron' && (int) $log[0]['entity_id'] === $old && (int) $log[0]['invoice_id'] === $d && json_decode((string) $log[0]['after'], true)['fortnights'] === '1', 'payment_claim.age_notice logged: cron, the claim, the invoice, the fortnight');
[$code, $rep] = worker('claims');
ok(step_counts($rep, 'claims') === ['told' => 0] && count($told()) === 2, 'a second pass the same fortnight: told no more');

echo "2. The next fortnight; a decided claim\n";
[$code, $rep] = worker('claims', hours_from_now(14 * 24));
ok(step_counts($rep, 'claims')['told'] >= 1 && count($told()) >= 4, 'two weeks on: the claim (now 29 days old) tells billing again');
q("UPDATE payment_claims SET status = 'dismissed', decided_by = 28, decided_at = now() WHERE id = :c", ['c' => $old]);
$n = count($told());
[$code, $rep] = worker('claims', hours_from_now(28 * 24));
ok(step_counts($rep, 'claims')['told'] === 1 && count($told()) === $n + 2, 'a decided claim is never chased: four weeks on only the young claim (now 31 days old) tells billing, for its second fortnight');
$still = (int) one("SELECT count(*) FROM activity_log WHERE action = 'payment_claim.age_notice' AND entity_id = :c", ['c' => $old]);
ok($still === 2, 'the dismissed claim produced exactly two notices in all (one per fortnight it waited)');
finish();
