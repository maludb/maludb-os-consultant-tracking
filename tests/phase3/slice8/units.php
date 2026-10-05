<?php
/**
 * Proof — the pure parts (spec "Proof"): the export period with a fixed "today", the CSV presenter (flattening, formula quoting, booleans, nulls, the lines-by-kind cell), the export file names, the share dates and money, the words of a text and of a person's and a client's mail.
 * No server and no database: the feature files are loaded as they are; `refuse()` and `env()` are stubs.
 */
require __DIR__ . '/lib.php';
function refuse(int $status, string $message): never { throw new RuntimeException($status . ' ' . $message); }
function env(string $k, ?string $d = null): ?string { return $k === 'APP_URL' ? 'https://consulting.example.invalid' : $d; }
function db_bool(PDO $p, string $s, array $a = []): bool { return false; }
function pg_array_literal(array $v): string { return '{' . implode(',', $v) . '}'; }
function one_value(PDO $p, string $s, array $a = []): mixed { return null; }
const NOTICE_KINDS_STUB = true;
require dirname(__DIR__, 3) . '/app/features/engagements/queries.php';
require dirname(__DIR__, 3) . '/app/features/exports/queries.php';
require dirname(__DIR__, 3) . '/app/features/notify/kinds.php';
require dirname(__DIR__, 3) . '/app/features/notify/body.php';
$day = fn (string $d) => new DateTimeImmutable($d);
$period = fn (string $export, array $q, string $today) => export_period($export, $q, $day($today));
$refused = function (callable $f): string { try { $f(); return ''; } catch (RuntimeException $e) { return $e->getMessage(); } };

echo "1. The export period\n";
ok($period('invoices', [], '2026-10-05') === ['from' => '2026-09-01', 'to' => '2026-09-30'], 'nothing asked, on 2026-10-05: last month, September');
ok($period('invoices', [], '2026-01-15') === ['from' => '2025-12-01', 'to' => '2025-12-31'], 'in January: December of the year before');
ok($period('invoices', [], '2026-03-31') === ['from' => '2026-02-01', 'to' => '2026-02-28'], 'on March 31: February (no overflow)');
ok($period('time', ['from' => '2026-03-01', 'to' => '2026-03-15'], '2026-10-05') === ['from' => '2026-03-01', 'to' => '2026-03-15'], 'from and to as given');
ok($period('time', ['from' => '2026-03-01'], '2026-10-05') === ['from' => '2026-03-01', 'to' => '2026-10-05'], 'from alone: to today');
ok($period('time', ['to' => '2026-03-15'], '2026-10-05') === ['from' => '2026-03-01', 'to' => '2026-03-15'], 'to alone: from the first of its month');
ok($period('wip', [], '2026-10-05') === ['from' => null, 'to' => '2026-10-05'], 'an "as of" export (wip): no from, to today');
ok($period('reimbursements', ['as_of' => '2026-04-02', 'from' => '2020-01-01'], '2026-10-05') === ['from' => null, 'to' => '2026-04-02'], 'reimbursements: `as_of` is read as the day and `from` is ignored');
ok($refused(fn () => $period('invoices', ['from' => '2023-01-01', 'to' => '2026-10-02'], '2026-10-05')) === '422 An export covers at most 24 months; choose a shorter period.', 'over 24 months: refused in words');
ok($period('invoices', ['from' => '2024-10-05', 'to' => '2026-10-05'], '2026-10-05')['from'] === '2024-10-05', 'exactly 24 months is allowed');
ok(str_contains($refused(fn () => $period('invoices', ['from' => '2026-02-30', 'to' => '2026-03-02'], '2026-10-05')), 'a date (YYYY-MM-DD)') && str_contains($refused(fn () => $period('invoices', ['from' => '2026-04-02', 'to' => '2026-04-01'], '2026-10-05')), 'ends before it begins') && str_contains($refused(fn () => $period('wip', ['to' => 'yesterday'], '2026-10-05')), 'a date'), 'an impossible day, a backwards period, a word: each refused in words');
ok($refused(fn () => export_from_request('nonsense')) === '404 There is no such export.' && export_from_request('credit_notes') === 'credit_notes', 'an export is from the allow-list or 404');
ok(export_filename('credit_notes', '2026-09-01', '2026-09-30', 'csv') === 'ct-credit-notes-2026-09-01_2026-09-30.csv' && export_filename('wip', null, '2026-10-05', 'json') === 'ct-wip-2026-10-05.json', 'file names: ct-<export>-<from>_<to>.<ext>, an as-of export without the from');
ok(array_keys(EXPORTS) === ['invoices', 'credit_notes', 'expenses', 'reimbursements', 'wip', 'time', 'usage'] && count(array_unique(array_column(EXPORTS, 'schema'))) === 7 && array_keys(EXPORT_FORMATS) === ['csv', 'json'], 'seven exports with seven schemas, two formats');

