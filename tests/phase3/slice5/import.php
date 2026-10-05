<?php
/** Proof — import (spec "Proof", 2): an Anthropic CSV of six rows mapped and previewed (the shipped mapping suggested), two duplicates skipped, four drafts at the exact cost; the mapping saved on the provider and prefilled next time; grouped by month; the file refused when it is not a CSV, a column is not mapped, a cost cannot be written exactly; the import deleted removes the drafts and keeps an approved one; nothing of the file is kept. */
require __DIR__ . '/lib.php';
[$acme, $beta] = usage_world();
$priya = as_member(26); $marco = as_member(27); $bea = as_member(28);
$anthropic = provider('anthropic');
function csv_tmp(array $rows, string $header = 'usage_date_utc,model,total_tokens,cost_usd'): string
{
    $p = sys_get_temp_dir() . '/ct-usage-' . bin2hex(random_bytes(4)) . '.csv';
    file_put_contents($p, $header . "\n" . implode("\n", $rows) . "\n");
    return $p;
}
function csv_stash_gone(string $t): bool { clearstatcache(); return !is_file(dirname(__DIR__, 3) . '/storage/tmp/usage-import/' . $t . '.csv'); }
function stash_files(): int { clearstatcache(); return count(glob(dirname(__DIR__, 3) . '/storage/tmp/usage-import/*.csv') ?: []); }
$six = ['2026-09-01,claude-sonnet,1000000,3.00', '2026-09-02,claude-sonnet,2000000,6.00', '2026-09-03,claude-opus,1000000,15.00', '2026-09-04,claude-sonnet,500000,1.50', '2026-09-05,claude-haiku,4000000,4.00', '2026-09-06,claude-haiku,1000000,1.00'];
$two = array_slice($six, 0, 2);

echo "1. Who may\n";
[$c, $b] = upload($priya, '/usage/import.php', ['provider' => $anthropic, 'engagement' => $acme, 'preview' => '1'], 'file', csv_tmp($six), 'usage.csv');
ok($c === 403 && msg($b) === 'You may not record usage charges.', 'Priya: usage_import → 403');
[$c, $b] = upload($marco, '/usage/import.php', ['provider' => $anthropic, 'engagement' => (int) one("SELECT id FROM engagements WHERE name = 'SMOKE Beas expense eng'") ?: 999999, 'preview' => '1'], 'file', csv_tmp($six), 'usage.csv');
ok(in_array($c, [403, 404, 422], true), 'an engagement he does not manage: refused (' . $c . ')');

echo "2. Step 1 → step 2: the preview, with the shipped mapping suggested\n";
$stash0 = stash_files();
[$c, $b] = upload($marco, '/usage/import.php', ['provider' => $anthropic, 'engagement' => $acme, 'preview' => '1'], 'file', csv_tmp($six), 'anthropic-sep.csv');
$d = $b['data'] ?? [];
ok($c === 200 && $d['headers'] === ['usage_date_utc', 'model', 'total_tokens', 'cost_usd'] && $d['mapping'] == ['date' => 'usage_date_utc', 'quantity' => 'total_tokens', 'cost' => 'cost_usd', 'model' => 'model', 'unit' => 'tokens', 'kind' => 'model_usage'], 'Anthropic has no saved mapping: the shipped one is matched against the file\'s headers (date → usage_date_utc, quantity → total_tokens, cost → cost_usd, model)');
ok(count($d['preview']['rows']) === 5 && $d['preview']['total'] === 6 && $d['preview']['errors'] === 0 && $d['preview']['rows'][0]['date'] === '2026-09-01' && $d['preview']['rows'][0]['quantity'] === '1000000' && $d['preview']['rows'][0]['cost'] === '3.00', 'a five-row preview of six rows, read as mapped');
ok($d['counts'] === ['new' => 6, 'duplicate' => 0, 'refused' => 0] && strlen($d['stash']) === 32 && stash_files() === $stash0 + 1, 'nothing is a duplicate yet: 6 new; the file is kept one step under a random token');
ok((int) one('SELECT count(*) FROM usage_charges WHERE source = \'csv_import\'') === 0 && (int) one('SELECT count(*) FROM usage_imports') === 0, 'and a preview writes nothing');
$tok0 = $d['stash'];

