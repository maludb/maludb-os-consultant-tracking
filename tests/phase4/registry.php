<?php
/**
 * Proof — the registry wrapper and the manifest, against the KERNEL's own code (read only): deploy/kernel-registry-consultant_tracking.json + mcp/action_registry.json, composed as the installer writes mcp/registries/consultant_tracking.json, are loaded by
 * the kernel's loader (application_actions.load_registries) and registered on a FastMCP exactly as the kernel's actions server does (application_actions.register): 108 built actions, a tool each, approval categories the kernel's policies know, the
 * `confirmed` guard on destructive ones; every resolve entity is answered by THIS application's live records server through the kernel's own resolver (application_actions.resolve_via_mcp) with the caller's token; and a few whole actions are driven through
 * the kernel's tool (the approval hook and the handler stubbed). Then maludb-os.json: the agents' tool grants name tools that exist on the servers (and actions that exist in the registry), the six shares are the server's, every approval is an action with that
 * category and log event, the skills' directories exist and every tool a skill or a job description names exists. The kernel's python (its mcp/venv) runs the loader; nothing of the kernel is written.
 */
require __DIR__ . '/lib.php';
$w = p4_world(); $x = exports_world();
$root = dirname(__DIR__, 2);
$S = surface();
$wrapper = json_decode((string) file_get_contents($root . '/deploy/kernel-registry-consultant_tracking.json'), true);
$reg = json_decode((string) file_get_contents($root . '/mcp/action_registry.json'), true);
$manifest = json_decode((string) file_get_contents($root . '/maludb-os.json'), true);
$actions = $reg['actions'];
$KNOWN_CATEGORIES = ['money_out', 'deletion', 'external_send', 'other'];      // the kernel's approval_policies CHECK (db/044) and bin/app_install.php's mapping

echo "1. The wrapper, the registry and the manifest read right\n";
ok(($wrapper['schema'] ?? '') === 'maludb-os.registry/1' && $wrapper['app_key'] === 'consultant_tracking' && count($wrapper['resolve']) === 20 && $reg['generated_from'] !== null, 'the wrapper: schema maludb-os.registry/1, app_key consultant_tracking, twenty resolve entities');
$php = (int) shell_exec('cd ' . escapeshellarg($root) . ' && php bin/build_action_registry.php --check >/dev/null 2>&1; echo $?');
ok($php === 0, 'the registry is current with the action manifest (bin/build_action_registry.php --check)');
ok(count($actions) === 108 && count(array_filter($actions, fn ($a) => !empty($a['built']))) === 108, 'the registry holds 108 actions, every one built');
$cats = array_unique(array_filter(array_column($actions, 'approval')));
ok(count(array_diff($cats, $KNOWN_CATEGORIES)) === 0 && count(array_filter($actions, fn ($a) => $a['approval'] !== null)) === 57, 'every approval category is one the kernel knows (' . implode(', ', $cats) . '); 57 actions carry one');
$missing = [];
foreach ($actions as $k => $a) { $p = $root . '/html' . parse_url($a['endpoint'], PHP_URL_PATH); if (!is_file($p) && !is_file(preg_replace('/\{[a-z_]+\}/', '1', $p))) { $missing[] = $k . ' → ' . $a['endpoint']; } }
ok($missing === [], 'every action\'s endpoint exists under html/' . ($missing ? ' — missing: ' . implode(', ', array_slice($missing, 0, 5)) : ''));
$phantom = [];
foreach ($actions as $k => $a) { foreach ($a['params'] as $p) { if (($p['name'] ?? null) === null) { $phantom[] = $k; } } }
ok($phantom === [], 'no action carries a phantom parameter (a comma inside a hint splits a manifest cell: the tool would lose its real fields)' . ($phantom ? ' — ' . implode(', ', array_unique($phantom)) : ''));
$noLog = array_filter($actions, fn ($a) => empty($a['log_event']));
ok($noLog === [], 'every action names the log event the approval hook asks about');
$dupe = array_diff_key(array_column($actions, 'action'), array_unique(array_column($actions, 'action')));
ok($dupe === [] && count(array_unique(array_keys($actions))) === 108, 'no two actions share a name (the kernel skips a collision)');

