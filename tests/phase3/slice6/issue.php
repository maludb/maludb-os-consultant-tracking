<?php
/** Proof — issue (spec "Proof", 2): the PO rule refused in words, then with the PO; the not-to-exceed cap refused without a reason, a Manager cannot issue, Bea's reason is recorded; INV-00001; an issued invoice immutable in the database's words; the link minted, the raw token nowhere; invoice.issue carries amount and currency. */
require __DIR__ . '/lib.php';
$w = billing_world(); $acme = $w['acme']; $client = $w['client'];
$marco = as_member(27); $bea = as_member(28); $priya = as_member(26); $ann = as_member(29); $clerk = null;
$d = acme_draft($marco);
ok(inv($d)['status'] === 'draft' && count(lines_of($d)) === 7 && inv($d)['total'] === '2359.00', "the draft #$d for Acme: seven lines, 2,359.00");

echo "1. Who issues\n";
[$c, $b] = act($marco, '/invoices/issue.php', ['invoice' => $d]);
ok($c === 403 && msg($b) === 'You may not issue, send and settle invoices.', 'Marco (a Manager drafts, never issues): 403 in words');
[$c, $b] = act($priya, '/invoices/issue.php', ['invoice' => $d]);
ok($c === 403, 'Priya: 403 (the same gate answers before the invoice is even looked at)');
[$c, $b] = act($ann, '/invoices/issue.php', ['invoice' => $d]);
ok($c === 403, 'Ann (Finance reads, never writes): 403');
ok(inv($d)['status'] === 'draft' && inv($d)['number'] === null, 'and nothing was issued');

echo "2. The PO rule\n";
q('UPDATE clients SET po_required = true WHERE id = :c', ['c' => $client]);
$since = last_activity_id();
[$c, $b] = act($bea, '/invoices/issue.php', ['invoice' => $d]);
ok($c === 422 && msg($b) === 'SMOKE Acme requires a PO number on every invoice', 'a client that requires a PO: refused in the database\'s words (' . msg($b) . ')');
ok(inv($d)['status'] === 'draft' && count(activity('invoice.issue', $since)) === 0, 'still a draft; nothing logged as issued');
[$c, $b] = act($marco, '/invoices/save.php', ['invoice' => $d, 'po_number' => 'PO-2026-17']);
ok($c === 200 && inv($d)['po_number'] === 'PO-2026-17', 'the manager types the PO on the draft (invoice_update)');

echo "3. The not-to-exceed cap\n";
q('UPDATE engagements SET nte_amount = 2000 WHERE id = :e', ['e' => $acme]);
[$c, $b] = act($bea, '/invoices/issue.php', ['invoice' => $d]);
ok($c === 422 && str_contains(msg($b), 'not-to-exceed of 2000.00') && str_contains(msg($b), 'billing may override with a reason'), 'above the cap without a reason: refused in the database\'s words (' . msg($b) . ')');
ok(inv($d)['status'] === 'draft', 'still a draft');
echo "4. Issue with Bea's reason\n";
$since = last_activity_id();
$seq = (int) one("SELECT next_value FROM document_sequences WHERE kind = 'invoice'");
[$c, $b] = act($bea, '/invoices/issue.php', ['invoice' => $d, 'nte_override_reason' => 'SMOKE the client approved the overage in writing']);
$i = inv($d);
ok($c === 200 && $b['number'] === 'INV-00001' && $b['status'] === 'issued' && $b['total'] === '2359.00' && $i['number'] === 'INV-00001' && $i['status'] === 'issued' && (int) $i['issued_by'] === 28 && $i['issued_at'] !== null, 'invoice_issue by Billing with a reason: INV-00001, issued by Bea');
ok($i['nte_override_reason'] === 'SMOKE the client approved the overage in writing' && (int) one("SELECT next_value FROM document_sequences WHERE kind = 'invoice'") === $seq + 1, 'the override reason is on the invoice; the sequence moved by exactly one (gapless)');
$log = activity('invoice.issue', $since);
$after = json_decode((string) $log[0]['after'], true);
ok(count($log) === 1 && $log[0]['source'] === 'web' && (int) $log[0]['invoice_id'] === $d && (int) $log[0]['engagement_id'] === $acme && (int) $log[0]['client_id'] === $client && $after['amount'] === '2359.00' && $after['currency'] === 'USD' && $after['number'] === 'INV-00001' && $after['nte_override_reason'] === 'SMOKE the client approved the overage in writing' && json_decode((string) $log[0]['before'], true)['status'] === 'draft', 'invoice.issue logged: invoice_id, engagement_id, client_id, number, amount and currency, the override reason');
ok($after['receipts_shared'] === 1, 'the engagement shares receipts (the settings\' default): the hotel receipt is shared with the client on issue');
ok((bool) one("SELECT client_visible FROM attachments WHERE record_type = 'expense' AND record_id = :x", ['x' => $w['expenses'][0]]), 'the receipt\'s client_visible is set');
[$c, $b] = act($bea, '/invoices/issue.php', ['invoice' => $d]);
ok($c === 422 && msg($b) === 'Invoice INV-00001 is already issued', 'issuing again: the verb\'s words');

