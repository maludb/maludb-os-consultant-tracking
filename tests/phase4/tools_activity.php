<?php
/**
 * Proof — the activity server (record_history, actor_timeline, who_touched, recent_activity, share_reads, activity_search) as Priya, Dana, Marco, Bea, Ann, the owner and the Clerk. The role reads mcp_activity_log (and the fact views for names) only;
 * the view decides the rows: one's own always, everything for Billing, Finance and the admin, a visible engagement's rows for reports.view or time.approve, the log of one's own weeks. Hand-derived (the world): on Acme the log holds
 * time_entry.save 8 (Priya 3, Dana 4, the owner 1), timesheet.approve 5, usage_charge.draft 5, invoice.issue 1, expense.approve 6 — and 57 rows in all.
 */
require __DIR__ . '/lib.php';
$w = p4_world(); exports_world();
$acme = $w['acme'];
// a kernel share read and a worker row for the share_reads and actor-label proofs
mcp_tool(REC, ktoken(), 'engagement_list', [], true);

function hist(int $m, array $a): ?array { return tdata($m, 'record_history', $a, ACT); }
function viewable(int $m, string $where, array $args = []): int { return (int) val_as($m, "SELECT count(*) FROM mcp_activity_log WHERE action <> 'screen.view' AND $where", $args); }

echo "1. record_history\n";
foreach ([27, 28, 29, 1, 26, 30] as $m) {
    $d = hist($m, ['engagement_id' => $acme, 'limit' => 200]);
    ok(count($d['events']) === viewable($m, 'engagement_id = ' . $acme), "member $m: record_history of Acme returns exactly the rows the view admits (" . count($d['events']) . ')');
}
$d = hist(27, ['engagement_id' => $acme, 'limit' => 200]);
$n = array_count_values(array_column($d['events'], 'action'));
ok(count($d['events']) === 57 && $n['time_entry.save'] === 8 && $n['timesheet.approve'] === 5 && $n['usage_charge.draft'] === 5 && $n['invoice.issue'] === 1 && $n['expense.approve'] === 6 && $n['engagement.create'] === 1, 'Marco (reports.view and time.approve on Acme): the whole trail — 57 rows; time_entry.save 8, timesheet.approve 5, usage_charge.draft 5, invoice.issue 1, expense.approve 6');
ok($d['events'][0]['action'] === 'engagement.create' && $d['events'][0]['actor_label'] === 'SMOKE Marco' && $d['events'][0]['source'] === 'web' && strtotime($d['events'][0]['occurred_at']) <= strtotime(end($d['events'])['occurred_at']), 'oldest first by default; the first row is the engagement\'s creation by Marco, worded with the actor');
ok(count(array_filter($d['events'], fn ($e) => $e['action'] === 'screen.view')) === 0, 'reads (screen.view) are not history');
$e = $d['events'][0];
ok(isset($e['occurred_at_local']) && $d['timezone'] === 'UTC' && array_key_exists('before', $e) && array_key_exists('after', $e) && array_key_exists('agent_run_id', $e), 'each event carries the local time, the zone, before and after, and the run id slot');
$new = hist(27, ['engagement_id' => $acme, 'order' => 'newest', 'limit' => 3]);
ok(count($new['events']) === 3 && strtotime($new['events'][0]['occurred_at']) >= strtotime($new['events'][2]['occurred_at']), 'order=newest and limit');
$inv = hist(28, ['invoice_id' => 1]);
$acts = array_column($inv['events'], 'action');
ok(in_array('invoice.draft', $acts, true) && in_array('invoice.issue', $acts, true) && count(array_filter($inv['events'], fn ($e) => $e['invoice_id'] === 1)) === count($inv['events']), 'invoice_id=1: everything that touched INV-00001 — drafted by Marco, issued by Bea');
$iss = find_row($inv['events'], 'action', 'invoice.issue');
ok($iss['actor_label'] === 'SMOKE Bea' && $iss['entity_type'] === 'invoice' && same_num($iss['after']['total'] ?? 0, 2479) && ($iss['after']['currency'] ?? '') === 'USD', 'the issue row: Bea, an invoice, and the money event carries amount and currency in after (total 2,479.00 USD)');
$byEntity = hist(28, ['entity_type' => 'invoice', 'entity_id' => 1]);
ok(count($byEntity['events']) >= 2 && array_unique(array_column($byEntity['events'], 'entity_type')) === ['invoice'], 'entity_type + entity_id: that record\'s own rows');
$ts = (int) one('SELECT id FROM timesheets WHERE member_id = 26 AND week_start = :w', ['w' => $w['w1']]);
$t = hist(26, ['timesheet_id' => $ts]);
ok(count($t['events']) >= 3 && in_array('timesheet.approve', array_column($t['events'], 'action'), true), 'Priya reads the trail of HER OWN week, including Marco\'s approval of it (the view admits the log of one\'s own weeks)');
$ap = find_row($t['events'], 'action', 'timesheet.approve');
ok($ap['actor_label'] === 'SMOKE Marco' && ($ap['after']['member_id'] ?? null) === 26, 'worded with the actor: SMOKE Marco; the payload names the week\'s person');
$pe = hist(26, ['engagement_id' => $acme, 'limit' => 200]);
ok(count($pe['events']) < 57 && count(array_filter($pe['events'], fn ($e) => $e['actor_member_id'] !== 26 && $e['timesheet_id'] === null || ($e['timesheet_id'] !== null && $e['actor_member_id'] !== 26 && (int) one('SELECT member_id FROM timesheets WHERE id = :t', ['t' => $e['timesheet_id']]) !== 26))) === 0, 'Priya on Acme: only her own actions and the log of her own weeks (' . count($pe['events']) . ' of 57)');
ok(hist(30, ['invoice_id' => 1])['events'] === [] && hist(26, ['invoice_id' => 1])['events'] === [], 'a Consultant\'s view of an invoice\'s trail: nothing');
$since = hist(27, ['engagement_id' => $acme, 'since' => gmdate('Y-m-d', time() + 86400), 'limit' => 200]);
ok($since['events'] === [] && count(hist(27, ['engagement_id' => $acme, 'since' => '2020-01-01', 'limit' => 200])['events']) === 57, 'since: tomorrow, none; 2020, all');
ok(tool(27, 'record_history', [], ACT)['error'] && tool(27, 'record_history', ['entity_type' => 'invoice'], ACT)['error'] && tool(27, 'record_history', ['engagement_id' => 1, 'since' => 'yesterday-ish'], ACT)['error'], 'no subject, half a subject and a bad time are sentences');

