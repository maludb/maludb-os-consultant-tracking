<?php
/**
 * The record picker's proof (docs/build-specs/record-picker.md; the cidery's scripts/prove-picker.sh is the model). Run through tests/picker/run.sh, which builds the scratch
 * database and the servers. Builds on slice 3's world (Acme and Beta engagements, Priya and Dana staffed, Marco their manager) and adds an unstaffed Gamma engagement,
 * 30 clients and 30 engagements to page through. Four parts: the code; the endpoint (refusals, visibility, search, escaping, paging, the dependent param); every converted
 * form renders its picker under the old name and id; a refusal re-render keeps the chosen label and writes go through the hidden inputs exactly as they did through the selects.
 */
require dirname(__DIR__) . '/phase3/slice3/lib.php';
[$acme, $beta] = time_world();

function get_(string $jar, string $path): array { $r = req('GET', $path, ['jar' => $jar]); return [$r['code'], $r['body']]; }
function rows_of(string $html): array { preg_match_all('/data-value="(\d+)" data-label="([^"]*)"/', $html, $m); return array_combine($m[1], array_map('html_entity_decode', $m[2])) ?: []; }
function has(string $html, string $needle): bool { return str_contains($html, $needle); }
function pick(string $jar, string $query): array { return get_($jar, '/pick/?' . $query); }

$owner = as_member(1); $priya = as_member(26); $marco = as_member(27); $bea = as_member(28); $ann = as_member(29); $dana = as_member(30);
$root = dirname(__DIR__, 2);

echo "== 1. the code\n";
foreach (['app/picker.php', 'app/pickers.php', 'html/pick/index.php', 'app/views/shared/picker-rows.php', 'app/views/layout.php', 'app/features/clients/queries.php', 'app/handler.php'] as $f) {
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc); ok($rc === 0, "lint $f");
}
$grep = static fn (string $pattern): string => trim((string) shell_exec('grep -rnE ' . escapeshellarg($pattern) . ' ' . escapeshellarg("$root/app") . ' ' . escapeshellarg("$root/html") . ' --include=*.php 2>/dev/null'));
ok($grep('<select name="(client|engagement|member|manager|accepted_by|morning_note_member)"') === '', 'no <select> of a client, engagement or member remains');
$layout = (string) file_get_contents("$root/app/views/layout.php");
ok(has($layout, 'id="record-picker"') && has($layout, 'hx-vals="js:{...recordPickerValues()}"') && has($layout, 'record-picker.js'), 'the shell carries the one modal, its search box and the script');
ok(has((string) file_get_contents("$root/html/assets/css/app-overrides.css"), 'RECORD PICKER'), 'app-overrides.css has the picker section');
exec('node --check ' . escapeshellarg("$root/html/assets/js/record-picker.js") . ' 2>&1', $o2, $rc2); ok($rc2 === 0, 'node parses the script');
ok(has((string) file_get_contents("$root/docs/consultant-tracking-action-manifest.md"), '/pick/?source='), 'the action manifest lists the /pick/ URL convention');

echo "== 2. the world\n";
// Repeatable: an engagement is never deleted (the database refuses), so every row is found by name before it is made.
$client_ = static fn (string $name, ?string $code = null): int => (int) (one('SELECT id FROM clients WHERE name = :n', ['n' => $name]) ?: mkclient($name, ['code' => $code]));
$engagement_ = static fn (int $client, string $name): int => (int) (one('SELECT id FROM engagements WHERE name = :n', ['n' => $name]) ?: mkengagement($client, $name));
$gammaClient = $client_('SMOKE Pk Gamma', 'PKG');
$gamma = $engagement_($gammaClient, 'SMOKE Pk Gamma work');                      // nobody is staffed on it; Marco manages it
q("UPDATE engagements SET status = 'active' WHERE id = :g", ['g' => $gamma]);
$lonely = $client_('SMOKE Pk Lonely', 'PKL');                                     // a client with no engagement at all
for ($n = 1; $n <= 30; $n++) { $engagement_($client_(sprintf('SMOKE Pk Client %02d', $n)), sprintf('SMOKE Pk Eng %02d', $n)); }
$totalClients = (int) one('SELECT count(*) FROM clients WHERE archived_at IS NULL');
$totalEng = (int) one('SELECT count(*) FROM engagements');
ok(right_of(26, 'engagements.manage') === false && right_of(26, 'invoices.draft') === false && right_of(27, 'usage.write') && right_of(28, 'billing.write'), 'the cast holds the rights the probes assume');

