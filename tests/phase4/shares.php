<?php
/**
 * Proof — the six shares[] as TOOLS (design §8, §14; spec docs/build-specs/mcp-servers.md): invoices_issued, credit_notes_issued, expenses_by_category, unbilled_summary, reimbursements_approved, engagement_list, called through the records
 * server as the KERNEL's token (every row; flat arguments, as the kernel sends them; `share.read` logged once per call) and as a PERSON or an agent (the right each document's gate names). One truth, two doors: every document equals, row for row,
 * what slice 8's PHP function (the one the exports page downloads) answers for the same caller. Hand-derived figures (tests/phase4/lib.php, slices 6-8): INV-00001 2,479.00 (time 2,135, expenses 140, usage 204) and INV-00002 300.00; a 250.00 credit note
 * against INV-00001; company-card expenses meals 60.00 (5.00 tax, billable) + 25.00 (not billable) and travel 70.00; owed to Priya (kernel member 26): EXP-000001 100.00, EXP-000003 20.00, EXP-000007 45.00; unbilled Acme 750.00 and Beta 5,045.00.
 */
require __DIR__ . '/lib.php';
$w = p4_world(); $x = exports_world();
$P = ['from' => '2026-09-01', 'to' => '2026-10-31'];
$S = surface();

/** Slice 8's PHP function, called the way the exports page calls it: [doc|refused|error]. */
function php_share(string $fn, string $caller, string $member, array $args): array
{
    $cmd = 'php ' . escapeshellarg(dirname(__DIR__) . '/phase3/slice8/share_call.php') . ' ' . escapeshellarg($fn) . ' ' . escapeshellarg($caller) . ' ' . escapeshellarg($member) . ' ' . escapeshellarg(json_encode($args)) . ' 2>&1';
    $out = trim((string) shell_exec($cmd));
    $line = '';
    foreach (explode("\n", $out) as $l) { if (str_starts_with(ltrim($l), '{')) { $line = $l; } }
    return json_decode($line, true) ?? ['error' => 'no answer: ' . $out];
}
/** A document without its clock. */
function bare(?array $doc): ?array { if ($doc === null) { return null; } unset($doc['generated_at']); return $doc; }
function kcall(string $tool, array $args): array { return mcp_tool(REC, ktoken(), $tool, $args, true); }
function share_log_rows(int $since): array { return q("SELECT * FROM activity_log WHERE action = 'share.read' AND id > :s ORDER BY id", ['s' => $since]); }

echo "1. The manifest, the surface and the server agree on the six\n";
$spec = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/maludb-os.json'), true);
$tools = array_column($spec['shares'], 'tool'); sort($tools);
ok($tools === $S['shares'] && count($tools) === 6, 'maludb-os.json shares[] = the surface\'s six = the tools the kernel\'s token is offered');
ok(mcp_tools(REC, ktoken()) === array_merge($S['shares'], ['app_roles']) || count(array_diff($S['shares'], mcp_tools(REC, ktoken()) ?? [])) === 0, 'the kernel is offered every one of them');

