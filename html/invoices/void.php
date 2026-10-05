<?php
declare(strict_types=1);
/** Action `invoice_void` (log `invoice.void`: number, amount, currency, reason; confirm; `money_out` for agents): billing.write; nothing paid or credited (the verb's words). The items are released — unbilled again —, milestones return to accepted, the lines stay as text. A reason is required. */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$inv = invoice_from_request($pdo);
$reason = (string) req_val('reason');
if ($reason === '' || mb_strlen($reason) > 1000) {
    ct_refuse_fields(['reason' => $reason === '' ? 'Say why it is voided.' : 'The reason is at most 1,000 characters.']);
}
$row = ct_guard($pdo, static function () use ($pdo, $me, $inv, $reason): array {
    $pdo->beginTransaction();
    $row = void_invoice($pdo, $inv['invoice_id'], $me, $reason);
    invoice_log($pdo, 'invoice.void', $inv, ['before' => ['status' => $inv['status']], 'after' => ['status' => 'void', 'number' => $inv['number'], 'amount' => $inv['total'], 'currency' => $inv['currency'], 'reason' => $reason, 'items_released' => array_sum(array_column($inv['lines'], 'item_count'))]]);
    $pdo->commit();
    return $row;
});
ct_done('Voided ' . invoice_label($inv) . '; its items are unbilled again', $inv['invoice_id'], ct_land(return_path('/invoices/' . $inv['invoice_id']), 'voided'), 'invoiceChanged', ['invoice_id' => $inv['invoice_id'], 'status' => 'void']);