echo "2. actor_timeline\n";
$d = tdata(26, 'actor_timeline', [], ACT);
ok($d['member_id'] === 26 && count($d['events']) === min(100, (int) val_as(26, 'select count(*) from mcp_activity_log where actor_member_id = 26')), 'a Consultant\'s timeline is herself by default, every row (sign-ons and views included): ' . count($d['events']) . ' events');
$by = array_column($d['by_action'], 'n', 'action');
ok(($by['time_entry.save'] ?? 0) === 3 && ($by['expense.save'] ?? 0) === 7 && ($by['expense.submit'] ?? 0) === 7, 'by action (hand): time_entry.save 3, expense.save 7, expense.submit 7');
$d = tdata(26, 'actor_timeline', ['member_id' => 'me', 'action' => 'time_entry.'], ACT);
ok(count($d['events']) === 3 && array_unique(array_column($d['events'], 'action')) === ['time_entry.save'], 'an action prefix (time_entry.): her three entries');
$d = tdata(27, 'actor_timeline', ['member_id' => '26', 'limit' => 200], ACT);
ok(count($d['events']) === viewable(27, 'actor_member_id = 26') && count($d['events']) >= 17, 'Marco reads Priya\'s timeline: the rows the view admits (' . count($d['events']) . ')');
$d = tdata(26, 'actor_timeline', ['member_id' => '27', 'limit' => 200], ACT);
ok(count($d['events']) === viewable(26, 'actor_member_id = 27') && count(array_filter($d['events'], fn ($e) => $e['action'] !== 'timesheet.approve' && $e['action'] !== 'timesheet.reject' && $e['action'] !== 'timesheet.submit' && !str_starts_with($e['action'], 'time'))) === 0, 'Priya asking for Marco\'s: only what touched her own weeks (his approvals of them)');
$d = tdata(28, 'actor_timeline', ['member_id' => '40', 'limit' => 50], ACT);
ok($d['member_id'] === 40 && count($d['events']) >= 1 && $d['events'][0]['actor_label'] === 'SMOKE Clerk (agent)' && $d['events'][0]['actor_kind'] === 'agent', 'Bea reads the Clerk\'s timeline: its rows are worded "SMOKE Clerk (agent)" (an agent is named as one)');
$d = tdata(28, 'actor_timeline', ['member_id' => '28', 'action' => 'invoice.', 'limit' => 100], ACT);
ok(count($d['events']) >= 2 && count(array_filter($d['events'], fn ($e) => !str_starts_with($e['action'], 'invoice.'))) === 0 && in_array('invoice.issue', array_column($d['events'], 'action'), true), 'Bea\'s invoice.* actions');
ok(count(tdata(28, 'actor_timeline', ['member_id' => '28', 'from' => '2099-01-01'], ACT)['events']) === 0 && count(tdata(28, 'actor_timeline', ['member_id' => '28', 'from' => '2020-01-01', 'to' => '2099-01-01', 'limit' => 5], ACT)['events']) === 5, 'from and to');
ok(tool(26, 'actor_timeline', ['member_id' => 'someone'], ACT)['error'] && tool(26, 'actor_timeline', ['from' => 'today'], ACT)['error'], 'a name for a member and a bad day are sentences');

