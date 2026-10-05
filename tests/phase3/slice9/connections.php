<?php
/**
 * Proof — the Connections screen (spec "Proof", 2): the six shares of maludb-os.json with when another application last read each (the kernel's token's `share.read` rows, direction in) and the reads we make — gl.invoice_receipts (declared) and hr.reimbursements_paid (H2) — with
 * the state of each as the kernel last ANSWERED it, in every mode the fake kernel's read route offers: nothing read yet (unknown), 403 no_connection (not approved), 200 (approved, the rows), 502 provider_failed (failed), the kernel not answering (unreachable). Read-only; the
 * approval is the super-admin's in the OS, linked. settings.manage only. The kernel has no endpoint that lists connections, so the page is built from what the application holds.
 */
require __DIR__ . '/lib.php';
$w = home_world();
$owner = as_member(1); $bea = as_member(28); $marco = as_member(27); $priya = as_member(26);
$today = today();
function conn(string $jar): array { return screen($jar, '/settings/connections')[1]; }
function read_of(array $d, string $tool): array { foreach ($d['reads'] as $r) { if ($r['tool'] === $tool) { return $r; } } return []; }
function share_of(array $d, string $tool): array { foreach ($d['shares'] as $s) { if ($s['tool'] === $tool) { return $s; } } return []; }
reset_fakes();

echo "1. Nothing read yet\n";
[$c, $d] = screen($owner, '/settings/connections');
ok($c === 200 && array_keys($d) === ['shares', 'reads', 'os_url'] && count($d['shares']) === 6 && count($d['reads']) === 2, '/settings/connections (JSON): {shares[6], reads[2], os_url}');
ok(array_column($d['shares'], 'tool') === ['invoices_issued', 'credit_notes_issued', 'expenses_by_category', 'unbilled_summary', 'reimbursements_approved', 'engagement_list'] && array_unique(array_column($d['shares'], 'last_read')) === [null] && array_sum(array_column($d['shares'], 'reads')) === 0, 'the shares are the six of maludb-os.json, none read by another application yet');
ok(share_of($d, 'reimbursements_approved')['people'] === true && share_of($d, 'invoices_issued')['people'] === false, 'reimbursements_approved is marked as naming people (HR reads it); no other is');
ok(array_column($d['reads'], 'tool') === ['invoice_receipts', 'reimbursements_paid'] && array_column($d['reads'], 'provider') === ['gl', 'hr'] && array_column($d['reads'], 'state') === ['unknown', 'unknown'] && array_column($d['reads'], 'declared') === [true, false], 'the reads: gl.invoice_receipts (declared in maludb-os.json) and hr.reimbursements_paid (H2, not declared until HR ships the share), both unknown — the worker has not read them');
$r = page($owner, '/settings/connections');
ok($r['code'] === 200 && str_contains($r['body'], 'id="read-gl-invoice_receipts-state"') && str_contains($r['body'], 'Not read yet') && str_contains($r['body'], 'id="share-invoices_issued-last"') && str_contains($r['body'], 'Not read by another application yet') && str_contains($r['body'], 'id="connections-os-link"') && !str_contains($r['body'], 'is not built yet'), 'the page: a card per read and per share, "Not read yet", the OS linked');

echo "2. The kernel's token reads our shares\n";
function share_as_kernel(string $fn, array $args): array { return json_decode(trim((string) shell_exec('php ' . escapeshellarg(__DIR__ . '/../slice8/share_call.php') . ' ' . escapeshellarg($fn) . ' kernel - ' . escapeshellarg(json_encode($args)) . ' 2>&1')), true) ?? []; }
$p = whole();
$a = share_as_kernel('share_invoices_issued', [$p['from'], $p['to']]);
share_as_kernel('share_invoices_issued', [$p['from'], $p['to']]);
$b = share_as_kernel('share_engagement_list', ['active']);
ok(isset($a['doc']) && isset($b['doc']), 'the fixture: the kernel\'s token read invoices_issued twice and engagement_list once');
$d = conn($owner);
$inv = share_of($d, 'invoices_issued'); $eng = share_of($d, 'engagement_list');
ok($inv['reads'] === 2 && $inv['last_read']['rows'] === count($a['doc']['rows']) && $inv['last_read']['at'] !== null && $eng['reads'] === 1 && $eng['last_read']['rows'] === count($b['doc']['rows']) && share_of($d, 'unbilled_summary')['last_read'] === null, 'invoices_issued: read twice, the last read with its row count; engagement_list: once; unbilled_summary: still none');
$r = page($owner, '/settings/connections');
ok(str_contains($r['body'], 'id="share-invoices_issued-last"') && preg_match('/share-invoices_issued-last">\s*Last read [^<]*· ' . count($a['doc']['rows']) . ' row\(s\) · 2 read\(s\) in all/', $r['body']) === 1, 'the page says when, the rows and how many reads in all');
ok(!str_contains(json_encode($d), 'SMOKE') && !str_contains($r['body'], 'SMOKE Acme'), 'what the page keeps of a read is a time and a count — no row, no name');