echo "== 3. the endpoint: refusals\n";
$r = req('GET', '/pick/?source=client');
ok($r['code'] === 401 && has($r['body'], 'Sign in again') && !has($r['body'], '<html'), "anonymous: 401 and a fragment, no redirect into the modal ({$r['code']})");
[$c, $b] = pick($owner, 'source=nope'); ok($c === 404 && has($b, 'That list does not exist.') && !has($b, '<html'), "an unknown source: 404 and a fragment ($c)");
[$c] = pick($owner, 'source=../etc'); ok($c === 404, 'a source key is cleaned before the lookup');
[$c] = pick($owner, 'source='); ok($c === 404, 'no source: 404');
foreach (['member' => $priya, 'client_live' => $priya, 'engagement_draftable' => $priya, 'engagement_managed' => $priya, 'engagement_loggable' => $ann] as $src => $jar) {
    [$c, $b] = pick($jar, "source=$src"); ok($c === 403 && has($b, 'permission') && !has($b, '<html'), "a consultant / finance reader may not choose from $src (403, a fragment)");
}
foreach (['client' => $priya, 'engagement' => $priya, 'engagement_loggable' => $priya, 'member' => $ann, 'engagement_draftable' => $bea, 'engagement_managed' => $marco, 'client_live' => $marco] as $src => $jar) {
    [$c] = pick($jar, "source=$src"); ok($c === 200, "the right person may choose from $src ($c)");
}

echo "== 4. the endpoint: what a person may pick is what they may see\n";
[, $b] = pick($priya, 'source=engagement'); $mine = rows_of($b);
ok(isset($mine[$acme]) && isset($mine[$beta]) && !isset($mine[$gamma]) && count($mine) === 2, 'Priya (staffed on Acme and Beta) is offered those two engagements, never the firm\'s');
[, $b] = pick($dana, 'source=engagement'); $d = rows_of($b);
ok(isset($d[$acme]) && !isset($d[$beta]) && count($d) === 1, 'Dana (staffed on Acme only) is offered Acme only');
[, $b] = pick($owner, 'source=engagement&q=' . urlencode('Gamma')); ok(isset(rows_of($b)[$gamma]), 'the owner sees Gamma');
[, $b] = pick($priya, 'source=client'); $pc = array_values(rows_of($b));
ok(in_array('SMOKE Acme', $pc, true) && !in_array('SMOKE Pk Gamma', $pc, true) && !in_array('SMOKE Pk Lonely', $pc, true), 'Priya is offered her engagements\' clients, not the firm\'s');
[, $b] = pick($marco, 'source=client_live&q=Lonely'); ok(in_array('SMOKE Pk Lonely', rows_of($b), true), 'a client with no engagement is offered to the manager making one (client_live)');
[, $b] = pick($owner, 'source=client&q=Lonely'); ok(in_array('SMOKE Pk Lonely', rows_of($b), true), 'the owner sees every client');
[, $b] = pick($marco, 'source=engagement_managed&q=Gamma'); ok(isset(rows_of($b)[$gamma]), 'a manager is offered the live engagements they manage');
[, $b] = pick($marco, 'source=engagement_draftable&q=Gamma'); ok(isset(rows_of($b)[$gamma]), 'draftable: a manager is offered the engagements they may draft an invoice for');
q("UPDATE engagements SET status = 'closed' WHERE id = :g", ['g' => $gamma]);
[, $b] = pick($marco, 'source=engagement_managed&q=Gamma'); ok(!isset(rows_of($b)[$gamma]), 'a closed engagement is not offered for a usage charge');
[, $b] = pick($owner, 'source=engagement&q=Gamma'); $g = rows_of($b); ok(isset($g[$gamma]) && has($b, '>Closed<'), 'the generic engagement list shows a closed engagement with its status badge');
q("UPDATE engagements SET status = 'active' WHERE id = :g", ['g' => $gamma]);
$day = (string) one("SELECT to_char(current_date, 'YYYYMMDD')");
[, $b] = pick($priya, "source=engagement_loggable&member_id=26&on=$day"); ok(count(rows_of($b)) === 2, 'loggable: Priya today on the two engagements she is staffed on');
[, $b] = pick($priya, 'source=engagement_loggable&member_id=26&on=20251201'); ok(rows_of($b) === [] && has($b, 'record-picker-empty'), 'loggable: before the engagements started, nothing');
[, $b] = pick($priya, "source=engagement_loggable&member_id=30&on=$day"); ok(count(rows_of($b)) === 1 && isset(rows_of($b)[$acme]), 'loggable for Dana (a manager logs for someone): only what Priya sees of Dana\'s engagements');
[, $b] = pick($priya, 'source=engagement_loggable&on=20269999'); ok(count(rows_of($b)) === 2, 'an impossible date falls back to today; no member means the caller');
[, $b] = pick($owner, 'source=member&human=1'); $hm = rows_of($b);
[, $b2] = pick($owner, 'source=member'); $am = rows_of($b2);
ok(count($am) > count($hm) && !in_array('SMOKE Clerk (agent)', $hm, true) && count(array_filter($am, static fn ($l) => str_ends_with($l, '(agent)'))) > 0, 'member: human=1 drops the agents, which are otherwise labelled (agent)');

