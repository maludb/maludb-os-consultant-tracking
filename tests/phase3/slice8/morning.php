<?php
/**
 * Proof — the morning note (spec "Proof", 11): `morning_note_send` leaves the Clerk's (or Billing's) note in the bell and the outbox of the person the settings name — or the one named — and Billing's home shows the latest in its Clerk region; the Clerk's run token may send it
 * (it is its duty's last step; nothing pauses it here — the pauses are the kernel's hook); a Manager or a Consultant may not; the log carries the recipient and the LENGTH, never the words (they carry money).
 */
require __DIR__ . '/lib.php';
$w = report_world();
$bea = as_member(28); $marco = as_member(27); $priya = as_member(26); $owner = as_member(1);
q('DELETE FROM notification_prefs WHERE member_id IN (27, 28)');
q("DELETE FROM notifications WHERE kind = 'morning_note'");
q('UPDATE ct_settings SET morning_note_member_id = NULL WHERE id = 1');
drain_outbox(); reset_fakes();
$note = "Good morning. Drafted INV draft for ENG-0001: 620.00 USD (time 540.00, expense 20.00, usage 60.00).\nAllocated 2026-08: 4 charges, remainder 50.01 USD.\nOverdue: INV-00003 (1,000.00 USD, 7 days).";

echo "1. Before any note\n";
[$c, $d] = screen($bea, '/');
ok($c === 200 && $d['clerk_note'] === null, 'Billing\'s home (JSON): clerk_note is null');
$r = page($bea, '/');
ok(str_contains($r['body'], 'id="home-clerk-note"') && str_contains($r['body'], 'id="home-clerk-note-empty"') && str_contains($r['body'], 'None yet') && !str_contains($r['body'], 'slice 8'), 'the home\'s region says there is none yet (an empty state, no "slice 8" placeholder)');

echo "2. Who may; what must be said\n";
foreach ([['Priya', $priya], ['Marco', $marco]] as [$n, $j]) {
    [$c, $b] = act($j, '/billing/morning-note.php', ['body' => $note]);
    ok($c === 403 && msg($b) === 'You may not issue, send and settle invoices.', "$n: 403 (the note is Billing's)");
}
[$c, $b] = act($bea, '/billing/morning-note.php', ['body' => $note]);
ok($c === 422 && isset(fields($b)['to_member']) && str_contains(fields($b)['to_member'], 'No one receives the note') && (int) one("SELECT count(*) FROM notifications WHERE kind = 'morning_note'") === 0, 'no recipient in the settings and none named: 422 on to_member, in words; nothing written');
q('UPDATE ct_settings SET morning_note_member_id = 28 WHERE id = 1');
[$c, $b] = act($bea, '/billing/morning-note.php', ['body' => '  ']);
ok($c === 422 && fields($b)['body'] === 'The note is required.', 'an empty note: 422 "The note is required."');
[$c, $b] = act($bea, '/billing/morning-note.php', ['body' => str_repeat('x', 8001)]);
ok($c === 422 && fields($b)['body'] === 'The note is at most 8,000 characters.', 'a note over 8,000 characters: refused in words');
[$c, $b] = act($bea, '/billing/morning-note.php', ['body' => $note, 'to_member' => 9999]);
ok($c === 422 && isset(fields($b)['to_member']), 'a person who is not here: 422 on to_member');