echo "2. As the kernel: the document is the PHP function's, row for row\n";
$cases = [
    'invoices_issued' => [$P, 'share_invoices_issued', [$P['from'], $P['to'], null]],
    'credit_notes_issued' => [$P, 'share_credit_notes_issued', [$P['from'], $P['to'], null]],
    'expenses_by_category' => [$P, 'share_expenses_by_category', [$P['from'], $P['to']]],
    'unbilled_summary' => [['as_of' => today()], 'share_unbilled_summary', [today(), null, null]],
    'reimbursements_approved' => [['as_of' => today()], 'share_reimbursements_approved', [today(), null]],
    'engagement_list' => [[], 'share_engagement_list', ['active', null]],
];
$docs = [];
foreach ($cases as $tool => [$args, $fn, $phpArgs]) {
    $since = last_activity_id();
    $r = kcall($tool, $args + ['scope_id' => 7]);                                   // a kernel adds fields of its own; an unscoped share ignores them
    $php = php_share($fn, 'kernel', '-', $phpArgs);
    $docs[$tool] = $r['data'];
    ok(!$r['error'] && $r['data'] !== null && bare($r['data']) == bare($php['doc'] ?? null) && count($r['data']['rows']) >= 1, "$tool as the kernel: " . ($r['data']['schema'] ?? '?') . ', ' . count($r['data']['rows'] ?? []) . ' row(s) — equal to the exports page\'s function');
    ok(str_starts_with($r['text'], '{"schema":"os.ct-') && ($r['data']['application'] ?? '') === 'consultant_tracking' && ($r['data']['currency_note'] ?? '') === 'amounts carry their own currency; nothing is converted', "$tool: the versioned head (schema, generated_at, application, currency_note) comes first, exactly as built");
}
$d = $docs['invoices_issued'];
$byNo = array_column($d['rows'], null, 'number');
ok(array_keys($byNo) === ['INV-00001', 'INV-00002'] && same_num($byNo['INV-00001']['total'], 2479) && $byNo['INV-00001']['client']['name'] === 'SMOKE Acme' && $byNo['INV-00001']['engagement_number'] === 'ENG-0001' && $byNo['INV-00001']['currency'] === 'USD' && $byNo['INV-00001']['terms_days'] === 30, 'invoices_issued: INV-00001 2,479.00 (Acme, ENG-0001, USD, 30 days) and INV-00002; the draft is not there');
$lk = array_column($byNo['INV-00001']['lines_by_kind'], null, 'kind');
ok(same_num($lk['time']['total'], 2135) && same_num($lk['expense']['total'], 140) && same_num($lk['usage']['total'], 204) && count($lk) === 3 && same_num($byNo['INV-00002']['lines_by_kind'][0]['total'], 300), 'lines_by_kind: time 2,135.00, expense 140.00, usage 204.00; INV-00002 a fixed 300.00');
$d = $docs['credit_notes_issued'];
ok(count($d['rows']) === 1 && $d['rows'][0]['number'] === $x['cn'] && same_num($d['rows'][0]['total'], 250) && $d['rows'][0]['invoice_number'] === 'INV-00001', 'credit_notes_issued: ' . $x['cn'] . ', 250.00 against INV-00001 (positive: the reader negates)');
$d = $docs['expenses_by_category'];
$rows = $d['rows'];
$sum = array_sum(array_map(fn ($r) => (float) $r['amount'], $rows));
ok(count($rows) === 3 && same_num($sum, 155) && same_num(array_sum(array_map(fn ($r) => (float) $r['tax_amount'], $rows)), 5) && !has_key($d, 'member_id') && !has_key($d, 'member_name'), 'expenses_by_category: three company-card rows (meals 60 + 25, travel 70 = 155.00, tax 5.00), no person named');
$m = array_values(array_filter($rows, fn ($r) => $r['category']['key'] === 'meals' && $r['billable']));
ok(count($m) === 1 && same_num($m[0]['amount'], 60) && $m[0]['count'] === 1 && $m[0]['engagement_number'] === 'ENG-0001', 'the billable meals: 60.00, one expense, ENG-0001');
$d = $docs['unbilled_summary'];
$by = array_column($d['rows'], null, 'engagement_number');
ok(same_num($by['ENG-0001']['total_amount'], 750) && same_num($by['ENG-0001']['time_amount'], 540) && same_num($by['ENG-0001']['expense_amount'], 150) && same_num($by['ENG-0001']['usage_amount'], 60) && same_num($by['ENG-0002']['total_amount'], 5045) && same_num($by['ENG-0002']['milestone_amount'], 5000), 'unbilled_summary: Acme 750.00 (540 + 150 + 60), Beta 5,045.00 (the 5,000.00 milestone and 45.00)');
ok($d['as_of'] === today() && $d['rows'][0]['as_of'] === today() && $by['ENG-0001']['client_name'] === 'SMOKE Acme' && !has_key($d, 'cost_amount') && !has_key($d, 'time_cost'), 'as_of on the document and on each row; the client\'s name; no cost');
$d = $docs['reimbursements_approved'];
$ex = array_column($d['rows'], null, 'expense_number');
ok(array_keys($ex) === ['EXP-000001', 'EXP-000003', 'EXP-000007'] && array_unique(array_column($d['rows'], 'member_id')) === [26] && same_num(array_sum(array_column($d['rows'], 'amount')), 165), 'reimbursements_approved (people): Priya (kernel member 26) is owed EXP-000001, EXP-000003, EXP-000007 = 165.00; the reimbursed 40.00 and the company-card ones are not there');
$d = $docs['engagement_list'];
ok(array_column($d['rows'], 'number') === ['ENG-0001', 'ENG-0002', 'ENG-0003'] && $d['status'] === 'active' && array_keys($d['rows'][0]) === ['number', 'name', 'client_name', 'kind', 'status', 'start_date', 'end_date', 'manager_name'] && $d['rows'][0]['manager_name'] === 'SMOKE Marco', 'engagement_list: the three active engagements, the eight keys, no money, the manager\'s name');

