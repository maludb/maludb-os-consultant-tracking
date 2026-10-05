<?php
/** Proof — who sees (spec "Proof", 7): Priya nothing of /invoices (403); Marco the invoices of his engagements and may draft, not issue (403 in words); Bea everything; Ann read-only (every POST 403); an engagement he does not manage does not exist to him (404 on every handler). */
require __DIR__ . '/lib.php';
$w = billing_world(); $acme = $w['acme']; $beta = $w['beta'];
$priya = as_member(26); $dana = as_member(30); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29); $owner = as_member(1);
/** The ids of every invoice the SQL views show a member. */
function seen(int $member): array { pdo()->exec("SELECT set_config('app.member_id', '$member', false)"); return array_map('intval', array_column(q('SELECT invoice_id FROM mcp_invoices ORDER BY invoice_id'), 'invoice_id')); }

echo "1. Bea's own engagement and invoice (Marco does not manage it)\n";
[$c, $b] = act($bea, '/engagements/save.php', ['client' => (int) one("SELECT id FROM clients WHERE name = 'SMOKE Beta'"), 'name' => 'SMOKE Beas billing eng', 'kind' => 'time_and_materials', 'start_date' => '2026-01-05', 'manager' => 28, 'start_now' => 'yes']);
$beas = (int) $b['record_id'];
[$c, $b] = draft($bea, $beas, ['add_unbilled' => 'no', 'po_number' => 'PO-B']);
$bd = (int) $b['record_id'];
act($bea, '/invoices/lines/save.php', ['invoice' => $bd, 'kind' => 'fixed', 'description' => 'SMOKE Bea fee', 'unit_price' => '300']);
[$c, $b] = act($bea, '/invoices/issue.php', ['invoice' => $bd]);
ok($c === 200 && $b['number'] === 'INV-00006', 'INV-00006 issued by Bea on her own engagement');
[$c, $b] = act($bea, '/credit-notes/save.php', ['invoice' => $bd, 'reason' => 'SMOKE hers']);
$bcn = (int) $b['record_id'];
$all = seen(28);
ok(count($all) === 6 && $all === seen(1) && $all === seen(29), 'in the views Billing, the owner and Finance see all six invoices (the void and the written-off ones included)');
$mine = seen(27);
ok(!in_array($bd, $mine, true) && count($mine) === 5 && in_array((int) one("SELECT id FROM invoices WHERE number = 'INV-00001'"), $mine, true), 'Marco: five — everything on Acme and Beta, nothing on Bea\'s engagement');
ok(seen(26) === [] && seen(30) === [], 'Priya and Dana: none (a Consultant never gets an invoice)');
$hid = (int) one("SELECT id FROM invoices WHERE number = 'INV-00001'");

echo "2. A Consultant\n";
foreach (['/invoices/', '/invoices/' . $hid, '/invoices/new', '/invoices/' . $hid . '/preview', '/billing/unbilled', '/billing/unbilled/' . $acme, '/billing/due', '/billing/claims', '/payments/', '/credit-notes/', '/credit-notes/new', '/reports/ar-aging', '/clients/' . $w['client'] . '/statement'] as $path) {
    $codes[$path] = [req('GET', $path, ['jar' => $priya, 'headers' => JSONH])['code'], req('GET', $path, ['jar' => $dana, 'headers' => JSONH])['code']];
}
ok(count(array_filter($codes, static fn ($c) => $c === [403, 403])) === count($codes), 'Priya and Dana: every billing screen answers 403 (' . implode(' ', array_map(static fn ($p, $c) => "$p=" . implode('/', $c), array_keys($codes), $codes)) . ')');
$posts = ['/invoices/save.php', '/invoices/add-unbilled.php', '/invoices/lines/save.php', '/invoices/lines/remove.php', '/invoices/items/remove.php', '/invoices/delete.php', '/invoices/issue.php', '/invoices/send.php', '/invoices/remind.php', '/invoices/void.php', '/invoices/write-off.php',
          '/invoices/external-ref.php', '/invoices/secure-link.php', '/payments/save.php', '/payments/void.php', '/billing/claims/confirm.php', '/billing/claims/dismiss.php', '/credit-notes/save.php', '/credit-notes/lines/save.php', '/credit-notes/delete.php', '/credit-notes/issue.php',
          '/credit-notes/apply.php', '/credit-notes/unapply.php', '/credit-notes/void.php', '/clients/statement-send.php'];