echo "3. The Clerk's run token\n";
[$tok, $relay] = run_token(40, 884);
kernel_state(function ($s) { $s['facts']['884'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 884, 'request_id' => 'req-884', 'trigger' => 'duty', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$since = last_activity_id();
[$c, $b] = act_token('/billing/morning-note.php', ['body' => $note], ['X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay]);
$nid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $b['ok'] === true && $nid > 0 && $b['notification_id'] === $nid && $b['refresh'] === 'notificationChanged' && $b['did'] === 'Left the morning note', 'the Clerk (an agent, run 884) sends it: 200 {ok, did, record_id = the notification, notification_id, refresh}');
$n = q('SELECT * FROM notifications WHERE id = :i', ['i' => $nid])[0] ?? [];
ok($n['kind'] === 'morning_note' && (int) $n['member_id'] === 28 && str_starts_with($n['title'], 'The morning note, ') && $n['body'] === $note && $n['record_type'] === null && $n['read_at'] === null, 'the notification: kind morning_note, for Bea (the settings\' recipient), the whole note as the body, unread');
$log = activity('morning_note.send', $since);
$a = json_decode((string) $log[0]['after'], true); ksort($a);
ok(count($log) === 1 && $log[0]['source'] === 'agent' && (int) $log[0]['agent_run_id'] === 884 && (int) $log[0]['actor_member_id'] === 40 && (int) $log[0]['entity_id'] === $nid && $a === ['length' => mb_strlen($note), 'to_member_id' => 28] && !str_contains(json_encode($log), 'ENG-0001') && !str_contains(json_encode($log), '620.00'), 'morning_note.send logged: source agent, run 884, the Clerk, the recipient and the LENGTH — none of the words');
kernel_state(function ($s) { unset($s['facts']); return $s; });

echo "4. The bell, the outbox, the home\n";
$out = outbox_of('morning_note', 28);
ok(count($out) === 1 && $out[0]['channel'] === 'email' && $out[0]['subject'] === $n['title'] && $out[0]['body'] === $note && $out[0]['status'] === 'queued', 'one outbox row for Bea: email (her default kinds include morning_note), the title and the whole note');
[$c, $nl] = screen($bea, '/notifications');
ok($c === 200 && $nl['notifications'][0]['kind'] === 'morning_note' && $nl['notifications'][0]['read'] === false && $nl['notifications'][0]['body'] === $note, 'the bell lists it first, unread');
[$c, $d] = screen($bea, '/');
ok($d['clerk_note']['notification_id'] === $nid && $d['clerk_note']['body'] === $note && $d['clerk_note']['for'] === 'SMOKE Bea' && $d['clerk_note']['title'] === $n['title'] && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $d['clerk_note']['created_at']) === 1, 'Billing\'s home (JSON): clerk_note — the notification, the note, who it was left for, when');
$r = page($bea, '/');
ok(str_contains($r['body'], 'id="home-clerk-note-title"') && str_contains($r['body'], 'Overdue: INV-00003 (1,000.00 USD, 7 days).') && str_contains($r['body'], 'Left for SMOKE Bea') && !str_contains($r['body'], 'id="home-clerk-note-empty"'), 'the home\'s region shows the note (title, every line, "Left for SMOKE Bea")');
[$c, $d] = screen($owner, '/');
ok($d['clerk_note']['notification_id'] === $nid, 'the owner (a super-admin) sees it too');
[$c, $d] = screen($marco, '/');
ok($d['clerk_note'] === null && !str_contains(page($marco, '/')['body'], 'home-clerk-note'), 'Marco\'s home (a Manager) has no Clerk region: a note carries billing figures');
[$c, $d] = screen($priya, '/');
ok($d['clerk_note'] === null, 'nor has Priya\'s');
set_sms(['mode' => 'ok']);
worker('send');
$mail = array_values(array_filter(mail_log(), fn ($m) => str_starts_with($m['subject'], 'The morning note')));
ok(count($mail) === 1 && $mail[0]['to'] === 'bea@example.invalid' && str_contains($mail[0]['text'], 'Allocated 2026-08: 4 charges') && str_contains($mail[0]['html'], '<p>'), 'the sender mails it to Bea (her address from the mirror), the whole note');

echo "5. Named recipient, texts, the latest wins\n";
q("INSERT INTO notification_prefs (member_id, email_enabled, text_enabled, kinds, text_kinds) VALUES (27, true, true, '{morning_note}', '{morning_note}')");
[$c, $b] = act($bea, '/billing/morning-note.php', ['body' => 'SMOKE short note for Marco: nothing overdue.', 'to_member' => 27]);
$n2 = (int) $b['record_id'];
ok($c === 200 && (int) one('SELECT member_id FROM notifications WHERE id = :i', ['i' => $n2]) === 27 && array_column(outbox_of('morning_note', 27), 'channel') === ['email', 'text'], 'to_member names Marco: his bell, an email and — he chose texts for it — a text row');
worker('send');
$sms = sms_log();
ok(count($sms) === 1 && $sms[0]['member_id'] === 27 && str_contains($sms[0]['text'], 'The morning note, ') && str_contains($sms[0]['text'], 'nothing overdue'), 'the text goes through the kernel to member 27');
[$c, $d] = screen($bea, '/');
ok($d['clerk_note']['notification_id'] === $n2 && $d['clerk_note']['for'] === 'SMOKE Marco', 'the latest note is the one Billing\'s home shows ("left for SMOKE Marco")');
$long = str_repeat('Overdue line. ', 60);
[$c, $b] = act($bea, '/billing/morning-note.php', ['body' => $long]);
$tx = q("SELECT body FROM notification_outbox WHERE kind = 'morning_note' AND member_id = 28 ORDER BY id DESC LIMIT 1")[0];
ok($c === 200 && mb_strlen($tx['body']) === mb_strlen(trim($long)) && (int) one("SELECT count(*) FROM notification_outbox WHERE kind = 'morning_note' AND channel = 'text' AND member_id = 28") === 0, 'a long note travels whole by email (and Bea chose no text, so none)');
q('DELETE FROM notification_prefs WHERE member_id = 27');
finish();
