<?php
/**
 * Proof — JSON mode and the registry (spec "Proof"): the two actions of this slice and the two screens answer JSON under a signed action token as every handler does; the registry reads every screen and action of the manifest as built but slice 9's two screens
 * (agents, connections); the pauses are the kernel's hook and neither action is among them (the Clerk may send the note, any exporter may download); the notifications screen lists and marks read.
 */
require __DIR__ . '/lib.php';
$w = report_world(); exports_world();
$bea = as_member(28); $marco = as_member(27); $priya = as_member(26); $ann = as_member(29);
$bTok = ['X-Action-Token: ' . person_token(28)]; $mTok = ['X-Action-Token: ' . person_token(27)];
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$mani = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true);
act($bea, '/billing/morning-note.php', ['body' => 'SMOKE json bell note', 'to_member' => 28]);       // something in Bea\'s bell

echo "1. The registry\n";
$screens = $reg['screens']; $actions = $reg['actions'];
$unbuilt = array_keys(array_filter($screens, fn ($s) => empty($s['built'])));
ok(count($actions) === 108 && count(array_filter($actions, fn ($a) => !empty($a['built']))) === 108, 'the registry: 108 actions, every one built');
ok(count($screens) === 78 && $unbuilt === ['agents', 'connections'], 'the registry: 78 screens, built but slice 9\'s two (' . implode(', ', $unbuilt) . ')');
$ex = $actions['export_download']; $mn = $actions['morning_note_send'];
ok($ex['endpoint'] === '/exports/download.php' && array_column($ex['params'], 'name') === ['export', 'format', 'from', 'to'] && $ex['params'][0]['required'] === true && $ex['log_event'] === 'export.download' && $ex['approval'] === null, 'export_download: /exports/download.php, params export, format (required), from, to; logs export.download; no approval');
ok($mn['endpoint'] === '/billing/morning-note.php' && array_column($mn['params'], 'name') === ['body', 'to_member'] && $mn['params'][0]['required'] === true && $mn['log_event'] === 'morning_note.send' && $mn['approval'] === null, 'morning_note_send: /billing/morning-note.php, params body (required), to_member; logs morning_note.send; no approval');
$cats = array_column($mani['approvals'], 'category', 'action');
ok(!isset($cats['export_download']) && !isset($cats['morning_note_send']), 'neither action is in the approvals of maludb-os.json: the Clerk\'s note and an agent\'s download pause for no one');
ok(in_array('morning_note_send', $mani['agents'][1]['tool_grants']['Actions MCP'], true) && !in_array('morning_note_send', $mani['agents'][0]['tool_grants']['Actions MCP'], true), 'the Clerk is granted morning_note_send; the expert is not');

echo "2. The pages answer JSON\n";
[$c, $d] = screen($bea, '/exports');
ok($c === 200 && array_keys($d) === ['period', 'as_of', 'exports'] && count($d['exports']) === 7, '/exports: {period, as_of, exports[7]}');
[$c, $d] = screen($bea, '/notifications');
ok($c === 200 && isset($d['notifications'], $d['unread_only']) && array_keys($d['notifications'][0]) === ['notification_id', 'kind', 'record_type', 'record_id', 'title', 'body', 'read', 'created_at'], '/notifications: the list with the whitelisted columns (notification_id, kind, record_type, record_id, title, body, read, created_at)');

echo "3. Under a person's action token\n";
$since = last_activity_id();
[$c, $b] = act_token('/exports/download.php', ['export' => 'credit_notes', 'format' => 'json'] + whole(), $bTok);
ok($c === 200 && $b['ok'] === true && $b['export'] === 'credit_notes' && $b['rows'] >= 1 && $b['document']['schema'] === 'os.ct-credit-notes/1' && count(activity('export.download', $since)) === 1 && activity('export.download', $since)[0]['source'] === 'assistant', 'export_download under Bea\'s token: {ok, export, rows, document}, logged as `assistant`');
[$c, $b] = act_token('/exports/download.php', ['export' => 'credit_notes', 'format' => 'json'], $mTok);
ok($c === 403 && ($b['error']['code'] ?? '') === 'forbidden' && msg($b) === 'You may not download exports.', 'Marco\'s token: 403 {error: {code: forbidden}} in words');
[$c, $b] = act_token('/exports/download.php', ['export' => 'credit_notes', 'format' => 'pdf'], $bTok);
ok($c === 422 && msg($b) === 'The format is csv or json.', 'a format that does not exist: 422 in words');
$since = last_activity_id();
[$c, $b] = act_token('/billing/morning-note.php', ['body' => 'SMOKE json note', 'to_member' => 28], $bTok);
ok($c === 200 && $b['ok'] === true && $b['record_id'] > 0 && count(activity('morning_note.send', $since)) === 1 && activity('morning_note.send', $since)[0]['source'] === 'assistant', 'morning_note_send under Bea\'s token: {ok, did, record_id}, logged as `assistant`');
[$c, $b] = act_token('/billing/morning-note.php', ['body' => 'SMOKE json note'], $mTok);
ok($c === 403 && ($b['error']['code'] ?? '') === 'forbidden', 'Marco\'s token: 403');

echo "4. The bell\n";
$unread = (int) one("SELECT count(*) FROM notifications WHERE member_id = 28 AND read_at IS NULL");
[$c, $d] = screen($bea, '/notifications?unread=1');
ok($c === 200 && $d['unread_only'] === true && count($d['notifications']) === min(50, $unread) && array_unique(array_column($d['notifications'], 'read')) === [false], "?unread=1: only the unread ($unread)");
$first = $d['notifications'][0]['notification_id'];
[$c, $b] = act($bea, '/settings/notifications/read.php', ['notification' => $first]);
ok($c === 200 && one('SELECT read_at IS NOT NULL FROM notifications WHERE id = :i', ['i' => $first]) === true, 'marking one read: read_at stamped');
[$c, $b] = act($bea, '/settings/notifications/read.php', []);
ok($c === 200 && (int) one("SELECT count(*) FROM notifications WHERE member_id = 28 AND read_at IS NULL") === 0 && (int) one("SELECT count(*) FROM notifications WHERE member_id = 27 AND read_at IS NULL") > 0, 'marking all read touches only Bea\'s own');
$r = page($bea, '/notifications');
ok($r['code'] === 200 && str_contains($r['body'], 'id="notifications-content"') && str_contains($r['body'], 'The morning note'), 'the page lists the morning note');
finish();