echo "2. Every resolve entity: its tool exists on the records server, answers the plain list, and the kernel's resolver finds one match\n";
$recTools = $S['records'];
foreach ($wrapper['resolve'] as $entity => $spec) {
    ok(in_array($spec['tool'], $recTools, true) && ($spec['query_param'] ?? '') === 'q' && !empty($spec['id_field']) && !empty($spec['label_field']) && !empty($spec['params']), "$entity: tool {$spec['tool']} exists on the records server; id {$spec['id_field']}, label {$spec['label_field']}");
}
$pm = [];
foreach ($wrapper['resolve'] as $e => $spec) { foreach ($spec['params'] as $p) { $pm[$p][] = $e; } }
ok(count(array_filter($pm, fn ($e) => count($e) > 1)) === 0, 'no param is claimed by two entities');
$byAction = [];
foreach ($actions as $k => $a) { foreach ($a['params'] as $p) { $n = $p['name'] ?? null; if ($n !== null && isset($pm[$n])) { $byAction[$n] = ($byAction[$n] ?? 0) + 1; } } }
ok(count($byAction) >= 20, 'the resolved params are really used by actions (' . count($byAction) . ' distinct names)');
foreach (['accepted_by', 'morning_note_member', 'manager', 'to_member', 'person'] as $n) { ok(isset($pm[$n]) || count(array_filter($actions, fn ($a) => in_array($n, array_column($a['params'], 'name'), true))) === 0, "the member param '$n' resolves through find_members (or no action takes it)"); }