echo "2. The CSV presenter\n";
$doc = ['rows' => [
    ['number' => 'INV-1', 'client' => ['client_id' => 7, 'name' => '=HYPERLINK("x")', 'code' => null], 'billable' => true, 'flag' => false, 'note' => "line one\nline two, with a comma", 'lines_by_kind' => [['kind' => 'time', 'subtotal' => '1260.00', 'tax' => '0.00', 'total' => '1260.00'], ['kind' => 'usage', 'subtotal' => '10.00', 'tax' => '1.00', 'total' => '11.00']]],
    ['number' => '+cmd', 'client' => ['client_id' => 8, 'name' => 'Beta "Quoted"', 'code' => 'B'], 'billable' => false, 'flag' => true, 'note' => '@at', 'lines_by_kind' => [], 'extra' => '-5'],
]];
$text = document_csv($doc);
$h = fopen('php://temp', 'r+'); fwrite($h, $text); rewind($h); $lines = []; while (($r = fgetcsv($h, 0, ',', '"', '')) !== false) { $lines[] = $r; }
ok($lines[0] === ['number', 'client_client_id', 'client_name', 'client_code', 'billable', 'flag', 'note', 'lines_by_kind', 'extra'], 'the header is the union of the rows\' columns in order of appearance; a nested object flattens to parent_child');
ok($lines[1] === ['INV-1', '7', "'=HYPERLINK(\"x\")", '', 'yes', 'no', "line one\nline two, with a comma", 'time 1260.00 (tax 0.00, total 1260.00); usage 10.00 (tax 1.00, total 11.00)', ''], 'a row: a name that begins like a formula is quoted with an apostrophe; booleans yes/no; null empty; newlines and commas survive; the lines-by-kind in one cell; a missing column empty');
ok($lines[2] === ["'+cmd", '8', 'Beta "Quoted"', 'B', 'no', 'yes', "'@at", '', "'-5"], 'a second row: + - @ also quoted; embedded quotes round-trip; an empty list is an empty cell');
ok(document_csv(['rows' => []]) === "\n" || document_csv(['rows' => []]) === '""' . "\n" || trim(document_csv(['rows' => []])) === '' || trim(document_csv(['rows' => []])) === '""', 'no rows: an empty file, not an error');

echo "3. Shares\' small parts\n";
ok(share_money('0') === '0.00' && share_money('45') === '45.00' && share_money('1260.5') === '1260.50' && share_money('2479.00') === '2479.00' && share_money(null) === '0.00', 'money prints with two decimals always (a SQL sum over no rows is \'0\')');
ok(share_date('2026-10-05', 'The day') === '2026-10-05' && str_contains((string) $refused(function () { try { share_date('2026-02-30', 'The first day'); } catch (DomainException $e) { throw new RuntimeException($e->getMessage()); } }), 'The first day is a day'), 'share_date: a real day, else a sentence naming it');
$gate = false;
try { share_gate(pdo(), 'kernel', 'billing.write', 'x'); $gate = true; } catch (Throwable) {}
ok($gate, 'the kernel\'s token is never asked for a right');

echo "4. What a row becomes when it is sent\n";
$member = ['kind' => 'expense_approved', 'subject' => 'Your expense EXP-000004 (30.00 USD) was approved', 'body' => "It will be reimbursed.\n\nThank you & <b>bye</b>", 'client_id' => null, 'body_html' => null];
[$s, $t, $hh] = outbox_email_parts($member);
ok($s === $member['subject'] && str_ends_with($t, "https://consulting.example.invalid/notifications\n") && str_contains($hh, '<p>It will be reimbursed.</p>') && str_contains($hh, 'Thank you &amp; &lt;b&gt;bye&lt;/b&gt;') && str_contains($hh, 'href="https://consulting.example.invalid/notifications"'), 'a person\'s mail: the title as subject, the body, a link to the bell; html paragraphs, everything escaped');
$client = ['kind' => 'invoice', 'subject' => 'Invoice INV-00001', 'body' => "Hello,\nSee https://consulting.example.invalid/i/abc", 'client_id' => 3, 'body_html' => '<p>Hello</p><p><a href="https://consulting.example.invalid/i/abc">link</a></p>'];
[$s, $t, $hh] = outbox_email_parts($client);
ok($t === $client['body'] && $hh === $client['body_html'] && !str_contains($t, '/notifications'), 'a client\'s mail is the row as slice 6 wrote it, verbatim — no footer added');
[, , $hh] = outbox_email_parts(['body_html' => '', 'body' => 'a < b'] + $client);
ok($hh === '<pre style="font-family:inherit;white-space:pre-wrap">a &lt; b</pre>', 'a client row with no html: the text, escaped, pre-wrapped');
ok(outbox_email_parts(['subject' => '', 'kind' => 'invoice'] + $client)[0] === 'An invoice' && outbox_kind_label('morning_note') === 'morning note' && outbox_kind_label('reminder') === 'A reminder', 'a missing subject falls back to the kind\'s label');
$words = outbox_text_words(['subject' => 'Please submit your week of sep 28–oct 4', 'body' => '5 empty weekdays, 0 h logged. Open your timesheet: https://consulting.example.invalid/time/?week=2026-09-28', 'kind' => 'missing_time']);
ok($words === 'Please submit your week of sep 28–oct 4. 5 empty weekdays, 0 h logged. Open your timesheet: https://consulting.example.invalid/time/?week=2026-09-28', 'a text: the title, then the body, one line');
ok(mb_strlen(outbox_text_words(['subject' => 'T', 'body' => str_repeat('é', 900), 'kind' => 'x'])) === OUTBOX_TEXT_MAX && outbox_text_words(['subject' => '', 'body' => '', 'kind' => 'morning_note']) === 'morning note', 'a text is cut at ' . OUTBOX_TEXT_MAX . ' characters (the kernel takes 480 and prefixes the name); an empty one falls back to the label');
ok(OUTBOX_MAX_ATTEMPTS === 5 && OUTBOX_CLIENT_KINDS === ['invoice', 'reminder', 'statement'], 'five attempts; a client is told with an invoice, a reminder or a statement');
finish();