echo "== 5. the endpoint: rows, search, escaping, paging, params\n";
[, $b] = pick($owner, 'source=client');
ok(substr_count($b, 'class="list-group-item list-group-item-action record-picker-row') === 25, 'the first page is 25 rows');
$names = array_values(rows_of($b)); $sorted = $names; usort($sorted, 'strcasecmp'); ok($names === $sorted, 'in name order');
ok(has($b, 'id="record-picker-more"') && has($b, 'page=2') && has($b, "25 of $totalClients shown"), "Show more asks for page 2 and says 25 of $totalClients shown");
[, $b] = pick($owner, 'source=client&page=2'); $p2 = rows_of($b);
ok(count($p2) === min(25, $totalClients - 25) && !has($b, 'record-picker-list'), 'page 2 is rows only, no list wrapper');
[, $b] = pick($owner, 'source=client&page=3'); ok(count(rows_of($b)) === max(0, min(25, $totalClients - 50)), 'page 3 holds the rest');
$pk01 = (int) one("SELECT id FROM clients WHERE name = 'SMOKE Pk Client 01'");
[, $b] = pick($owner, "source=client&selected=$pk01&q=" . urlencode('Pk Client 01'));
ok(has($b, 'record-picker-row active" id="record-picker-row-' . $pk01 . '" role="option" aria-selected="true"'), 'the chosen row is marked active and selected');
[, $b] = pick($owner, 'source=client&q=' . urlencode('pk client 0')); ok(count(rows_of($b)) === 9, 'search narrows by name (case-insensitive)');
[, $b] = pick($owner, 'source=client&q=PKL'); ok(in_array('SMOKE Pk Lonely', rows_of($b), true), 'search matches the client code');
[, $b] = pick($owner, 'source=client&q=' . urlencode('100%')); ok(rows_of($b) === [], 'a percent sign in a search is not a wildcard');
[, $b] = pick($owner, 'source=client&q=' . urlencode('<b>nothing')); ok(has($b, 'No client matches “&lt;b&gt;nothing”') && !has($b, '<b>nothing'), 'no match says so, the words escaped');
[$c] = pick($owner, 'source=client&q=' . str_repeat('a', 200)); ok($c === 200, 'a search longer than 80 characters is cut, not refused');
[, $b] = pick($owner, 'source=engagement&q=' . urlencode('Pk Eng 07')); $e7 = rows_of($b); ok(count($e7) === 1 && has($b, 'SMOKE Pk Client 07'), 'engagement search by name; the detail line carries the client');
[, $b] = pick($owner, 'source=engagement&q=' . urlencode('SMOKE Pk Client 07')); ok(count(rows_of($b)) === 1, 'engagement search by client name');
$number = (string) one('SELECT number FROM engagements WHERE id = :e', ['e' => $acme]);
[, $b] = pick($owner, 'source=engagement&q=' . urlencode($number)); ok(isset(rows_of($b)[$acme]) && has($b, htmlspecialchars($number . ' · ')), 'engagement search by number; the label is "number · name"');
[, $b] = pick($owner, "source=engagement&client_id=$gammaClient"); $dep = rows_of($b); ok(array_map('intval', array_keys($dep)) === [$gamma], 'the dependent param: client_id narrows the engagements to that client\'s');
[, $b] = pick($owner, 'source=engagement&client_id=abc'); ok(count(rows_of($b)) === 25, 'a param that is not a number is ignored');
[, $b] = pick($owner, 'source=engagement&status=cancelled&foo=1'); ok(count(rows_of($b)) === 25, 'an undeclared param changes nothing');
[, $b] = pick($owner, 'source=engagement'); ok($totalEng > 25 && has($b, "25 of $totalEng shown"), 'the engagement total counts every status');
ok(substr_count(pick($owner, 'source=engagement')[1], 'bg-soft-success') === 0, 'an active engagement carries no badge');