$cn = $x['cn'];
$resolve = [
    ['client', 'acme', atoken(27)], ['engagement', 'beta', atoken(27)], ['milestone', 'phase', atoken(27)], ['timesheet', 'dana', atoken(27)], ['time_entry', 'pending week', atoken(30)],
    ['expense', 'hotel', atoken(26)], ['usage_charge', 'USG-000006', atoken(27)], ['invoice', 'inv-00002', atoken(28)], ['credit_note', $cn, atoken(28)], ['payment_claim', 'SMOKE-P4-WIRE', atoken(28)],
    ['rate_card', 'standard', atoken(27)], ['consulting_role', 'analyst', atoken(27)], ['activity_type', 'discovery', atoken(27)], ['expense_category', 'lodging', atoken(27)], ['usage_provider', 'openai', atoken(27)],
    ['allocation_rule', 'beta', atoken(27)], ['recurring_charge', 'vm', atoken(27)], ['ai_statement', '2026-08', atoken(27)], ['member', 'dana', atoken(27)], ['department', 'accounting', atoken(27)],
];
$drive = [
    ['invoice_issue', ['invoice' => 'INV-00001', 'confirmed' => true], atoken(28)],
    ['invoice_issue', ['invoice' => 'INV-00001', 'confirmed' => true], atoken(28), 'pending_approval'],
    ['invoice_issue', ['invoice' => 'INV-00001'], atoken(28)],
    ['engagement_member_save', ['engagement' => 'beta', 'member' => 'dana', 'role' => 'analyst'], atoken(27)],
    ['expense_approve', ['expense' => 'hotel', 'confirmed' => true], atoken(27)],
    ['time_entry_write_off', ['entry' => 'pending week', 'reason' => 'SMOKE proof', 'confirmed' => true], atoken(27)],
    ['client_save', ['name' => 'SMOKE never saved'], atoken(27)],
    ['engagement_save', ['client' => 'smoke', 'name' => 'x', 'kind' => 'fixed_bid'], atoken(27)],
    ['engagement_save', ['client' => 'nonesuch', 'name' => 'x', 'kind' => 'fixed_bid'], atoken(27)],
    ['milestone_accept', ['milestone' => 'phase', 'acceptance_note' => 'ok', 'accepted_by' => 'marco', 'confirmed' => true], atoken(27)],
];
$kpy = '/var/www/mcp/venv/bin/python';
if (!is_executable($kpy)) { $kpy = trim((string) shell_exec('command -v python3')); }
$cmd = escapeshellarg($kpy) . ' ' . escapeshellarg(__DIR__ . '/registry_check.py') . ' ' . escapeshellarg($root) . ' ' . escapeshellarg('http://127.0.0.1:' . REC . '/mcp') . ' ' . escapeshellarg(json_encode(['resolve' => $resolve, 'drive' => $drive, 'env' => need('CT_DEV_ENV')])) . ' 2>/tmp/ct-registry-check.err';
$out = (string) shell_exec($cmd);
$k = json_decode($out, true);
ok(is_array($k), 'the kernel\'s loader and tool factory ran against the composed registry' . (is_array($k) ? '' : ' — ' . substr((string) @file_get_contents('/tmp/ct-registry-check.err'), -300)));
if (!is_array($k)) { finish(); }
ok($k['loaded'] === ['consultant_tracking'] && $k['registered'] === 108, 'application_actions.load_registries() accepts it and register() adds 108 tools');
ok(array_keys($k['tools']) !== [] && count(array_diff(array_keys($actions), array_keys($k['tools']))) === 0 && count($k['tools']) === 108, 'one tool per action, by the action\'s name');
$noApp = [];
foreach ($actions as $key => $a) { if ($a['approval'] !== null && !str_contains($k['tools'][$key]['description'], 'category: ' . $a['approval'])) { $noApp[] = $key; } }
ok($noApp === [], 'a tool\'s description states its approval category (' . count(array_filter($actions, fn ($a) => $a['approval'] !== null)) . ' of them)');
$noConfirm = [];
foreach ($actions as $key => $a) { $has = isset($k['tools'][$key]['schema']['properties']['params']) || true; $props = $k['tools'][$key]['schema']['$defs'] ?? []; $txt = json_encode($k['tools'][$key]['schema']); if (!empty($a['confirm']) && !str_contains($txt, 'confirmed')) { $noConfirm[] = $key; } }
ok($noConfirm === [] && count(array_filter($actions, fn ($a) => !empty($a['confirm']))) > 20, 'every destructive action carries the `confirmed` guard');
foreach ($k['resolved'] as $entity => $r) {
    $spec = $wrapper['resolve'][$entity];
    ok(!isset($r['error']) && $r['matches'] === 1 && $r['id'] !== null && $r['label'] !== null && in_array($spec['id_field'], $r['keys'], true) && in_array($spec['label_field'], $r['keys'], true), "resolver: $entity '{$r['value']}' → one match, {$spec['id_field']} = " . ($r['id'] ?? '?') . ', "' . ($r['label'] ?? $r['error'] ?? '?') . '"');
}
$id = fn ($e) => (string) $k['resolved'][$e]['id'];
$d = $k['driven'];
echo "3. Whole actions through the kernel's tool\n";
ok(($d[0]['answer']['status'] ?? '') === 'success' && $d[0]['answer']['resolved']['invoice'] === 'INV-00001 — SMOKE Acme, ' . date('M Y') && count($d[0]['posts']) === 2 && $d[0]['posts'][0]['path'] === '/approvals/hook.php' && $d[0]['posts'][1]['path'] === '/invoices/issue.php' && $d[0]['posts'][1]['fields'] === ['invoice' => '1'], 'invoice_issue "INV-00001": the name resolved to id 1 through our server, the hook asked, then POST /invoices/issue.php invoice=1');
$hook = json_decode($d[0]['posts'][0]['fields']['parameters'], true);
ok($d[0]['posts'][0]['fields']['action_key'] === 'invoice_issue' && $d[0]['posts'][0]['fields']['log_event'] === 'invoice.issue' && $hook === ['invoice' => '1'], 'the hook is asked about the LOG EVENT invoice.issue (what the kernel\'s policies match), with the resolved parameters');
ok(($d[1]['answer']['status'] ?? '') === 'pending_approval' && count($d[1]['posts']) === 1 && $d[1]['answer']['application'] === 'consultant_tracking', 'when the kernel pauses it (pending_approval): nothing is posted to the application');
ok(($d[2]['answer']['status'] ?? '') === 'needs_confirmation' && $d[2]['posts'] === [], 'without confirmed=true: needs_confirmation, nothing posted, nothing asked');
$f = $d[3]['posts'][0]['fields'] ?? [];
ok(($d[3]['answer']['status'] ?? '') === 'success' && $d[3]['posts'][0]['path'] === '/engagements/staffing/save.php' && $f['engagement'] === $id('engagement') && $f['member'] === $id('member') && $f['role'] === $id('consulting_role') && count($d[3]['posts']) === 1, 'engagement_member_save "beta" "dana" "analyst": three names, three ids — no approval category, so the handler is posted directly');
ok(($d[4]['answer']['status'] ?? '') === 'success' && ($d[4]['posts'][1]['fields']['expense'] ?? '') === $id('expense') && str_contains($d[4]['answer']['resolved']['expense'], 'SMOKE bill hotel') && str_starts_with($d[4]['answer']['resolved']['expense'], 'SMOKE Priya — '), 'expense_approve "hotel" as Marco: he resolves PRIYA\'s expense by words (the resolver reaches what the view admits an approver) — "' . ($d[4]['answer']['resolved']['expense'] ?? '?') . '"');
ok(($d[5]['answer']['status'] ?? '') === 'success' && str_starts_with($d[5]['answer']['resolved']['entry'] ?? '', 'SMOKE Dana — ') && ($d[5]['posts'][1]['fields']['entry'] ?? '') !== '', 'time_entry_write_off "pending week" as Marco: Dana\'s entry resolves, labelled with her name');
ok(($d[6]['answer']['status'] ?? '') === 'success' && $d[6]['posts'][0]['fields'] === ['name' => 'SMOKE never saved'] , 'a plain create posts its fields as given');
ok(($d[7]['answer']['status'] ?? '') === 'error' && str_contains($d[7]['answer']['message'], 'Several match') && str_contains($d[7]['answer']['message'], 'SMOKE Acme (id 1)') && $d[7]['posts'] === [], 'an ambiguous name (client "smoke"): the kernel lists the candidates with ids and posts nothing');
ok(($d[8]['answer']['status'] ?? '') === 'error' && str_contains($d[8]['answer']['message'], "No client matching 'nonesuch'") && $d[8]['posts'] === [], 'an unknown name: "No client matching \'nonesuch\' in Consultant Tracking that you can see."');
ok(($d[9]['answer']['status'] ?? '') === 'success' && ($d[9]['posts'][1]['fields']['accepted_by'] ?? '') === '27' && ($d[9]['posts'][1]['fields']['milestone'] ?? '') === $id('milestone'), 'milestone_accept: the milestone and accepted_by (a member, "marco") both resolve to ids');
$count = (int) one("SELECT count(*) FROM clients WHERE name = 'SMOKE never saved'");
ok($count === 0, 'and nothing was written to the application: the handler posts were stubs');

