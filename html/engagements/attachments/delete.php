<?php
declare(strict_types=1);
/** Action `attachment_delete` (log `attachment.delete`; confirm; `deletion` for agents): the record's manager (its writer rule, plus the uploader). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/documents/write.php';
ct_handler_begin();
require_login();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('attachment') ?? request_integer('attachment_id') ?? refuse(422, 'Say which file.');
$a = attachment_path($pdo, $id) ?? refuse(404, 'File not found.');
$uploader = (int) one_value($pdo, 'SELECT uploaded_by FROM attachments WHERE id = :id', ['id' => $id]);
if (!record_writable($pdo, $a['record_type'], $a['record_id']) && $uploader !== $me) {
    refuse(403, 'You may not remove this file.');
}
$was = ct_guard($pdo, static function () use ($pdo, $id, $a): array {
    $pdo->beginTransaction();
    $was = delete_attachment($pdo, $id);
    log_activity($pdo, 'attachment.delete', 'attachment', $id, ['before' => $was] + record_log_keys($pdo, $a['record_type'], $a['record_id']));
    $pdo->commit();
    return $was;
});
ct_done('Removed ' . $was['filename'], $id, ct_land(return_path(record_url($a['record_type'], $a['record_id'])), 'file_removed', 'documents'), str_replace('_', '', $a['record_type']) . 'Changed');