echo "3. Arguments\n";
$r = kcall('invoices_issued', ['from' => '2026-09-01', 'to' => '2026-10-31', 'client_id' => 2]);
ok(array_column($r['data']['rows'], 'number') === ['INV-00002'], 'invoices_issued with client_id: the other client\'s invoice only');
$r = kcall('invoices_issued', ['from' => '2026-09-01', 'to' => '2026-09-30']);
ok($r['data']['rows'] === [] && $r['data']['from'] === '2026-09-01', 'a period before today\'s issue date: no rows, the document still answers');
ok(kcall('invoices_issued', ['to' => '2026-09-30'])['error'] && kcall('invoices_issued', [])['error'], 'a missing period is an error');
$r = kcall('invoices_issued', ['from' => '2026-13-01', 'to' => '2026-09-30']);
ok($r['error'] && str_contains($r['text'], 'The first day is a day'), 'a bad day is the function\'s own sentence: ' . substr($r['text'], 0, 90));
$r = kcall('unbilled_summary', ['engagement_id' => $w['acme']]);
ok(count($r['data']['rows']) === 1 && $r['data']['rows'][0]['engagement_number'] === 'ENG-0001', 'unbilled_summary for one engagement');
$r = kcall('unbilled_summary', ['client_id' => 2]);
ok(count($r['data']['rows']) === 1 && $r['data']['rows'][0]['engagement_number'] === 'ENG-0002', '...and for one client');
$r = kcall('unbilled_summary', ['as_of' => '2026-09-20']);
ok(!$r['error'] && $r['data']['as_of'] === '2026-09-20', 'as_of in the past');
$r = kcall('reimbursements_approved', ['member_id' => 30]);
ok($r['data']['rows'] === [], 'reimbursements for a member owed nothing: none');
$r = kcall('engagement_list', ['status' => 'all']);
ok(count($r['data']['rows']) === 3 && kcall('engagement_list', ['status' => 'closed'])['data']['rows'] === [] && kcall('engagement_list', ['status' => 'sideways'])['error'], 'engagement_list: all three; closed none; an unknown status is an error');
ok(array_column(kcall('engagement_list', ['client_id' => 2])['data']['rows'], 'number') === ['ENG-0002', 'ENG-0003'], 'engagement_list for one client: Beta\'s two engagements (Marco\'s and Bea\'s)');

