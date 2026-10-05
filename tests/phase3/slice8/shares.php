<?php
/**
 * Proof — the six shares (spec "Proof", 10; design §8, §14): the query functions the records server will expose as `shares[]`, called as the KERNEL's token (every row; the connection reads as the firm's administrator for the length of the call and is put back; `share.read`
 * logged with the tool, the argument keys and the row count) and as a PERSON (what the views and the gates admit): the same document shapes, one truth with the exports page. maludb-os.json lists exactly these six (reimbursements flagged people).
 */
require __DIR__ . '/lib.php';
$w = report_world(); $acme = $w['acme']; exports_world();
$p = whole(); $today = today();
function share(string $fn, string $caller, string $member, array $args): array
{
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/share_call.php') . ' ' . escapeshellarg($fn) . ' ' . escapeshellarg($caller) . ' ' . escapeshellarg($member) . ' ' . escapeshellarg(json_encode($args)) . ' 2>&1';
    $out = trim((string) shell_exec($cmd));
    $line = '';
    foreach (explode("\n", $out) as $l) { if (str_starts_with(ltrim($l), '{')) { $line = $l; } }
    return json_decode($line, true) ?? ['error' => 'no answer: ' . $out];
}
function rows_of(array $r): array { return $r['doc']['rows'] ?? []; }
function same_rows(array $a, array $b): bool { return json_encode($a) === json_encode($b); }
$spec = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true);

echo "1. The manifest lists exactly these six\n";
$tools = array_column($spec['shares'], 'tool');
ok($tools === ['invoices_issued', 'credit_notes_issued', 'expenses_by_category', 'unbilled_summary', 'reimbursements_approved', 'engagement_list'], 'maludb-os.json shares[]: invoices_issued, credit_notes_issued, expenses_by_category, unbilled_summary, reimbursements_approved, engagement_list');
$byTool = array_column($spec['shares'], null, 'tool');
ok(($byTool['reimbursements_approved']['people'] ?? false) === true && count(array_filter($spec['shares'], fn ($s) => !empty($s['people']))) === 1 && count(array_filter($spec['shares'], fn ($s) => !empty($s['scoped']))) === 0, 'only reimbursements_approved is people (HR reads it); none is scoped (one firm, no sites)');
ok($spec['reads'] === [['app' => 'gl', 'tool' => 'invoice_receipts', 'why' => $spec['reads'][0]['why']]] && count($spec['reads']) === 1, 'reads[]: the one read, gl.invoice_receipts');