echo "4. maludb-os.json is in step with what the servers offer\n";
$ab = [];
foreach ($manifest['agents'] as $agent) {
    foreach ($agent['tool_grants'] as $ep => $tools) {
        $pool = ['Records MCP' => $S['records'], 'Activity MCP' => $S['activity'], 'Actions MCP' => array_keys($actions)][$ep] ?? null;
        $bad = $pool === null ? ['(unknown endpoint ' . $ep . ')'] : array_values(array_diff($tools, $pool));
        ok($bad === [] && count($tools) === count(array_unique($tools)), "{$agent['key']} · $ep: all " . count($tools) . ' granted tools exist' . ($bad ? ' — missing: ' . implode(', ', $bad) : ''));
    }
    foreach ($agent['skills'] as $s) { ok(is_file($root . '/' . $s . '/SKILL.md'), "{$agent['key']}: skill $s has its SKILL.md"); }
    ok(is_file($root . '/' . $agent['job_description']), "{$agent['key']}: job description {$agent['job_description']} exists");
}
$ep = array_column($manifest['endpoints'], null, 'name');
ok(($ep['Records MCP']['path'] ?? '') === '/mcp/records' && ($ep['Records MCP']['port_env'] ?? '') === 'MCP_RECORDS_PORT' && ($ep['Activity MCP']['path'] ?? '') === '/mcp/activity' && ($ep['Activity MCP']['port_env'] ?? '') === 'MCP_ACTIVITY_PORT' && $ep['Records MCP']['kind'] === 'mcp' && $ep['Records MCP']['agent_reachable'] === true, 'the manifest declares the two MCP endpoints the run-facts call names ("Records MCP", "Activity MCP"), on the ports the vhost and the units use');
$vh = (string) file_get_contents($root . '/deploy/apache-consultant_tracking.conf');
ok(str_contains($vh, 'ProxyPass        /mcp/records  http://127.0.0.1:{{MCP_RECORDS_PORT}}/mcp') && str_contains($vh, 'ProxyPass        /mcp/activity http://127.0.0.1:{{MCP_ACTIVITY_PORT}}/mcp'), 'the vhost proxies /mcp/records and /mcp/activity to those ports');
foreach (['records' => 'MCP_RECORDS_PORT', 'activity' => 'MCP_ACTIVITY_PORT'] as $svc => $env) {
    $u = (string) file_get_contents($root . "/deploy/consultant_tracking-$svc-mcp.service");
    ok(str_contains($u, '{{' . $env . '}}') && str_contains($u, "mcp/{$svc}_server.py") && str_contains($u, 'mcp/venv/bin/python'), "the $svc unit runs mcp/{$svc}_server.py from mcp/venv on {{" . $env . '}}');
}
$rs = (string) file_get_contents($root . '/deploy/ROOT_STEPS.sh');
ok(str_contains($rs, '8831') && str_contains($rs, '8832') && str_contains($rs, '8185'), 'ROOT_STEPS.sh pins 8185 / 8831 / 8832');
$shares = array_column($manifest['shares'], 'tool'); sort($shares);
ok($shares === $S['shares'] && $shares === ['credit_notes_issued', 'engagement_list', 'expenses_by_category', 'invoices_issued', 'reimbursements_approved', 'unbilled_summary'], 'maludb-os.json shares[] are the six tools the server answers');
$ap = $manifest['approvals'];
$wrong = [];
foreach ($ap as $a) { $r = $actions[$a['action']] ?? null; if ($r === null || $r['approval'] !== $a['category']) { $wrong[] = $a['action']; } }
ok($wrong === [] && count($ap) === 57, 'the 57 approvals of maludb-os.json are actions of the registry carrying that very category' . ($wrong ? ' — wrong: ' . implode(', ', $wrong) : ''));
$withApproval = array_keys(array_filter($actions, fn ($a) => $a['approval'] !== null)); $names = array_column($ap, 'action'); sort($withApproval); sort($names);
ok($withApproval === $names, 'and every action with a category is listed there');
ok(count(array_diff($manifest['reads'] ? array_column($manifest['reads'], 'tool') : [], ['invoice_receipts', 'reimbursements_paid'])) === 0 && count($manifest['reads']) === 2, 'reads[]: the two reads the worker makes — gl.invoice_receipts and hr.reimbursements_paid — are other applications\' tools, not ours');
$skillTools = [];
$vocab = ['time_and_materials', 'fixed_bid', 'resale', 'markup', 'cost', 'po_required', 'next_billing_date'];       // words a skill backticks that are values or columns, not tools
foreach (array_merge(glob($root . '/skills/*/SKILL.md'), glob($root . '/os/*.md')) as $f) {
    preg_match_all('/`([a-z][a-z0-9_]+)`/', (string) file_get_contents($f), $m);
    foreach (array_unique($m[1]) as $n) { if (!in_array($n, $vocab, true)) { $skillTools[$n][] = basename(dirname($f)) . '/' . basename($f); } }
}
$universe = array_merge($S['records'], $S['activity'], array_keys($actions));
$unknown = array_diff(array_keys($skillTools), $universe);
ok($unknown === [], 'every tool a skill or a job description names exists (' . count($skillTools) . ' names checked)' . ($unknown ? ' — unknown: ' . implode(', ', $unknown) : ''));
$notGranted = [];
foreach ($manifest['agents'] as $agent) {
    $granted = array_merge(...array_values($agent['tool_grants']));
    foreach (array_merge(glob($root . '/' . $agent['job_description'])) as $f) {
        preg_match_all('/`([a-z][a-z0-9_]+)`/', (string) file_get_contents($f), $m);
        foreach (array_unique($m[1]) as $n) { if (in_array($n, $universe, true) && !in_array($n, $granted, true)) { $notGranted[$agent['key']][] = $n; } }
    }
}
ok($notGranted === [], 'every tool an agent\'s own job description names is among that agent\'s grants' . ($notGranted ? ' — not granted: ' . json_encode($notGranted) : ''));
finish();