$bad = [];
foreach ($posts as $p) { foreach ([$priya, $dana] as $j) { [$c, ] = act($j, $p, ['invoice' => $hid, 'engagement' => $acme, 'reason' => 'x', 'amount' => '1']); if ($c !== 403) { $bad[] = $p . '=' . $c; } } }
ok($bad === [], 'every one of the 25 handlers answers a Consultant 403' . ($bad ? ' — not: ' . implode(' ', $bad) : ''));
[$c, $d] = screen($priya, '/');
ok($c === 200 && $d['billing'] === null, 'the home carries no Billing region for a Consultant');
[$c, $d] = screen($priya, '/clients/' . $w['client']);
ok($c === 200 && $d['invoices'] === [] && isset($d['engagements']), 'a Consultant opens the client page of an engagement she is on: no invoice on it');
[$c, $d] = screen($priya, '/engagements/' . $acme . '?tab=invoices');
ok($c === 200 && ($d['invoices'] ?? null) === null, 'and the engagement\'s Invoices tab answers JSON with none');

echo "3. A Manager\n";
[$c, $d] = screen($marco, '/invoices/');
$ids = array_column($d['invoices'], 'invoice_id');
ok($c === 200 && !in_array($bd, $ids, true) && count($ids) === 5 && $d['may']['draft'] === true && $d['may']['billing'] === false && isset($d['totals']['USD']), 'Marco\'s list: his five invoices, he may draft, not bill; the totals footer per currency');
[$c, $d] = screen($bea, '/invoices/');
ok($c === 200 && count($d['invoices']) === 6 && $d['counts'][''] === 6 && $d['counts']['draft'] === 0 && $d['counts']['paid'] === 1 && $d['counts']['void'] === 2 && $d['counts']['open'] === 3 && $d['counts']['overdue'] === 1, 'Bea\'s list: all six; counts — open 3 (INV-00004, 5, 6), paid 1, void or written off 2, overdue 1');
foreach (['/invoices/' . $bd, '/invoices/' . $bd . '/preview', '/invoices/' . $bd . '/edit'] as $path) {
    $r = req('GET', $path, ['jar' => $marco, 'headers' => JSONH]);
    ok($r['code'] === 404, "Marco opening $path on Bea's engagement: 404, as if it did not exist");
}
foreach ([['/invoices/save.php', ['invoice' => $bd, 'memo' => 'x']], ['/invoices/add-unbilled.php', ['invoice' => $bd]], ['/invoices/lines/save.php', ['invoice' => $bd, 'kind' => 'fixed', 'description' => 'x', 'unit_price' => '1']], ['/invoices/delete.php', ['invoice' => $bd]],
          ['/invoices/save.php', ['engagement' => $beas, 'add_unbilled' => 'no']]] as [$p, $f]) {
    [$c, $b] = act($marco, $p, $f);
    ok($c === 404, "Marco's POST $p on Bea's engagement: 404");
}
[$c, $b] = act($marco, '/invoices/issue.php', ['invoice' => $hid]);
ok($c === 403 && msg($b) === 'You may not issue, send and settle invoices.', 'issuing: 403 "You may not issue, send and settle invoices."');
$mdraft = draft($marco, $acme, ['add_unbilled' => 'no']);
ok($mdraft[0] === 200, 'drafting on his own engagement: allowed');
act($marco, '/invoices/delete.php', ['invoice' => (int) $mdraft[1]['record_id']]);
foreach (['/payments/', '/billing/claims', '/reports/ar-aging', '/clients/' . $w['client'] . '/statement'] as $path) {
    ok(req('GET', $path, ['jar' => $marco, 'headers' => JSONH])['code'] === 403, "Marco: $path is Billing's (403)");
}
[$c, $d] = screen($marco, '/billing/unbilled');
ok($c === 200 && array_column(array_column($d['unbilled'], 'engagement'), 'engagement_id') !== [] && !in_array($beas, array_column(array_column($d['unbilled'], 'engagement'), 'engagement_id'), true), 'his unbilled screen shows his engagements only');
foreach (['/credit-notes/', '/credit-notes/' . $bcn, '/credit-notes/new'] as $path) {
    ok(req('GET', $path, ['jar' => $marco, 'headers' => JSONH])['code'] === 403, "Marco: $path is Billing's alone (403) — Phase 2's gate: no credit-note screens for a Manager");
}
ok(seen(27) !== [] && count(q('SELECT credit_note_id FROM mcp_credit_notes')) === 2, 'the view still shows him the notes of his own engagements (CN-00001, CN-00002) to the tools — the screens are Billing\'s');
[$c, $d] = screen($marco, '/');
ok($c === 200 && $d['billing'] !== null && isset($d['billing']['unbilled']) && $d['billing']['claims_waiting'] === 0 && $d['billing']['aging'] === ['current_due' => [], 'd1_30' => [], 'd31_60' => [], 'd61_90' => [], 'd90_plus' => []], 'his home\'s Billing region: his figures; no firm-wide aging for a Manager');
$r = page($marco, '/engagements/' . $acme . '?tab=invoices');
ok($r['code'] === 200 && str_contains($r['body'], 'id="engagement-invoices-cards"') && str_contains($r['body'], 'id="engagement-invoices-draft-btn"') && str_contains($r['body'], 'INV-00001'), 'the engagement\'s Invoices tab lists its invoices and offers Draft invoice');
$r = page($priya, '/engagements/' . $acme . '?tab=invoices');
ok($r['code'] === 200 && str_contains($r['body'], 'id="engagement-tab-invoices-none"') && !str_contains($r['body'], 'INV-00001'), 'a staffed Consultant opens the tab and finds none of it');