echo "2. The kernel's token: every row\n";
$k = share('share_invoices_issued', 'kernel', '-', [$p['from'], $p['to']]);
$n = (int) one("SELECT count(*) FROM invoices WHERE status NOT IN ('draft', 'void') AND issue_date >= :f AND issue_date <= :t", ['f' => $p['from'], 't' => $p['to']]);
ok(isset($k['doc']) && $k['doc']['schema'] === 'os.ct-invoices/1' && count(rows_of($k)) === $n && $k['context_before'] === '' && $k['context_after'] === '', "invoices_issued as the kernel: all $n invoices of the period, and the connection reads as nobody again afterwards (the elevation is put back)");
$bea = json_decode(req('GET', '/exports/download.php?' . http_build_query(['export' => 'invoices', 'format' => 'json'] + $p), ['jar' => as_member(28)])['body'], true);
ok(same_rows(rows_of($k), $bea['rows']), 'the very rows Billing downloads from the exports page: one truth, two doors');
foreach (['share_credit_notes_issued' => [$p['from'], $p['to']], 'share_expenses_by_category' => [$p['from'], $p['to']], 'share_unbilled_summary' => [$today], 'share_reimbursements_approved' => [$today], 'share_engagement_list' => ['active']] as $fn => $args) {
    $kk = share($fn, 'kernel', '-', $args);
    $schema = $kk['doc']['schema'] ?? ('ERROR ' . json_encode($kk));
    ok(isset($kk['doc']) && str_starts_with($schema, 'os.ct-') && $kk['context_after'] === '' && $kk['doc']['application'] === 'consultant_tracking', "$fn as the kernel: $schema, " . count(rows_of($kk)) . ' row(s)');
}
$ek = share('share_engagement_list', 'kernel', '-', ['active']);
ok(array_column(rows_of($ek), 'number') === ['ENG-0001', 'ENG-0002', 'ENG-0003'] && array_keys(rows_of($ek)[0]) === ['number', 'name', 'client_name', 'kind', 'status', 'start_date', 'end_date', 'manager_name'] && rows_of($ek)[0]['manager_name'] === 'SMOKE Marco' && rows_of($ek)[0]['client_name'] === 'SMOKE Acme' && !str_contains(json_encode(rows_of($ek)), 'amount') && !str_contains(json_encode(rows_of($ek)), 'budget'), 'engagement_list: ENG-0001..3, the columns number, name, client_name, kind, status, start_date, end_date, manager_name — no money');
$ea = share('share_engagement_list', 'kernel', '-', ['all']);
ok(count(rows_of($ea)) >= 3 && share('share_engagement_list', 'kernel', '-', ['closed'])['doc']['rows'] === [] && isset(share('share_engagement_list', 'kernel', '-', ['nonsense'])['error']), 'status all lists every engagement; closed lists none here; nonsense is refused in words');
$rk = share('share_reimbursements_approved', 'kernel', '-', [$today]);
$wk = share('share_unbilled_summary', 'kernel', '-', [$today]);
ok(count(rows_of($rk)) === (int) one("SELECT count(*) FROM expenses WHERE status = 'approved' AND paid_with = 'personal' AND reimbursed_at IS NULL AND expense_date <= :d", ['d' => $today]) && array_keys(rows_of($rk)[0]) === ['member_id', 'expense_number', 'expense_date', 'currency', 'amount', 'description'], 'reimbursements_approved as the kernel: every approved, unreimbursed personal-card expense, keyed by member id');
$one = share('share_reimbursements_approved', 'kernel', '-', [$today, 26]);
ok(count(rows_of($one)) >= 1 && array_unique(array_column(rows_of($one), 'member_id')) === [26], 'narrowed to one member (26): only Priya\'s');
$logs = q("SELECT * FROM activity_log WHERE action = 'share.read' AND source = 'application' ORDER BY id");
$tools = array_map(fn ($l) => json_decode((string) $l['after'], true)['tool'], $logs);
ok(count($logs) >= 8 && in_array('invoices_issued', $tools, true) && in_array('reimbursements_approved', $tools, true) && $logs[0]['actor_member_id'] === null, 'share.read logged for each kernel read (source application, no actor): ' . count($logs) . ' so far');
$a = json_decode((string) end($logs)['after'], true);
ok(isset($a['direction'], $a['tool'], $a['keys'], $a['rows']) && $a['direction'] === 'in' && !str_contains(json_encode($logs), 'SMOKE') && !str_contains(json_encode($logs), '@'), 'a share.read row carries the tool, the argument keys given and the row count — no row, no name, no address');

