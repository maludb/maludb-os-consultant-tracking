<?php
declare(strict_types=1);
/** Action `credit_note_void` (log `credit_note.void`: number, amount, currency, reason; confirm; `money_out` for agents): billing.write; an issued credit applied to nothing (the guard's words otherwise). A reason is required. */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$cn = credit_note_from_request($pdo);
$reason = (string) req_val('reason');
if ($reason === '' || mb_strlen($reason) > 1000) {
    ct_refuse_fields(['reason' => $reason === '' ? 'Say why it is voided.' : 'The reason is at most 1,000 characters.']);
}
$was = ct_guard($pdo, static function () use ($pdo, $me, $cn, $reason): array {
    $pdo->beginTransaction();
    $was = void_credit_note($pdo, $cn['credit_note_id'], $me, $reason);
    credit_log($pdo, 'credit_note.void', $cn, ['before' => ['status' => 'issued'], 'after' => ['status' => 'void', 'number' => $was['number'], 'amount' => $was['total'], 'currency' => $was['currency'], 'reason' => $reason]]);
    $pdo->commit();
    return $was;
});
ct_done('Voided ' . $was['number'], $cn['credit_note_id'], ct_land(return_path('/credit-notes/' . $cn['credit_note_id']), 'voided'), 'creditNoteChanged', ['credit_note_id' => $cn['credit_note_id'], 'status' => 'void']);
