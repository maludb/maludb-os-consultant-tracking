<?php
/** Proof — the receipt door (spec "Proof", 1): an .exe receipt → 422; a file over the limit → 422; neither leaves an expense or a file behind; a PDF is accepted and served inline; the generic documents door serves `expense`; a second receipt on the form. */
require __DIR__ . '/lib.php';
function find_notes_of(string $jar, int $e): array { return screen($jar, '/expenses/' . $e)[1]['notes'] ?? []; }
[$acme, $beta] = expense_world();
$priya = as_member(26); $dana = as_member(30); $bea = as_member(28);
$count = (int) one('SELECT count(*) FROM expenses'); $files = receipt_files(); $atts = (int) one('SELECT count(*) FROM attachments');
$base = ['engagement' => $acme, 'date' => date('Y-m-d'), 'category' => category('meals'), 'amount' => '20.00', 'description' => 'SMOKE receipt door'];

echo "1. What is refused leaves nothing behind\n";
$exe = sys_get_temp_dir() . '/ct-evil-' . bin2hex(random_bytes(3)) . '.exe'; file_put_contents($exe, file_get_contents('/bin/ls'));
[$c, $b] = upload($priya, '/expenses/save.php', $base, 'file', $exe, 'receipt.exe');
ok($c === 422 && str_contains(msg($b), 'That kind of file is not accepted'), 'an .exe receipt: 422 "That kind of file is not accepted" (' . msg($b) . ')');
$fake = jpeg_file(); 
[$c, $b] = upload($priya, '/expenses/save.php', $base, 'file', $fake, 'receipt.jpg.exe');
ok($c === 422 && str_contains(msg($b), 'That kind of file is not accepted'), 'a JPEG renamed .exe: 422 by the name too');
$max = (int) getenv('ATTACHMENT_MAX_BYTES') ?: 20 * 1048576;
$big = sys_get_temp_dir() . '/ct-big-' . bin2hex(random_bytes(3)) . '.pdf'; file_put_contents($big, '%PDF-1.4' . str_repeat('x', $max + 1 - 8));
[$c, $b] = upload($priya, '/expenses/save.php', $base, 'file', $big, 'huge.pdf');
ok($c === 422 && (str_contains(msg($b), 'too large') || str_contains(msg($b), 'A file is at most')), 'a file one byte over the limit (' . $max . '): 422 (' . msg($b) . ')');
ok((int) one('SELECT count(*) FROM expenses') === $count && (int) one('SELECT count(*) FROM attachments') === $atts && receipt_files() === $files, 'no expense, no attachment row, no orphan file after the three refusals');
@unlink($big); @unlink($exe);

echo "2. A PDF and a second receipt\n";
$pdf = sys_get_temp_dir() . '/ct-r-' . bin2hex(random_bytes(3)) . '.pdf'; file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
[$c, $b] = upload($priya, '/expenses/save.php', $base, 'file', $pdf, 'hotel.pdf');
$e = (int) ($b['record_id'] ?? 0);
$r = req('GET', '/attachments/' . (int) one("SELECT id FROM attachments WHERE record_type = 'expense' AND record_id = :e", ['e' => $e]), ['jar' => $priya]);
ok($c === 200 && $r['code'] === 200 && stripos($r['headers'], 'Content-Type: application/pdf') !== false && stripos($r['headers'], 'Content-Disposition: inline') !== false, 'a PDF receipt is accepted and served inline');
$body = page($priya, '/expenses/' . $e)['body'];
ok(str_contains($body, 'id="receipt-large-' . $e . '"') && str_contains($body, 'id="receipt-' . $e . '-file"') && str_contains($body, 'id="expense-money"'), 'the expense page shows the PDF as a link and the money card');
[$c, $b] = upload($priya, '/expenses/save.php', ['expense' => $e], 'file', jpeg_file(), 'second.jpg');
ok($c === 200 && (int) one("SELECT count(*) FROM attachments WHERE record_type = 'expense' AND record_id = :e", ['e' => $e]) === 2 && expense_row($e)['amount'] === '20.00', 'an edit with a file adds a second receipt and keeps what it is not sent (the amount)');
$body = page($priya, '/expenses/' . $e)['body'];
ok(str_contains($body, 'id="receipt-' . $e . '-file"') && str_contains($body, '1 more in the documents below'), 'the first receipt is large, the other is in the documents');
$aid = (int) one("SELECT id FROM attachments WHERE record_type = 'expense' AND record_id = :e ORDER BY id DESC LIMIT 1", ['e' => $e]);
ok(req('GET', '/attachments/' . $aid, ['jar' => $dana])['code'] === 404, 'a receipt Dana may not see: 404');
ok(req('GET', '/attachments/' . $aid, ['jar' => $bea])['code'] === 200, 'Billing sees it (all expenses)');

echo "3. The generic door serves expenses\n";
[$c, $b] = upload($priya, '/engagements/attachments/save.php', ['record_type' => 'expense', 'record' => $e], 'file', jpeg_file(), 'third.jpg');
ok($c === 200 && ($b['url'] ?? '') !== '' && str_ends_with((string) $b['location'], '/expenses/' . $e . '?notice=attached#documents'), 'attachment_add with record_type=expense lands on the expense');
[$c, $b] = upload($dana, '/engagements/attachments/save.php', ['record_type' => 'expense', 'record' => $e], 'file', jpeg_file(), 'nope.jpg');
ok($c === 404, 'Dana attaching to Priya\'s expense: 404 (she cannot see it)');
[$c, $b] = act($priya, '/engagements/notes/save.php', ['record_type' => 'expense', 'record' => $e, 'body' => 'SMOKE a note on my expense']);
ok($c === 200 && (int) one("SELECT count(*) FROM notes WHERE record_type = 'expense' AND record_id = :e", ['e' => $e]) === 1 && count(find_notes_of($priya, $e)) === 1, 'note_add with record_type=expense is accepted and shown on the expense');
finish();
