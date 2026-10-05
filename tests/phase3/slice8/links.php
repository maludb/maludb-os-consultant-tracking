<?php
/**
 * Proof — the secure link's expiry (spec "Proof", 8): the invoice's live link dies `link_lifetime_days` after the day it was PAID — the worker sets the expiry once, the invoice paid and the link without one; an unpaid invoice's link, and a link
 * a later send rotated away, are not touched; a link past its expiry answers the one page ("This link has expired…") and no invoice; the setting is read when the expiry is set.
 */
require __DIR__ . '/lib.php';
$w = report_world(); $acme = $w['acme']; $client = billing_world()['client'];
$bea = as_member(28);
$today = today();
if ((int) one("SELECT count(*) FROM client_contacts WHERE client_id = :c AND receives_invoices AND email = 'ap@acme.example.invalid'", ['c' => $client]) === 0) { bill_contact($client, 'ap@acme.example.invalid'); }
function linked_invoice(string $jar, int $engagement, string $price): array
{
    [$c, $b] = draft($jar, $engagement, ['add_unbilled' => 'no', 'po_number' => 'PO-S8L']);
    $d = (int) $b['record_id'];
    act($jar, '/invoices/lines/save.php', ['invoice' => $d, 'kind' => 'fixed', 'description' => 'SMOKE s8 link fee', 'unit_price' => $price]);
    act($jar, '/invoices/issue.php', ['invoice' => $d]);
    act($jar, '/invoices/send.php', ['invoice' => $d]);
    $rows = outbox_of('invoice', null, $d);
    return [$d, token_in((string) end($rows)['body'])];
}
worker('links', $today . 'T06:00:00Z');       // settle whatever earlier proofs paid, so the counts below are this proof\'s own
[$P, $tokP] = linked_invoice($bea, $acme, '300');           // will be paid
[$U, $tokU] = linked_invoice($bea, $acme, '200');           // stays unpaid
[$R, $tokR1] = linked_invoice($bea, $acme, '100');          // paid, but its first link is rotated away by a second send
act($bea, '/invoices/send.php', ['invoice' => $R]);
$rr = outbox_of('invoice', null, $R); $tokR2 = token_in((string) end($rr)['body']);
ok(visitor('/i/' . $tokP)['code'] === 200 && visitor('/i/' . $tokU)['code'] === 200 && visitor('/i/' . $tokR1)['code'] === 404 && visitor('/i/' . $tokR2)['code'] === 200, 'three invoices sent: their links open (R\'s first was rotated away by its second send)');
$live = fn (int $i): array => q('SELECT expires_at, rotated_at FROM invoice_links_secure WHERE invoice_id = :i ORDER BY id', ['i' => $i]);
ok(array_unique(array_column($live($P), 'expires_at')) === [null], 'no link has an expiry while its invoice is open');
$since = last_activity_id();
[$code, $rep] = worker('links', $today . 'T07:00:00Z');
ok(step_counts($rep, 'links')['set'] === 0 && array_unique(array_column($live($P), 'expires_at')) === [null], 'the worker, with nothing paid: sets nothing');
foreach ([[$P, '300'], [$R, '100']] as [$i, $amt]) { [$c, $b] = act($bea, '/payments/save.php', ['invoice' => $i, 'amount' => $amt, 'method' => 'transfer', 'reference' => 'SMOKE s8 pay']); if ($c !== 200) { fwrite(STDERR, 'pay failed ' . json_encode($b) . "\n"); } }
ok(inv($P)['status'] === 'paid' && inv($R)['status'] === 'paid' && inv($U)['status'] !== 'paid' && inv($P)['paid_at'] !== null, 'P and R paid (paid_at stamped); U is not');

echo "1. The expiry is set once, from the day it was paid\n";
[$code, $rep] = worker('links', $today . 'T08:00:00Z');
$c = step_counts($rep, 'links');
$lp = $live($P); $lr = $live($R);
$paidAt = (string) inv($P)['paid_at'];
$expect = (string) one("SELECT CAST(CAST(:p AS timestamptz) + make_interval(days => 180) AS text)", ['p' => $paidAt]);
ok($c['set'] === 2 && strtotime((string) end($lp)['expires_at']) === strtotime($expect) && end($lp)['rotated_at'] === null, 'two live links given an expiry: P\'s is its paid_at + 180 days (' . substr((string) end($lp)['expires_at'], 0, 16) . ')');
ok(array_unique(array_column(array_slice($lr, 0, -1), 'expires_at')) === [null] && array_unique(array_filter(array_column(array_slice($lr, 0, -1), 'rotated_at'), 'is_null')) === [] && end($lr)['expires_at'] !== null, 'R: the links a send rotated away are not touched; the live one has the expiry');
ok(array_unique(array_column($live($U), 'expires_at')) === [null], 'U (unpaid): its link has no expiry');
ok(visitor('/i/' . $tokP)['code'] === 200 && visitor('/i/' . $tokR2)['code'] === 200, 'the links still open — they die 180 days after payment, not before');
$log = activity('invoice.link_expiry_set', $since);
$a = json_decode((string) $log[0]['after'], true);
ok(count($log) === 1 && $log[0]['source'] === 'cron' && $log[0]['actor_member_id'] === null && $a['links'] === 2 && $a['link_lifetime_days'] === 180, 'invoice.link_expiry_set logged once: cron, two links, the lifetime used');
[$code, $rep] = worker('links', $today . 'T09:00:00Z');
ok(step_counts($rep, 'links')['set'] === 0 && count(activity('invoice.link_expiry_set', $since)) === 1, 'a second pass sets nothing and logs nothing');

echo "2. Past the expiry: the one page\n";
q("UPDATE invoice_links_secure SET expires_at = now() - interval '1 minute' WHERE invoice_id = :i AND rotated_at IS NULL", ['i' => $P]);
$r = visitor('/i/' . $tokP);
ok($r['code'] === 404 && str_contains($r['body'], 'This link has expired') && !str_contains($r['body'], 'INV-') , 'the expired link answers the one page ("This link has expired. Ask the firm for a new one.") and shows no invoice');
[$code, $rep] = worker('links', plus_days($today, 1) . 'T07:00:00Z');
ok(step_counts($rep, 'links')['expired'] >= 1, 'the worker counts the links that are past their expiry (' . step_counts($rep, 'links')['expired'] . ')');

echo "3. The lifetime is the settings'\n";
q('UPDATE ct_settings SET link_lifetime_days = 30 WHERE id = 1');
[$Q, $tokQ] = linked_invoice($bea, $acme, '150');
act($bea, '/payments/save.php', ['invoice' => $Q, 'amount' => '150', 'method' => 'cash']);
worker('links', plus_days($today, 1) . 'T08:00:00Z');
$lq = $live($Q);
ok((string) one("SELECT CAST(expires_at - :p AS text) FROM invoice_links_secure WHERE invoice_id = :i AND rotated_at IS NULL", ['i' => $Q, 'p' => inv($Q)['paid_at']]) === '30 days', 'with link_lifetime_days = 30: the expiry is paid_at + 30 days');
q('UPDATE ct_settings SET link_lifetime_days = 180 WHERE id = 1');
finish();