echo "3. Import two rows and remember the mapping\n";
$since = last_activity_id();
$map = json_encode(['date' => 'usage_date_utc', 'quantity' => 'total_tokens', 'cost' => 'cost_usd', 'model' => 'model', 'unit' => 'tokens', 'kind' => 'model_usage']);
[$c, $b] = upload($marco, '/usage/import.php', ['provider' => $anthropic, 'engagement' => $acme, 'mapping' => $map, 'save_mapping' => 'yes'], 'file', csv_tmp($two), 'anthropic-first.csv');
ok($c === 200 && $b['inserted'] === 2 && $b['skipped'] === 0 && $b['refused'] === 0 && $b['rows'] === 2 && count($b['charge_ids']) === 2 && ($b['refresh'] ?? '') === 'usageChanged', 'usage_import with the file and the mapping in one POST: 2 drafts made');
$imp1 = (int) $b['import_id'];
$ir = q('SELECT * FROM usage_imports WHERE id = :i', ['i' => $imp1])[0];
ok($ir['file_name'] === 'anthropic-first.csv' && (int) $ir['row_count'] === 2 && (int) $ir['inserted'] === 2 && (int) $ir['skipped'] === 0 && (int) $ir['provider_id'] === $anthropic && json_decode($ir['mapping'], true)['cost'] === 'cost_usd', 'the import row remembers the file name, the counts, the provider and the mapping');
$ch = q('SELECT * FROM usage_charges WHERE import_id = :i ORDER BY id', ['i' => $imp1]);
ok(count($ch) === 2 && $ch[0]['status'] === 'draft' && $ch[0]['source'] === 'csv_import' && $ch[0]['kind'] === 'model_usage' && $ch[0]['unit'] === 'tokens' && $ch[0]['quantity'] === '1000000.0000' && $ch[0]['cost_amount'] === '3.00' && $ch[0]['billable_amount'] === '3.00' && $ch[0]['unit_cost'] === '0.000003' && $ch[0]['period_start'] === '2026-09-01' && $ch[0]['period_end'] === '2026-09-01' && (int) $ch[0]['engagement_id'] === $acme && (int) $ch[0]['provider_id'] === $anthropic && $ch[0]['currency'] === 'USD' && $ch[1]['cost_amount'] === '6.00', 'a row makes a draft charge: source csv_import, model_usage, tokens, the file\'s quantity and cost (the unit cost set to 0.000003, the guard wrote 3.00), the engagement\'s USD');
$log = activity('usage_import.run', $since);
$after = json_decode((string) $log[0]['after'], true);
ok(count($log) === 1 && (int) $log[0]['engagement_id'] === $acme && $after['rows'] === 2 && $after['inserted'] === 2 && $after['skipped'] === 0 && $after['provider'] === 'anthropic' && $after['file_name'] === 'anthropic-first.csv' && $after['mapping_saved'] === true && !str_contains((string) $log[0]['after'], 'cost_usd'), 'usage_import.run logged: provider, rows, inserted, skipped, engagement_id (the mapping\'s content is not in the payload)');
ok(json_decode((string) one('SELECT csv_mapping FROM usage_providers WHERE id = :p', ['p' => $anthropic]), true) == ['date' => 'usage_date_utc', 'quantity' => 'total_tokens', 'cost' => 'cost_usd', 'model' => 'model', 'unit' => 'tokens', 'kind' => 'model_usage'], 'save_mapping: the provider now holds the mapping');
ok((int) one("SELECT count(*) FROM attachments WHERE record_type = 'usage_import'") === 0 && stash_files() === $stash0 + 1, 'the file is not stored (the import row and the charges are the record); its stash file went with the import');
ok((int) one('SELECT count(*) FROM notifications WHERE kind = \'usage_drafted\' AND body LIKE \'%usage/?import=%\'') === 0, 'Marco ran it himself: no notice to himself');

