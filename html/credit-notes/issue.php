<?php
declare(strict_types=1);
/** Action `credit_note_issue` (log `credit_note.issue`: number, amount, currency, invoice_id, reason; confirm; `money_out` for agents): billing.write. The verb numbers it gaplessly (CN-00001…) and refuses a note for nothing, in words. */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$cn = credit_note_from_request($pdo);
$row = ct_guard($pdo, static function () use ($pdo, $me, $cn): array {
    $pdo->beginTransaction();
    $row = issue_credit_note($pdo, $cn['credit_note_id'], $me);
    credit_log($pdo, 'credit_note.issue', $cn, ['before' => ['status' => 'draft'], 'after' => ['status' => 'issued', 'number' => $row['number'], 'amount' => $row['total'], 'currency' => trim((string) $row['currency']), 'total' => $row['total'], 'invoice_id' => $cn['invoice_id'], 'reason' => $cn['reason']]]);
    $pdo->commit();
    return $row;
});
ct_done('Issued ' . $row['number'] . ' (' . fmt_money($row['total'], trim((string) $row['currency'])) . ')', $cn['credit_note_id'], ct_land(return_path('/credit-notes/' . $cn['credit_note_id']), 'issued'), 'creditNoteChanged',
    ['credit_note_id' => $cn['credit_note_id'], 'status' => 'issued', 'number' => $row['number'], 'total' => $row['total']]);