echo "== 6. the forms render their pickers under the old names and ids\n";
$form = static function (string $jar, string $path, array $needles, string $label) {
    [$c, $b] = get_($jar, $path);
    $missing = array_filter($needles, static fn (string $n): bool => !has($b, $n));
    ok($c === 200 && $missing === [] && substr_count($b, 'id="record-picker"') === 1 && substr_count($b, 'record-picker.js') === 1, "$label" . ($missing === [] ? '' : ' — missing: ' . implode(' | ', $missing)) . " ($c)");
    return $b;
};
$pickerAttr = static fn (string $name, string $id): string => 'name="' . $name . '" id="' . $id . '"';
$b = $form($marco, '/engagements/new', [$pickerAttr('client', 'engagement-form-field-client'), 'data-picker-source="client_live"', 'id="engagement-form-field-client-open"', 'for="engagement-form-field-client-open"', $pickerAttr('manager', 'engagement-form-field-manager'), 'data-picker-source="member"', 'data-picker-params="{&quot;human&quot;:1}"'], 'engagement form: client_live and manager (humans) pickers');
ok(has($b, 'record-picker-label">SMOKE Marco<'), 'the engagement form pre-chooses the caller as the manager, by label');
$b = $form($marco, "/engagements/$acme/edit", ['record-picker-label">SMOKE Acme<'], 'engagement edit shows the client\'s label');
$b = $form($marco, "/engagements/new?client=$lonely", ['record-picker-label">SMOKE Pk Lonely<'], 'a client prefilled in the URL shows its label');
$form($owner, '/invoices/new', [$pickerAttr('engagement', 'invoice-form-field-engagement'), 'data-picker-source="engagement_draftable"'], 'invoice form: engagement_draftable');
$form($owner, "/invoices/new?engagement=$acme", ['record-picker-label">'], 'invoice form with an engagement prefilled shows it');
$form($owner, '/invoices/', [$pickerAttr('client', 'invoice-filter-client'), $pickerAttr('engagement', 'invoice-filter-engagement'), 'data-picker-source="engagement"'], 'invoice list filters');
$form($owner, '/payments/', [$pickerAttr('client', 'payment-filter-client'), 'data-picker-source="client"'], 'payment list filter');
$form($owner, '/billing/unbilled', [$pickerAttr('client', 'unbilled-filter-client')], 'unbilled filter');
$form($owner, '/engagements/', [$pickerAttr('client', 'engagement-filter-client')], 'engagement list filter');
$form($owner, '/usage/', [$pickerAttr('engagement', 'usage-filter-engagement')], 'usage list filter');
$form($owner, '/credit-notes/new', [$pickerAttr('client', 'credit-note-form-field-client'), 'data-picker-source="client"'], 'credit note form: client');
$form($priya, '/time/entries/new', [$pickerAttr('engagement', 'time-entry-form-field-engagement'), 'data-picker-source="engagement_loggable"', 'data-picker-params="{&quot;member_id&quot;:26,&quot;on&quot;:' . date('Ymd') . '}"'], 'time entry form: loggable, for the member and the day');
$form($priya, '/expenses/new', [$pickerAttr('engagement', 'expense-form-field-engagement'), 'data-picker-source="engagement_loggable"'], 'expense form: loggable');
$form($marco, '/usage/new', [$pickerAttr('engagement', 'usage-form-field-engagement'), 'data-picker-source="engagement_managed"'], 'usage charge form: managed');
$form($marco, '/usage/import', [$pickerAttr('engagement', 'import-field-engagement'), 'data-picker-source="engagement_managed"'], 'usage import form: managed');
$form($marco, '/settings/allocation-rules/new', [$pickerAttr('engagement', 'rule-form-field-engagement')], 'allocation rule form: managed');
$form($marco, '/settings/schedules/new', [$pickerAttr('engagement', 'schedule-form-field-engagement')], 'hosting schedule form: managed');
$form($owner, '/settings/capacity/', [$pickerAttr('member', 'capacity-form-field-member'), 'data-picker-params="{&quot;human&quot;:1}"'], 'capacity form: people');
$form($owner, '/settings/general', [$pickerAttr('morning_note_member', 'settings-form-field-note'), 'data-picker-source="member"'], 'settings: the morning note\'s recipient (people and agents)');
$form($marco, "/engagements/$acme/staffing", [$pickerAttr('member', 'staffing-form-field-member'), 'data-picker-source="member"'], 'staffing form: people and agents');
$form($owner, '/reports/realization', [$pickerAttr('engagement', 'report-f-engagement'), $pickerAttr('client', 'report-f-client'), 'for="report-f-client-open"'], 'report filters: engagement and client');
$form($owner, '/reports/utilization', [$pickerAttr('member', 'report-f-member'), 'data-picker-params="{&quot;human&quot;:1}"'], 'report filters: person (humans)');
$form($owner, '/reports/budget-burn', [$pickerAttr('manager', 'report-f-manager')], 'report filters: manager');
$form($owner, '/reports/revenue', [], 'a report without record filters still renders');
[, $b] = get_($owner, '/reports/realization?client=' . $lonely); ok(has($b, 'record-picker-label">SMOKE Pk Lonely<'), 'a report filter in the URL shows the chosen record\'s label');
[, $b] = get_($priya, '/time/'); ok(has($b, $pickerAttr('engagement', 'timer-bar-field-engagement')) && has($b, 'class="record-picker-label"'), 'the timer bar offers a picker already on the first engagement (the select preselected it)');
$ms = (int) (one("SELECT id FROM milestones WHERE name = 'SMOKE Pk milestone'") ?: one("INSERT INTO milestones (engagement_id, name, amount) VALUES (:e, 'SMOKE Pk milestone', 100) RETURNING id", ['e' => $beta]));
q("UPDATE milestones SET status = 'delivered', delivered_at = coalesce(delivered_at, now()) WHERE id = :m AND status = 'planned'", ['m' => $ms]);
$form($marco, "/engagements/$beta/milestones?accept=$ms", [$pickerAttr('accepted_by', 'milestone-accept-field-by'), 'data-picker-source="member"', 'record-picker-label">SMOKE Marco<'], 'milestone acceptance: who recorded it (the caller, by label)');
$form($owner, '/settings/cost-rates/', [$pickerAttr('member', 'cost-rate-form-field-member')], 'cost rate form: people');