echo "3. The reads we make, in each mode of the kernel\n";
[$code, $rep] = worker('receipts,reimbursements', $today . 'T07:00:00Z');       // no connection approved
$d = conn($owner);
$gl = read_of($d, 'invoice_receipts'); $hr = read_of($d, 'reimbursements_paid');
ok($gl['state'] === 'not_approved' && $gl['last']['status'] === 403 && $gl['last']['code'] === 'no_connection' && $hr['state'] === 'not_approved', 'no connection approved: 403 no_connection — both reads say "not approved" (the super-admin approves in the OS)');
$r = page($owner, '/settings/connections');
ok(str_contains($r['body'], 'Not approved yet') && str_contains($r['body'], 'no_connection'), 'the page says it in words and shows the kernel\'s code');
set_reads(['gl' => ['invoice_receipts' => ['mode' => 'ok', 'result' => ['schema' => 'os.gl-receipts/1', 'rows' => [['external_ref' => 'INV-NOPE', 'received_on' => $today, 'amount' => '1.00', 'currency' => 'USD', 'method' => 'transfer', 'reference' => 'SMOKE s9']]]]],
           'hr' => ['reimbursements_paid' => ['mode' => 'ok', 'result' => ['rows' => []]]]]);
worker('receipts,reimbursements', $today . 'T08:00:00Z');
$d = conn($owner); $gl = read_of($d, 'invoice_receipts'); $hr = read_of($d, 'reimbursements_paid');
ok($gl['state'] === 'approved' && $gl['last']['status'] === 200 && $gl['last']['rows'] === 1 && $gl['last']['code'] === null && $hr['state'] === 'approved' && $hr['last']['rows'] === 0, 'approved: the kernel answered 200 — the ledger\'s receipt read returned 1 row, HR\'s none');
$r = page($owner, '/settings/connections');
ok(preg_match('/read-gl-invoice_receipts-last">\s*Last read [^<]*· 1 row\(s\)/', $r['body']) === 1 && str_contains($r['body'], 'Connection approved'), 'the page: "Connection approved — the kernel answered", the last read and its rows');
set_reads(['gl' => ['invoice_receipts' => ['mode' => 'provider_failed']], 'hr' => ['reimbursements_paid' => ['mode' => 'ok', 'result' => ['rows' => []]]]]);
worker('receipts,reimbursements', $today . 'T09:00:00Z');
$d = conn($owner); $gl = read_of($d, 'invoice_receipts'); $hr = read_of($d, 'reimbursements_paid');
ok($gl['state'] === 'failed' && $gl['last']['status'] === 502 && $gl['last']['code'] === 'provider_failed' && $hr['state'] === 'approved', 'the provider failed (502): the ledger\'s read says "failed" with the kernel\'s code; HR\'s is untouched');
[$code, $rep, $raw] = worker('receipts', $today . 'T10:00:00Z', '', 'OS_INTERNAL_URL=http://127.0.0.1:1');
$d = conn($owner); $gl = read_of($d, 'invoice_receipts');
ok($gl['state'] === 'unreachable' && $gl['last']['status'] === 0 && $gl['last']['code'] === 'unreachable', 'the kernel does not answer: "unreachable" — nothing else is claimed');
$r = page($owner, '/settings/connections');
ok(str_contains($r['body'], 'The kernel did not answer') && str_contains($r['body'], 'The provider failed') === false, 'the page names the latest outcome only (the kernel did not answer)');
set_reads(['gl' => ['invoice_receipts' => ['mode' => 'ok', 'result' => ['rows' => []]]], 'hr' => ['reimbursements_paid' => ['mode' => 'ok', 'result' => ['rows' => []]]]]);
worker('receipts,reimbursements', $today . 'T11:00:00Z');
ok(read_of(conn($owner), 'invoice_receipts')['state'] === 'approved', 'a later read the kernel answers: approved again');

echo "4. Read-only, and who may look\n";
$since = last_activity_id();
$r = page($owner, '/settings/connections');
$inner = explode('id="connections-content"', $r['body'])[1];
ok(!preg_match('/<form|type="submit"|<button/', substr($inner, 0, (int) strpos($inner, 'id="share-engagement_list-last"'))) && (int) one("SELECT count(*) FROM activity_log WHERE id > :s AND action <> 'screen.view'", ['s' => $since]) === 0, 'no form and no button; looking writes only screen.view');
foreach ([['Priya (a Consultant)', $priya], ['Marco (a Manager)', $marco], ['Bea (Billing)', $bea]] as [$n, $j]) {
    ok(page($j, '/settings/connections')['code'] === 403, "$n: 403");
}
ok(in_array(req('GET', '/settings/connections', ['jar' => '/dev/null'])['code'], [302, 303, 401], true), 'no session: sent to sign in');
finish();
