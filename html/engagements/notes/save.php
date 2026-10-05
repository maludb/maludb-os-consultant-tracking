<?php
declare(strict_types=1);
/** Action `note_add` (log `note.add`: an excerpt; the record's keys): the record's viewer. record_type + record + body. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/documents/write.php';
ct_handler_begin();
require_login();
$pdo = db();
$me = (int) current_member_id();
$type = (string) req_val('record_type');
$recordId = request_integer('record') ?? request_integer('record_id') ?? refuse(422, 'Say which record (record_type and record).');
if (!in_array($type, NOTE_RECORD_TYPES, true)) {
    ct_refuse_fields(['record_type' => 'The record type is one of: ' . implode(', ', NOTE_RECORD_TYPES) . '.']);
}
if (!record_visible($pdo, $type, $recordId)) {
    refuse(404, ucfirst(str_replace('_', ' ', $type)) . ' not found.');
}
$body = trim((string) req_val('body'));
if ($body === '' || mb_strlen($body) > 8000) {
    ct_refuse_fields(['body' => 'Write the note (up to 8,000 characters).']);
}
$id = ct_guard($pdo, static function () use ($pdo, $me, $type, $recordId, $body): int {
    $pdo->beginTransaction();
    $id = add_note($pdo, $type, $recordId, $body, $me);
    log_activity($pdo, 'note.add', 'note', $id, ['after' => ['record_type' => $type, 'record_id' => $recordId, 'excerpt' => mb_substr($body, 0, 120)]] + record_log_keys($pdo, $type, $recordId));
    $pdo->commit();
    return $id;
});
ct_done('Noted on the ' . str_replace('_', ' ', $type), $id, ct_land(return_path(record_url($type, $recordId, $pdo)), 'noted', 'notes'), str_replace('_', '', $type) . 'Changed', ['note_id' => $id]);