echo "4. The saved mapping is offered; two duplicates are skipped\n";
[$c, $b] = upload($marco, '/usage/import.php', ['provider' => $anthropic, 'engagement' => $acme, 'preview' => '1'], 'file', csv_tmp($six, "total_tokens,model,cost_usd,usage_date_utc"), 'reordered.csv');
$d = $b['data'] ?? [];
ok($c === 200 && $d['mapping']['date'] === 'usage_date_utc' && $d['mapping']['cost'] === 'cost_usd', 'the next import of this provider is prefilled from its saved mapping (the columns found by name, in any order)');
[$c, $b] = upload($marco, '/usage/import.php', ['provider' => $anthropic, 'engagement' => $acme, 'preview' => '1'], 'file', csv_tmp($six), 'anthropic-sep.csv');
$d = $b['data'];
ok($d['counts'] === ['new' => 4, 'duplicate' => 2, 'refused' => 0] && array_column($d['plan'], 'state') === ['duplicate', 'duplicate', 'new', 'new', 'new', 'new'], 'the preview counts the duplicates: the first two rows are already imported (same provider, period, quantity, cost) → 4 new, 2 duplicate');
$since = last_activity_id();
$before = (int) one('SELECT count(*) FROM notifications');
$stashBefore = stash_files();
[$c, $b] = act($bea, '/usage/import.php', ['provider' => $anthropic, 'engagement' => $acme, 'stash' => $d['stash'], 'map_date' => 'usage_date_utc', 'map_quantity' => 'total_tokens', 'map_cost' => 'cost_usd', 'map_model' => 'model', 'map_unit' => 'tokens', 'map_kind' => 'model_usage']);
$imp2 = (int) ($b['import_id'] ?? 0);
ok($c === 200 && $b['inserted'] === 4 && $b['skipped'] === 2 && $b['refused'] === 0 && $b['rows'] === 6, 'Bea imports from the kept file (the stash token, no second upload): 4 drafts, 2 skipped');
$ir = q('SELECT * FROM usage_imports WHERE id = :i', ['i' => $imp2])[0];
ok((int) $ir['inserted'] === 4 && (int) $ir['skipped'] === 2 && (int) $ir['row_count'] === 6 && $ir['file_name'] === 'anthropic-sep.csv', 'the import row: 6 rows, 4 inserted, 2 skipped');
$costs = q('SELECT cost_amount, quantity, unit_cost FROM usage_charges WHERE import_id = :i ORDER BY id', ['i' => $imp2]);
ok(array_column($costs, 'cost_amount') === ['15.00', '1.50', '4.00', '1.00'] && array_column($costs, 'unit_cost') === ['0.000015', '0.000003', '0.000001', '0.000001'], 'the four drafts cost exactly what the file said (15.00, 1.50, 4.00, 1.00)');
ok(stash_files() === $stashBefore - 1 && csv_stash_gone($d['stash']), 'the kept file of the import is gone');
$told = q("SELECT member_id, record_type FROM notifications WHERE kind = 'usage_drafted' AND id > :b ORDER BY id", ['b' => $before]);
ok($told === [['member_id' => 27, 'record_type' => 'usage_charge']], 'Marco (the manager) is told once that 4 charges were drafted from the file');
ok(req('POST', '/usage/import.php', ['jar' => $bea, 'headers' => JSONH, 'form' => ['provider' => $anthropic, 'engagement' => $acme, 'stash' => $d['stash'], 'csrf_token' => csrf_of(req('GET', '/', ['jar' => $bea])['body'])]])['code'] === 422, 'the token is single-use: the file is gone');
[$c, $d2] = screen($marco, '/usage/?import=' . $imp2);
ok($c === 200 && count($d2['charges']) === 4 && $d2['import']['file_name'] === 'anthropic-sep.csv' && $d2['totals'][0]['billable_amount'] === '21.50' && $d2['totals'][0]['cost_amount'] === '21.50', 'the list filtered by the import: its four charges, the total per currency');

echo "5. One charge a month\n";
$since = last_activity_id();
$oct = ['2026-10-01,claude-sonnet,1000000,3.00', '2026-10-15,claude-opus,1000000,15.00', '2026-10-20,claude-sonnet,1000000,3.00', '2026-11-02,claude-sonnet,1000000,3.00'];
[$c, $b] = upload($marco, '/usage/import.php', ['provider' => $anthropic, 'engagement' => $acme, 'group_by_month' => 'yes'], 'file', csv_tmp($oct), 'oct-nov.csv');
ok($c === 200 && $b['inserted'] === 2 && $b['rows'] === 2 && $b['skipped'] === 0, 'grouped by month: four rows make two charges (October, November)');
$g = q('SELECT * FROM usage_charges WHERE import_id = :i ORDER BY period_start', ['i' => (int) $b['import_id']]);
ok($g[0]['period_start'] === '2026-10-01' && $g[0]['period_end'] === '2026-10-31' && $g[0]['quantity'] === '3000000.0000' && $g[0]['cost_amount'] === '21.00' && str_contains($g[0]['description'], '3 rows') && $g[1]['period_start'] === '2026-11-01' && $g[1]['cost_amount'] === '3.00', 'October: 3,000,000 tokens, 21.00, the month as the period, "3 rows" in the description; November: 3.00');
$g1 = (int) $g[0]['id'];