echo "5. The secure link: made, only a hash kept\n";
$l = q('SELECT * FROM invoice_links_secure WHERE invoice_id = :i', ['i' => $d]);
ok(count($l) === 1 && $l[0]['rotated_at'] === null && preg_match('/^[a-f0-9]{64}$/', $l[0]['token_hash']) === 1 && $l[0]['view_count'] === 0, 'one live link, its sha-256 hash stored, never opened');
$hex = (int) one("SELECT count(*) FROM activity_log WHERE (COALESCE(before::text, '') || COALESCE(after::text, '')) ~ '[a-f0-9]{48}'") + (int) one("SELECT count(*) FROM notification_outbox WHERE body ~ '/i/[a-f0-9]{48}'") + (int) one("SELECT count(*) FROM notifications WHERE body ~ '[a-f0-9]{48}'");
ok($hex === 0, 'a raw token is in no log row, no notice and no outbox row (it was not asked for: sending makes its own)');
[$c, $d2] = screen($bea, '/invoices/' . $d);
ok($c === 200 && $d2['invoice']['link']['state'] === 'live' && !str_contains(json_encode($d2), $l[0]['token_hash']) && $d2['invoice']['number'] === 'INV-00001', 'the invoice screen shows the link live — and never its token or hash');

echo "6. Issued is immutable — in the database's words\n";
[$c, $b] = act($marco, '/invoices/lines/save.php', ['invoice' => $d, 'kind' => 'fixed', 'description' => 'SMOKE late', 'unit_price' => '5']);
ok($c === 422 && msg($b) === 'The lines of an invoice change only while it is a draft (it is issued)', 'a line added to the issued invoice: the trigger\'s words (' . msg($b) . ')');
$line = (int) lines_of($d)[0]['id'];
[$c, $b] = act($marco, '/invoices/lines/remove.php', ['line' => $line]);
ok($c === 422 && str_contains(msg($b), 'draft'), 'a line removed: refused (' . msg($b) . ')');
[$c, $b] = act($marco, '/invoices/items/remove.php', ['item' => (int) one('SELECT id FROM invoice_line_items WHERE invoice_id = :i LIMIT 1', ['i' => $d])]);
ok($c === 422 && msg($b) === 'An issued invoice keeps its backing items; void it to release them', 'an item taken off: "An issued invoice keeps its backing items; void it to release them"');
[$c, $b] = act($marco, '/invoices/save.php', ['invoice' => $d, 'due_date' => '2027-01-01']);
ok($c === 422 && str_contains(msg($b), 'is issued: its client, engagement, dates, number and totals cannot change'), 'the due date of an issued invoice: refused (' . msg($b) . ')');
[$c, $b] = act($marco, '/invoices/delete.php', ['invoice' => $d]);
ok($c === 422 && str_contains(msg($b), 'Only a draft is deleted'), 'delete: only a draft');
ok(str_contains(refused("UPDATE invoices SET total = 1 WHERE id = :i", ['i' => $d]), 'is issued: its client, engagement, dates, number and totals cannot change'), 'and straight in SQL: the total cannot be changed');
ok(str_contains(refused("UPDATE invoices SET status = 'paid' WHERE id = :i", ['i' => $d]), 'Paid is derived'), 'a status typed by hand: "Paid is derived from the payments recorded, never typed"');
ok(str_contains(refused("DELETE FROM invoices WHERE id = :i", ['i' => $d]), 'is issued and is never deleted'), 'an issued invoice is never deleted');
[$c, $b] = act($marco, '/invoices/add-unbilled.php', ['invoice' => $d]);
ok($c === 422 && str_contains(msg($b), 'is issued; draft another'), 'add-unbilled on it: "Invoice INV-00001 is issued; draft another"');

echo "7. Screens\n";
$r = page($bea, '/invoices/' . $d);
ok($r['code'] === 200 && str_contains($r['body'], 'id="invoice-view-send-btn"') && str_contains($r['body'], 'id="invoice-view-payment-btn"') && str_contains($r['body'], 'id="invoice-view-void-btn"') && str_contains($r['body'], 'id="link-state-badge"') && str_contains($r['body'], 'id="invoice-lines-table"') && !str_contains($r['body'], 'invoice-view-issue-btn'), 'Billing sees Send, Record payment, Void, the link state and the lines — no Issue any more');
$r = page($marco, '/invoices/' . $d);
ok($r['code'] === 200 && str_contains($r['body'], 'invoice-view-preview-btn') && !str_contains($r['body'], 'invoice-view-send-btn') && !str_contains($r['body'], 'invoice-view-payment-btn'), 'the Manager reads it and previews it; Send and Record a payment are Billing\'s');
$r = page($bea, '/invoices/' . $d . '/preview');
ok($r['code'] === 200 && str_contains($r['body'], 'id="secure-invoice"') && str_contains($r['body'], 'INV-00001'), 'the preview is the client\'s document');
q('UPDATE engagements SET nte_amount = NULL WHERE id = :e', ['e' => $acme]);
finish();
