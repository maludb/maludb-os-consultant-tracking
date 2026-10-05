<?php
declare(strict_types=1);
/** Action `payment_claim_dismiss` (log `payment_claim.dismiss`: invoice_number, reason; `other` for agents): billing.write. A waiting claim is dismissed — a decision too; no money moves. The reason (optional) goes in the log. */
require_once dirname(__DIR__, 3) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$k = find_claim($pdo, claim_id_from_request() ?? refuse(422, 'Say which claim.')) ?? refuse(404, 'Claim not found.');
$inv = find_invoice($pdo, $k['invoice_id']) ?? refuse(404, 'Invoice not found.');
$reason = req_has('reason') ? (string) req_val('reason') : '';
if (mb_strlen($reason) > 1000) {
    ct_refuse_fields(['reason' => 'The reason is at most 1,000 characters.']);
}
ct_guard($pdo, static function () use ($pdo, $me, $k, $inv, $reason): void {
    $pdo->beginTransaction();
    dismiss_claim($pdo, $k['claim_id'], $me, $reason === '' ? null : $reason);
    invoice_log($pdo, 'payment_claim.dismiss', $inv, ['before' => ['status' => 'submitted'], 'after' => ['status' => 'dismissed', 'claim_id' => $k['claim_id'], 'invoice_number' => $inv['number'], 'amount' => $k['amount'], 'currency' => $inv['currency'], 'reason' => $reason === '' ? null : $reason]],
        'payment_claim', $k['claim_id']);
    $pdo->commit();
});
ct_done('Dismissed the claim on ' . $inv['number'], $k['claim_id'], ct_land(return_path('/billing/claims'), 'dismissed'), 'invoiceChanged', ['claim_id' => $k['claim_id'], 'invoice_id' => $inv['invoice_id'], 'status' => 'dismissed']);
