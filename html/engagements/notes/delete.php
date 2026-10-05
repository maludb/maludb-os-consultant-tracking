<?php
declare(strict_types=1);
/** Action `note_delete` (log `note.delete`; confirm; `deletion` for agents): the note's author or the admin. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/documents/write.php';
ct_handler_begin();
require_login();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('note') ?? request_integer('note_id') ?? refuse(422, 'Say which note.');
$n = $pdo->prepare('SELECT record_type, record_id FROM mcp_notes WHERE note_id = :id');
$n->execute(['id' => $id]);
$n = $n->fetch() ?: refuse(404, 'Note not found.');
$was = ct_guard($pdo, static function () use ($pdo, $id, $me): array {
    $pdo->beginTransaction();
    $was = delete_note($pdo, $id, $me, is_ct_admin());
    log_activity($pdo, 'note.delete', 'note', $id, ['before' => $was] + record_log_keys($pdo, $was['record_type'], $was['record_id']));
    $pdo->commit();
    return $was;
});
ct_done('Removed the note', $id, ct_land(return_path(record_url($n['record_type'], (int) $n['record_id'])), 'note_removed', 'notes'), str_replace('_', '', $n['record_type']) . 'Changed');
