<?php
declare(strict_types=1);
/** Action `credit_note_delete` (log `credit_note.delete`: amount, currency; confirm; `deletion` for agents): billing.write; a draft only (no number was burned). Its lines go with it. */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$cn = credit_note_from_request($pdo);
$was = ct_guard($pdo, static function () use ($pdo, $me, $cn): array {
    $pdo->beginTransaction();
    $was = delete_credit_note($pdo, $cn['credit_note_id'], $me);
    credit_log($pdo, 'credit_note.delete', $cn, ['before' => ['status' => 'draft', 'amount' => $was['total'], 'currency' => $was['currency'], 'total' => $was['total']]]);
    $pdo->commit();
    return $was;
});
ct_done('Deleted the draft credit note', $cn['credit_note_id'], ct_land(return_path('/credit-notes/'), 'deleted'), 'creditNoteChanged', ['credit_note_id' => $cn['credit_note_id'], 'deleted' => true]);
