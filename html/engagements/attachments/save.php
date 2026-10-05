<?php
declare(strict_types=1);
/** Action `attachment_add` (log `attachment.add`: filename, byte_size, mime_type, client_visible; the record's keys): the record's writer. record_type + record + file (multipart) + client_visible. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/documents/write.php';
ct_handler_begin();
require_right('attachments.write');
$pdo = db();
$me = (int) current_member_id();
$type = (string) req_val('record_type');
$recordId = request_integer('record') ?? request_integer('record_id') ?? refuse(422, 'Say which record (record_type and record).');
if (!in_array($type, ATTACHMENT_RECORD_TYPES, true)) {
    ct_refuse_fields(['record_type' => 'The record type is one of: ' . implode(', ', ATTACHMENT_RECORD_TYPES) . '.']);
}
if (!record_visible($pdo, $type, $recordId)) {
    refuse(404, ucfirst(str_replace('_', ' ', $type)) . ' not found.');
}
if (!record_writable($pdo, $type, $recordId)) {
    refuse(403, 'You may not attach files to this ' . str_replace('_', ' ', $type) . '.');
}
$file = $_FILES['file'] ?? null;
if (!is_array($file)) {
    ct_refuse_fields(['file' => 'Choose a file.']);
}
$visible = ct_yes('client_visible', false);
$id = ct_guard($pdo, static function () use ($pdo, $me, $type, $recordId, $file, $visible): int {
    $pdo->beginTransaction();
    $id = store_attachment($pdo, $type, $recordId, $file, $me, $visible);
    $row = $pdo->prepare('SELECT filename, byte_size, mime_type FROM attachments WHERE id = :id');
    $row->execute(['id' => $id]);
    $f = $row->fetch();
    log_activity($pdo, 'attachment.add', 'attachment', $id, ['after' => ['record_type' => $type, 'record_id' => $recordId, 'filename' => $f['filename'], 'byte_size' => (int) $f['byte_size'], 'mime_type' => $f['mime_type'], 'client_visible' => $visible]] + record_log_keys($pdo, $type, $recordId));
    $pdo->commit();
    return $id;
});
ct_done('Attached ' . basename((string) $file['name']), $id, ct_land(return_path(record_url($type, $recordId, $pdo)), 'attached', 'documents'), str_replace('_', '', $type) . 'Changed', ['attachment_id' => $id, 'url' => '/attachments/' . $id]);