echo "== 7. a refusal re-render keeps the label; a write goes through the hidden input\n";
$csrf = csrf_of(req('GET', '/', ['jar' => $marco])['body']);
$prov = (int) one('SELECT id FROM usage_providers ORDER BY id LIMIT 1');
$r = req('POST', '/usage/import', ['jar' => $marco, 'form' => ['csrf_token' => $csrf, 'provider' => $prov, 'engagement' => $acme]]);
ok($r['code'] === 422 && has($r['body'], 'record-picker-label">' . (string) one('SELECT number FROM engagements WHERE id = :e', ['e' => $acme]) . ' · SMOKE Time Acme<'), "a 422 on the import form keeps the chosen engagement's label ({$r['code']})");
$r = req('POST', '/usage/import', ['jar' => $marco, 'form' => ['csrf_token' => $csrf, 'provider' => $prov, 'engagement' => 999999]]);
ok($r['code'] === 422 && has($r['body'], 'record-picker-label text-muted">Choose an engagement<'), 'an engagement id that names nothing shows the placeholder, not an error page');
$mon = monday(0); $d0 = $mon;
$id0 = (int) one('SELECT coalesce(max(id), 0) FROM time_entries');
[$c, $body] = act($priya, '/time/entries/save.php', ['engagement' => $acme, 'date' => date('Y-m-d'), 'hours' => '1.50']);
$newId = (int) one('SELECT coalesce(max(id), 0) FROM time_entries');
ok($c === 200 && $newId > $id0 && (int) one('SELECT engagement_id FROM time_entries WHERE id = :i', ['i' => $newId]) === $acme, "a time entry saves through the hidden input's name ('engagement') as it did through the select ($c)");
q('DELETE FROM engagement_members WHERE engagement_id = :e AND member_id = 28', ['e' => $acme]);          // repeatable
[$c, $body] = act($marco, '/engagements/staffing/save.php', ['engagement' => $acme, 'member' => 28, 'role' => role('analyst'), 'valid_from' => '2026-01-05']);
ok($c === 200 && (int) one('SELECT count(*) FROM engagement_members WHERE engagement_id = :e AND member_id = 28', ['e' => $acme]) === 1, "staffing saves through the member field ($c)");
$ed = req('GET', "/engagements/$acme/staffing?staffing=" . (int) one('SELECT id FROM engagement_members WHERE engagement_id = :e AND member_id = 28', ['e' => $acme]), ['jar' => $marco]);
ok(has($ed['body'], 'id="staffing-form-field-member" value="28" data-picker-value disabled') && has($ed['body'], 'record-picker-label">SMOKE Bea<'), 'editing a staffing: the person is shown and locked (the hidden input is disabled, as the select was)');

finish();