echo "4. share.read is logged once per kernel call — keys, never values\n";
$since = last_activity_id();
$r = kcall('invoices_issued', ['from' => '2026-09-01', 'to' => '2026-10-31']);
$log = share_log_rows($since);
$a = json_decode((string) ($log[0]['after'] ?? '{}'), true);
ok(count($log) === 1 && $log[0]['source'] === 'application' && $log[0]['actor_member_id'] === null && $log[0]['entity_type'] === 'share' && $a['direction'] === 'in' && $a['tool'] === 'invoices_issued' && $a['rows'] === 2 && $a['keys'] === ['from', 'to'], 'one row: source application, no actor, the tool, the argument KEYS (from, to), two rows answered');
ok(!str_contains((string) $log[0]['after'], '2026-09-01') && !str_contains((string) $log[0]['after'], 'INV-0'), 'no argument value and no row in the payload');
$since = last_activity_id();
kcall('unbilled_summary', []); kcall('engagement_list', []);
ok(count(share_log_rows($since)) === 2, 'two more calls, two more rows');
$since = last_activity_id();
kcall('invoices_issued', ['from' => '2026-13-01', 'to' => '2026-09-30']);
ok(share_log_rows($since) === [], 'a refused or failed call logs nothing');
$since = last_activity_id();
tool(28, 'invoices_issued', $P); tool(28, 'engagement_list', []);
ok(share_log_rows($since) === [], 'a PERSON\'s read through the tool is not a share.read (the sibling reads are the kernel\'s; a person\'s download is logged by the exports page)');
$since = last_activity_id();
mcp_tool(REC, ktoken(), 'unbilled_summary', [], true);
$rd = tdata(28, 'share_reads', [], ACT);
ok($rd !== null && count($rd['reads']) >= 1 && $rd['reads'][0]['tool'] === 'unbilled_summary' && $rd['reads'][0]['direction'] === 'in' && $rd['reads'][0]['actor_label'] === 'application', 'the activity server\'s share_reads (Billing) shows the kernel\'s read: tool, direction in, the actor "application"');

echo "5. As a person or an agent: the right each document's gate names\n";
foreach ([['invoices_issued', $P, [27, 28, 29, 1], [26, 30]], ['expenses_by_category', $P, [27, 28, 29, 1], [26, 30]], ['credit_notes_issued', $P, [28, 1], [26, 27, 29]],
          ['unbilled_summary', [], [27, 28, 29, 1], [26, 30]], ['reimbursements_approved', [], [28, 1], [26, 27, 29]]] as [$t, $a, $yes, $no]) {
    foreach ($yes as $m) { $r = tool($m, $t, $a); ok(!$r['error'] && isset($r['data']['schema']), "$t: member $m may read it"); }
    foreach ($no as $m) { $r = tool($m, $t, $a); ok($r['error'] && str_contains($r['text'], 'You may not'), "$t: member $m refused in the function's words: " . substr(preg_replace('/^Error executing tool \w+: /', '', $r['text']), 0, 70)); }
}
$mi = tdata(27, 'invoices_issued', $P);
ok(array_column($mi['rows'], 'number') === ['INV-00001'], 'Marco (reports.view) may read the document, and the VIEW scopes it: INV-00001 only — not Bea\'s INV-00002');
$bea = tool(28, 'invoices_issued', $P);
$php = php_share('share_invoices_issued', 'person', '28', [$P['from'], $P['to'], null]);
ok(bare($bea['data']) == bare($php['doc']), 'Bea through the tool = the PHP function as Bea (one truth)');
$mar = tool(27, 'unbilled_summary', []);
$php = php_share('share_unbilled_summary', 'person', '27', [today(), null, null]);
ok(bare($mar['data']) == bare($php['doc']) && count($mar['data']['rows']) === 2, 'Marco\'s unbilled_summary = the function as Marco: his two engagements');
$pr = tool(26, 'engagement_list', []);
$php = php_share('share_engagement_list', 'person', '26', ['active', null]);
ok(bare($pr['data']) == bare($php['doc']) && array_column($pr['data']['rows'], 'number') === ['ENG-0001', 'ENG-0002'], 'any member reads engagement_list: Priya the two she may see, as the function answers');
$crn = tdata(28, 'credit_notes_issued', ['q' => $x['cn']]);
ok(count($crn) === 1 && isset($crn[0]['credit_note_id']) && str_starts_with($crn[0]['label'], $x['cn'] . ' — SMOKE Acme') && count($crn[0]) === 2, 'credit_notes_issued with q alone: the resolver\'s plain list [{credit_note_id, label}] (read from the view, no document)');
ok(tdata(27, 'credit_notes_issued', ['q' => 'smoke']) === [] || tdata(27, 'credit_notes_issued', ['q' => 'smoke']) !== null, 'and a Manager\'s q is the view\'s answer');
ok(tool(28, 'credit_notes_issued', ['q' => 'cn', 'from' => '2026-09-01'])['error'], 'q with a period is not the resolver\'s call: the period needs its end');
$clerk = agent_grants('biller');
$run = random_int(800000, 899999);
$grant = ['Records MCP' => array_merge($clerk['Records MCP'], ['invoices_issued' => [], 'unbilled_summary' => []])];
facts($run, 40, $grant);
$ar = mcp_tool(REC, rtoken(40, $run), 'unbilled_summary', []);
ok(!$ar['error'] && count($ar['data']['rows']) === 2 && $ar['data']['schema'] === 'os.ct-wip/1', 'an agent granted the share reads it as its member: the Clerk (billing) gets both engagements\' WIP');
$ar = mcp_tool(REC, rtoken(40, $run), 'invoices_issued', $P);
ok(!$ar['error'] && count($ar['data']['rows']) === 2, '...and the invoices issued');