echo "4. Billing\n";
[$c, $d] = screen($bea, '/');
ok($c === 200 && $d['billing']['claims_waiting'] === 0 && $d['billing']['owed']['USD'] === '1390.00' && $d['billing']['overdue'] === 1 && count($d['billing']['aging']['d31_60']) > 0, 'Bea\'s home: owed 1,390.00 USD (INV-00005 1,000.00 + INV-00004 90.00 + INV-00006 300.00), one overdue, the aging buckets');
[$c, $d] = screen($bea, '/billing/due');
ok($c === 200 && isset($d['due'], $d['milestones'], $d['drafts']), 'the due-to-draft screen answers');
[$c, $d] = screen($bea, '/billing/unbilled');
ok($c === 200 && isset($d['totals']), 'the unbilled screen answers');

echo "5. Finance reads, never writes\n";
foreach (['/invoices/', '/invoices/' . $hid, '/invoices/' . $hid . '/preview', '/reports/ar-aging', '/clients/' . $w['client'] . '/statement', '/billing/unbilled', '/'] as $path) {
    $r = req('GET', $path, ['jar' => $ann, 'headers' => JSONH]);
    if ($r['code'] !== 200) { fwrite(STDERR, "Ann $path => {$r['code']}\n"); }
    $okAnn[] = $r['code'] === 200;
}
ok(!in_array(false, $okAnn, true), 'Ann opens the invoices, one invoice, its preview, the aging, a statement, WIP and the home (200 each)');
$bad = [];
foreach ($posts as $p) { [$c, ] = act($ann, $p, ['invoice' => $hid, 'engagement' => $acme, 'reason' => 'x', 'amount' => '1', 'credit_note' => 1, 'payment' => 1, 'claim' => 1, 'client' => $w['client'], 'line' => 1, 'item' => 1]); if ($c !== 403) { $bad[] = $p . '=' . $c; } }
ok($bad === [], 'every one of the 25 POST handlers answers Finance 403' . ($bad ? ' — not: ' . implode(' ', $bad) : ''));
[$c, $d] = screen($ann, '/invoices/' . $hid);
ok($c === 200 && $d['may']['send'] === false && $d['may']['pay'] === false && $d['may']['void'] === false && $d['may']['rotate'] === false && $d['may']['edit'] === false, 'the invoice for Ann: no action offered (may.* all false)');
[$c, $d] = screen($ann, '/payments/');
ok($c === 403, 'the payments list is Billing\'s: 403');
$r = page($ann, '/invoices/' . $hid);
ok($r['code'] === 200 && str_contains($r['body'], 'id="invoice-view-no-actions"') && !str_contains($r['body'], 'invoice-view-send-btn'), 'her page shows "Nothing to do here for now."');
finish();