echo "3. who_touched\n";
$d = tdata(27, 'who_touched', ['engagement_id' => $acme], ACT);
$by = array_column($d['everyone'], null, 'actor_name');
ok($d['last'] !== null && count($d['everyone']) >= 5 && $by['SMOKE Marco']['actions'] === count(array_filter(hist(27, ['engagement_id' => $acme, 'limit' => 200])['events'], fn ($e) => $e['actor_member_id'] === 27)) && $by['SMOKE Priya']['actions'] > 0, 'who_touched on Acme: Marco, Priya, Dana, Bea and the owner, each with a count — Marco\'s equals his rows in the history');
ok(isset($by['SMOKE Marco']['first_at']) && isset($by['SMOKE Marco']['last_at']) && isset($d['last']['actor_label']), 'first and last time per person; the last thing done, worded');
$d = tdata(28, 'who_touched', ['invoice_id' => 1], ACT);
ok(array_column($d['everyone'], 'actor_name') !== [] && count(array_diff(array_column($d['everyone'], 'actor_name'), ['SMOKE Marco', 'SMOKE Bea'])) === 0, 'an invoice: only Marco (the draft) and Bea (the issue) touched it');
ok(tdata(26, 'who_touched', ['invoice_id' => 1], ACT)['everyone'] === [], 'a Consultant sees nobody on an invoice');
ok(tool(27, 'who_touched', [], ACT)['error'], 'no engagement and no invoice is a sentence');
ok(count(tdata(28, 'who_touched', ['engagement_id' => $acme, 'from' => '2099-01-01'], ACT)['everyone']) === 0, 'a window with nothing in it');

echo "4. recent_activity\n";
$d = tdata(28, 'recent_activity', ['since' => '2026-01-01', 'limit' => 200], ACT);
$tot = array_sum(array_map(fn ($g) => count($g['events']), $d['by_engagement']));
ok($tot === 200 || $tot === viewable(28, 'true') - viewable(28, "action = 'screen.view'"), 'recent_activity: events grouped by engagement (' . $tot . ' events in ' . count($d['by_engagement']) . ' groups)');
$g = find_row($d['by_engagement'], 'engagement_number', 'ENG-0001');
ok($g !== null && $g['engagement_id'] === $acme && strtotime($g['events'][0]['occurred_at']) >= strtotime(end($g['events'])['occurred_at']), 'a group per engagement, newest first inside it');
ok(count($d['by_action']) >= 5 && $d['by_action'][0]['n'] >= $d['by_action'][1]['n'], 'with a count by action, most frequent first');
$d = tdata(28, 'recent_activity', ['since' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)], ACT);
ok($d['by_engagement'] === [], 'since a time in the future: nothing');
$d = tdata(27, 'recent_activity', ['since' => '2026-01-01', 'engagement_id' => $acme, 'limit' => 100], ACT);
ok(count($d['by_engagement']) === 1 && $d['by_engagement'][0]['engagement_number'] === 'ENG-0001', 'an engagement filter');
$d = tdata(26, 'recent_activity', ['since' => '2026-01-01', 'limit' => 200], ACT);
ok(array_sum(array_map(fn ($g) => count($g['events']), $d['by_engagement'])) === min(200, viewable(26, "action <> 'screen.view'")), 'Priya: only what the view admits');
ok(tool(26, 'recent_activity', [], ACT)['error'] && tool(26, 'recent_activity', ['since' => 'ages ago'], ACT)['error'], 'since is required and a day or a time');