echo "3. A person: what the gates and the views admit\n";
$priya = '26'; $marco = '27'; $beaId = '28'; $ann = '29'; $dana = '30';
foreach ([['share_invoices_issued', [$p['from'], $p['to']]], ['share_credit_notes_issued', [$p['from'], $p['to']]], ['share_expenses_by_category', [$p['from'], $p['to']]], ['share_unbilled_summary', [$today]], ['share_reimbursements_approved', [$today]]] as [$fn, $args]) {
    $r = share($fn, 'person', $priya, $args);
    ok(isset($r['refused']) && !isset($r['doc']), "Priya (a Consultant) is refused $fn: \"" . ($r['refused'] ?? json_encode($r)) . '"');
}
$pe = share('share_engagement_list', 'person', $priya, ['active']);
ok(array_column(rows_of($pe), 'number') === ['ENG-0001', 'ENG-0002'], 'Priya reads the engagement list: only the engagements she is staffed on (ENG-0001, ENG-0002)');
$de = share('share_engagement_list', 'person', $dana, ['active']);
ok(array_column(rows_of($de), 'number') === ['ENG-0001'], 'Dana (staffed on Acme only): ENG-0001');
$mi = share('share_invoices_issued', 'person', $marco, [$p['from'], $p['to']]);
ok(isset($mi['doc']) && count(rows_of($mi)) < $n && !in_array('INV-00002', array_column(rows_of($mi), 'number'), true) && in_array('INV-00001', array_column(rows_of($mi), 'number'), true), 'Marco (a Manager): the invoices of the engagements he sees — INV-00001 yes, INV-00002 (Bea\'s engagement) no — fewer than the kernel\'s ' . $n);
ok(isset(share('share_credit_notes_issued', 'person', $marco, [$p['from'], $p['to']])['refused']) && isset(share('share_reimbursements_approved', 'person', $marco, [$today])['refused']) && isset(share('share_reimbursements_approved', 'person', $ann, [$today])['refused']), 'credit notes and reimbursements are Billing\'s: Marco and Finance (Ann) refused reimbursements (people data); Marco refused credit notes');
$mw = share('share_unbilled_summary', 'person', $marco, [$today]);
ok(isset($mw['doc']) && array_column(rows_of($mw), 'engagement_number') === array_column(rows_of($wk), 'engagement_number') && same_rows(rows_of($mw), rows_of($wk)), 'Marco reads the WIP of Acme and Beta (he manages both): the same rows as the kernel');
$bi = share('share_invoices_issued', 'person', $beaId, [$p['from'], $p['to']]);
ok(same_rows(rows_of($bi), rows_of($k)) && same_rows(rows_of(share('share_credit_notes_issued', 'person', $beaId, [$p['from'], $p['to']])), rows_of(share('share_credit_notes_issued', 'kernel', '-', [$p['from'], $p['to']]))) && same_rows(rows_of(share('share_reimbursements_approved', 'person', $beaId, [$today])), rows_of($rk)), 'Bea (Billing) reads exactly what the kernel reads: invoices, credit notes, reimbursements');
$ai = share('share_invoices_issued', 'person', $ann, [$p['from'], $p['to']]);
ok(same_rows(rows_of($ai), rows_of($k)) && isset(share('share_expenses_by_category', 'person', $ann, [$p['from'], $p['to']])['doc']), 'Finance (Ann, reports.view) reads the invoices and the expenses by category');
$me = share('share_expenses_by_category', 'person', $marco, [$p['from'], $p['to']]);
ok(isset($me['doc']) && same_rows(rows_of($me), rows_of(share('share_expenses_by_category', 'kernel', '-', [$p['from'], $p['to']]))) && $me['context_after'] === '27', 'Marco\'s expenses by category equal the kernel\'s (every company-card expense is on an engagement he manages), and a person\'s connection is left as it was');
ok(isset(share('share_invoices_issued', 'person', $marco, ['2026-13-40', $p['to']])['error']) && isset(share('share_invoices_issued', 'kernel', '-', [$p['from'], '2026-02-30'])['error']), 'a bad day is refused in words (a DomainException), for a person and for the kernel');

echo "4. Documents as the tool surface shapes them\n";
$inv = array_column(rows_of($k), null, 'number')['INV-00001'];
ok(array_keys($inv) === ['number', 'client', 'engagement_number', 'issue_date', 'due_date', 'terms_days', 'currency', 'subtotal', 'tax_total', 'total', 'status', 'external_ref', 'lines_by_kind'] && $inv['total'] === '2479.00' && $inv['lines_by_kind'][1] === ['kind' => 'time', 'subtotal' => '2135.00', 'tax' => '0.00', 'total' => '2135.00'], 'os.ct-invoices/1 row, by hand: INV-00001 totals 2,479.00, its time lines 2,135.00');
$cr = share('share_credit_notes_issued', 'kernel', '-', [$p['from'], $p['to']]);
ok(array_keys(rows_of($cr)[0]) === ['number', 'client', 'engagement_number', 'invoice_number', 'issue_date', 'currency', 'subtotal', 'tax_total', 'total', 'status', 'external_ref', 'lines_by_kind'] && rows_of($cr)[0]['total'] === '250.00', 'os.ct-credit-notes/1: invoice_number where the invoice\'s dates were, 250.00 positive');
$ex = share('share_expenses_by_category', 'kernel', '-', [$p['from'], $p['to']]);
ok(array_sum(array_map(fn ($r) => (float) $r['amount'], rows_of($ex))) === 155.0 && array_sum(array_column(rows_of($ex), 'count')) === 3, 'os.ct-expenses/1: the three company-card expenses, 60.00 + 25.00 + 70.00 = 155.00 — personal ones are not in it');
ok(array_keys(rows_of($wk)[0]) === ['as_of', 'engagement_number', 'client_name', 'currency', 'time_amount', 'expense_amount', 'usage_amount', 'milestone_amount', 'total_amount'] && $wk['doc']['as_of'] === $today, 'os.ct-wip/1: the tool surface\'s columns, as_of the day asked');
finish();