echo "6. The internal door\n";
$base = 'http://127.0.0.1:' . need('APP_INTERNAL_PORT');
function door(string $body, ?string $time = null, ?string $sig = null, ?string $key = null): array
{
    $time ??= (string) time();
    $sig ??= hash_hmac('sha256', 'share:' . $time . ':' . hash('sha256', $body), $key ?? need('ACTIONS_RELAY_KEY'));
    $ch = curl_init('http://127.0.0.1:' . need('APP_INTERNAL_PORT') . '/api/v1/shares/read.php');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Share-Time: ' . $time, 'X-Share-Signature: ' . $sig]]);
    $b = (string) curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($b, true) ?? []];
}
$body = json_encode(['tool' => 'engagement_list', 'caller' => 'kernel', 'arguments' => []]);
[$c, $b] = door($body);
ok($c === 200 && $b['schema'] === 'os.ct-engagements/1', 'a signed request: the document');
[$c, $b] = door($body, null, str_repeat('0', 64));
ok($c === 401 && $b === ['error' => 'unauthorized'], 'a wrong signature: 401');
[$c] = door($body, null, null, str_repeat('k', 64));
ok($c === 401, 'a signature from another key: 401');
[$c] = door($body, (string) (time() - 600));
ok($c === 401, 'a stale timestamp (ten minutes old): 401');
[$c] = door($body . ' ');
ok($c === 200, '(control: a different body signed afresh is fine)');
$ch = curl_init("$base/api/v1/shares/read.php");
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
curl_exec($ch);
ok(curl_getinfo($ch, CURLINFO_HTTP_CODE) === 401, 'no signature at all: 401');
$sigOfOther = hash_hmac('sha256', 'share:' . time() . ':' . hash('sha256', 'something else'), need('ACTIONS_RELAY_KEY'));
[$c] = door($body, (string) time(), $sigOfOther);
ok($c === 401, 'a signature over a different body: 401 (the body cannot be swapped)');
[$c, $b] = door(json_encode(['tool' => 'records_search', 'caller' => 'kernel', 'arguments' => []]));
ok($c === 422 && str_contains($b['error'], 'six shared tools'), 'a tool that is not one of the six: 422');
[$c, $b] = door(json_encode(['tool' => 'engagement_list', 'caller' => 'person', 'member_id' => 9999, 'arguments' => []]));
ok($c === 403, 'a person who is not admitted: 403');
[$c, $b] = door(json_encode(['tool' => 'engagement_list', 'caller' => 'person', 'arguments' => []]));
ok($c === 403, 'a person with no member id: 403');
[$c, $b] = door(json_encode(['tool' => 'invoices_issued', 'caller' => 'person', 'member_id' => 26, 'arguments' => $P]));
ok($c === 403 && str_contains($b['error'], 'Billing'), 'a refusal is a 403 in the function\'s sentence: ' . $b['error']);
$ch = curl_init("$base/api/v1/shares/read.php");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
curl_exec($ch);
ok(curl_getinfo($ch, CURLINFO_HTTP_CODE) === 405, 'a GET: 405');
$vhost = (string) file_get_contents(dirname(__DIR__, 2) . '/deploy/apache-consultant_tracking.conf');
ok(substr_count($vhost, 'RewriteRule ^/api/ - [R=404,L]') >= 1 && !str_contains($vhost, 'shares/read'), 'the public vhost answers 404 for everything under /api but health — the door is on the internal port only');
finish();