echo "6. What is refused, in words\n";
$notcsv = sys_get_temp_dir() . '/ct-notcsv-' . bin2hex(random_bytes(3)) . '.pdf';
file_put_contents($notcsv, "%PDF-1.4\n%%EOF\n");
[$c, $b] = upload($marco, '/usage/import.php', ['provider' => $anthropic, 'engagement' => $acme, 'preview' => '1'], 'file', $notcsv, 'report.pdf');
ok($c === 422 && isset(fields($b)['file']) && str_contains(fields($b)['file'], 'not a CSV'), 'a PDF is not a CSV: 422, field file (' . (fields($b)['file'] ?? '') . ')');
[$c, $b] = upload($marco, '/usage/import.php', ['provider' => $anthropic, 'engagement' => $acme, 'mapping' => json_encode(['date' => 'when', 'quantity' => 'total_tokens', 'cost' => 'cost_usd'])], 'file', csv_tmp($six), 'wrong.csv');
ok($c === 422 && str_contains(msg($b), 'when') || isset(fields($b)['map_date']), 'a mapped column the file lacks: 422 naming it (' . msg($b) . ')');
$n = (int) one('SELECT count(*) FROM usage_imports');
[$c, $b] = upload($marco, '/usage/import.php', ['provider' => 0, 'engagement' => $acme], 'file', csv_tmp($six), 'x.csv');
ok($c === 422 && isset(fields($b)['provider']) && (int) one('SELECT count(*) FROM usage_imports') === $n, 'no provider: 422, field provider, nothing made');
[$c, $b] = upload($marco, '/usage/import.php', ['provider' => $anthropic, 'engagement' => $acme], 'file', csv_tmp(['2026-12-01,m,1000000,3.00', 'not a date,m,5,1.00', '2026-12-03,m,abc,1.00', '2026-12-04,m,1234567,3.47']), 'messy.csv');
ok($c === 200 && $b['inserted'] === 1 && $b['refused'] === 3 && $b['skipped'] === 0 && $b['rows'] === 4, 'a messy file: one good row made, three refused (a bad date, a bad quantity, a cost that cannot be written exactly)');
$ir = q('SELECT * FROM usage_imports WHERE id = :i', ['i' => (int) $b['import_id']])[0];
ok(str_contains((string) $ir['detail'], 'Line 3: the date is not a day') && str_contains((string) $ir['detail'], 'the quantity is not a number') && str_contains((string) $ir['detail'], 'cannot be written exactly at six decimals') && (int) $ir['inserted'] === 1, 'the import row says why, line by line, in its detail');
[$c, $b] = upload($marco, '/usage/import.php', ['provider' => $anthropic, 'engagement' => $acme], 'file', csv_tmp([]), 'empty.csv');
ok($c === 422 && str_contains(msg($b), 'no usage rows'), 'an empty file: 422 in words (' . msg($b) . ')');

echo "7. The import deleted removes its drafts and keeps an approved one\n";
[$c, $b] = act($marco, '/usage/approve.php', ['charge' => (int) one('SELECT id FROM usage_charges WHERE import_id = :i ORDER BY id LIMIT 1', ['i' => $imp2])]);
$kept = (int) one("SELECT id FROM usage_charges WHERE import_id = :i AND status = 'approved'", ['i' => $imp2]);
ok($c === 200 && $kept > 0, 'one charge of the second import is approved');
[$c, $b] = act($priya, '/usage/import-delete.php', ['import' => $imp2]);
ok($c === 403, 'Priya: usage_import_delete → 403');
$since = last_activity_id();
[$c, $b] = act($marco, '/usage/import-delete.php', ['import' => $imp2]);
$log = activity('usage_import.delete', $since);
ok($c === 200 && $b['removed'] === 3 && (int) one('SELECT count(*) FROM usage_charges WHERE import_id = :i', ['i' => $imp2]) === 1 && usage_row($kept)['status'] === 'approved', 'usage_import_delete: the three drafts are removed, the approved charge stays');
ok(count($log) === 1 && json_decode((string) $log[0]['after'], true)['removed'] === 3 && json_decode((string) $log[0]['before'], true)['file_name'] === 'anthropic-sep.csv', 'usage_import.delete logged with the file and the count removed');
ok((string) one('SELECT detail FROM usage_imports WHERE id = :i', ['i' => $imp2]) !== '' && (int) one('SELECT inserted FROM usage_imports WHERE id = :i', ['i' => $imp2]) === 1, 'the import row stays (an approved charge still names it), saying 3 drafts were removed');
[$c, $b] = act($marco, '/usage/import-delete.php', ['import' => $imp1]);
ok($c === 200 && $b['removed'] === 2 && (int) one('SELECT count(*) FROM usage_imports WHERE id = :i', ['i' => $imp1]) === 0, 'an import whose charges are all drafts goes entirely');
[$c, $b] = act($marco, '/usage/import-delete.php', ['import' => 999999]);
ok($c === 404, 'an import that is not there: 404');
[$c, $d] = screen($marco, '/usage/import');
ok($c === 200 && count($d['providers']) >= 8 && $d['shipped'] === ['anthropic', 'openai'] && count($d['recent']) >= 2, 'the import screen (JSON): the providers, the shipped mappings, the recent imports');
finish();