echo "5. share_reads\n";
$d = tdata(28, 'share_reads', [], ACT);
ok(count($d['reads']) >= 1 && $d['reads'][0]['action'] === 'share.read' && $d['reads'][0]['source'] === 'application' && $d['reads'][0]['direction'] === 'in' && $d['reads'][0]['actor_label'] === 'application' && in_array($d['reads'][0]['tool'], $S = ['engagement_list', 'unbilled_summary', 'invoices_issued', 'credit_notes_issued', 'expenses_by_category', 'reimbursements_approved'], true), 'Billing: the kernel\'s reads — source application, direction in, the tool named');
ok(count(tdata(1, 'share_reads', ['limit' => 3], ACT)['reads']) === 3 && tdata(1, 'share_reads', ['from' => '2099-01-01'], ACT)['reads'] === [], 'the owner too; a window and a limit');
foreach ([27, 26, 29, 30] as $m) { $r = tool($m, 'share_reads', [], ACT); ok($r['error'] && str_contains($r['text'], 'billing.write'), "member $m: refused in words (billing.write or settings.manage)"); }
$aReads = tdata(28, 'share_reads', ['app' => 'hr'], ACT);
ok($aReads['reads'] === [], 'an app filter: the kernel\'s inbound rows name no application, so none match "hr"');

echo "6. activity_search and the payload rules\n";
$r = tool(1, 'activity_search', ['sql' => "select count(*) as n from mcp_activity_log where action <> 'screen.view'"], ACT);
ok(!$r['error'] && $r['data'][0]['n'] === viewable(1, 'true'), 'activity_search: a count over the view as the owner equals the view\'s');
$r = tool(26, 'activity_search', ['sql' => 'select count(*) as n from mcp_activity_log'], ACT);
ok(!$r['error'] && $r['data'][0]['n'] === (int) val_as(26, 'select count(*) from mcp_activity_log'), 'as Priya: only her rows');
$r = tool(27, 'activity_search', ['sql' => "select l.action, m.display_name from mcp_activity_log l join mcp_members m on m.member_id = l.actor_member_id where l.engagement_id = $acme limit 3"], ACT);
ok(!$r['error'] && count($r['data']) === 3, 'a join to the names works (mcp_members is readable here)');
$r = tool(27, 'activity_search', ['sql' => 'select count(*) from mcp_engagements'], ACT);
ok(!$r['error'] && $r['data'][0]['count'] === 2, 'and to the fact views (mcp_engagements: Marco\'s two)');
foreach (['select * from activity_log', 'select * from members', 'select * from mcp_time_entries', 'delete from mcp_activity_log', 'select 1; select 2', "select set_config('app.member_id','1',true)"] as $sql) {
    ok(tool(27, 'activity_search', ['sql' => $sql], ACT)['error'], 'refused: ' . substr($sql, 0, 50));
}
$bad = tool(1, 'activity_search', ['sql' => "select count(*) as n from mcp_activity_log where (after::text ~* '(cost_rate|hourly_cost|secure_token|token_hash|\"email\"|\"phone\")' or before::text ~* '(cost_rate|hourly_cost|secure_token|token_hash|\"email\"|\"phone\")')"], ACT);
ok(!$bad['error'] && $bad['data'][0]['n'] === 0, 'no payload anywhere in the log carries a cost rate, a secure token, a token hash, an email or a phone');
$amt = tool(1, 'activity_search', ['sql' => "select count(*) as n from mcp_activity_log where action in ('invoice.issue', 'payment.record', 'credit_note.issue') and (after->>'currency') is null"], ACT);
ok(!$amt['error'] && $amt['data'][0]['n'] === 0, 'every invoice.issue, payment.record and credit_note.issue row carries a currency in after');
$clerk = agent_grants('biller'); $run = random_int(900000, 999999);
facts($run, 40, $clerk);
$ct = rtoken(40, $run);
ok(mcp_tools(ACT, $ct) === ['record_history'], 'the Clerk is offered record_history alone on the activity server');
$r = mcp_tool(ACT, $ct, 'record_history', ['engagement_id' => $acme, 'limit' => 5]);
ok(!$r['error'] && count($r['data']['events']) === 5, 'and it answers as the Clerk (billing sees everything)');
ok(mcp_tool(ACT, $ct, 'actor_timeline', [])['error'] && mcp_tool(ACT, $ct, 'activity_search', ['sql' => 'select 1'])['error'], 'the tools it was not granted are refused');
finish();
