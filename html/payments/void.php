<?php
declare(strict_types=1);
/** Action `payment_void` (log `payment.void`: invoice_number, amount, currency, reason; confirm; `money_out` for agents): billing.write. A recorded payment is voided with a reason and the invoice reopens (the database derives partially paid or open again). A payment is never deleted. */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$p = find_payment($pdo, payment_id_from_request() ?? refuse(422, 'Say which payment.')) ?? refuse(404, 'Payment not found.');
$inv = find_invoice($pdo, $p['invoice_id']) ?? refuse(404, 'Invoice not found.');
$reason = (string) req_val('reason');
if ($reason === '' || mb_strlen($reason) > 1000) {
    ct_refuse_fields(['reason' => $reason === '' ? 'Say why the payment is voided.' : 'The reason is at most 1,000 characters.']);
}
$after = ct_guard($pdo, static function () use ($pdo, $me, $p, $inv, $reason): array {
    $pdo->beginTransaction();
    $was = void_payment($pdo, $p['payment_id'], $me, $reason);
    $after = find_invoice($pdo, $inv['invoice_id']);
    invoice_log($pdo, 'payment.void', $after, ['before' => ['status' => $inv['status']], 'after' => ['payment_id' => $p['payment_id'], 'invoice_number' => $inv['number'], 'amount' => $was['amount'], 'currency' => $was['currency'], 'reason' => $reason, 'invoice_status' => $after['status'],
        'balance_due' => $after['balance_due']]], 'payment', $p['payment_id']);
    $pdo->commit();
    return $after;
});
ct_done('Voided the payment of ' . fmt_money($p['amount'], $p['currency']) . '; ' . $inv['number'] . ' is ' . strtolower(INVOICE_STATUSES[$after['status']]) . ' again', $p['payment_id'], ct_land(return_path('/invoices/' . $inv['invoice_id']), 'payment_voided'), 'invoiceChanged',
    ['invoice_id' => $inv['invoice_id'], 'payment_id' => $p['payment_id'], 'status' => $after['status'], 'balance_due' => $after['balance_due']]);
