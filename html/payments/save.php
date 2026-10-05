<?php
declare(strict_types=1);
/**
 * Action `payment_record` (log `payment.record`: invoice_number, amount, currency, method, reference, source; confirm; `money_out` for agents): billing.write. Money IN — a record of status (D10): the database derives partially paid / paid
 * (`paid_at`), refuses an overpayment and a payment in another currency, in words. Nothing in this application pays anyone. A paid invoice tells billing and the engagement's manager (`invoice_paid`).
 */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$inv = invoice_from_request($pdo);
$errors = [];
$amount = req_money('amount', 'The amount', $errors, true, true);
$paidOn = req_date('paid_on', 'The date paid', $errors) ?? (req_has('date') ? req_date('date', 'The date paid', $errors) : null);
$method = req_has('method') ? (string) req_val('method') : 'transfer';
if (!isset(PAYMENT_METHODS[$method])) {
    $errors['method'] = 'Choose how it was paid: transfer, card, cheque, cash or other.';
}
$ref = req_has('reference') ? (string) req_val('reference') : '';
if (mb_strlen($ref) > 200) {
    $errors['reference'] = 'The reference is at most 200 characters.';
}
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$out = ct_guard($pdo, static function () use ($pdo, $me, $inv, $amount, $paidOn, $method, $ref): array {
    $pdo->beginTransaction();
    $pid = record_payment($pdo, $inv['invoice_id'], ['amount' => $amount, 'paid_on' => $paidOn, 'method' => $method, 'reference' => $ref === '' ? null : $ref], $me, 'manual');
    $after = find_invoice($pdo, $inv['invoice_id']);
    invoice_log($pdo, 'payment.record', $after, ['after' => ['payment_id' => $pid, 'invoice_number' => $inv['number'], 'amount' => $amount, 'currency' => $inv['currency'], 'method' => $method, 'reference' => $ref === '' ? null : $ref, 'source' => 'manual',
        'invoice_status' => $after['status'], 'balance_due' => $after['balance_due']]], 'payment', $pid);
    if ($after['status'] === 'paid' && $inv['status'] !== 'paid') {
        notify_paid($pdo, $after, $me);
    }
    $pdo->commit();
    return [$pid, $after];
});
[$pid, $after] = $out;
ct_done('Recorded ' . fmt_money($amount, $inv['currency']) . ' against ' . $inv['number'] . ($after['status'] === 'paid' ? '; it is paid' : ''), $pid, ct_land(return_path('/invoices/' . $inv['invoice_id']), 'payment'), 'invoiceChanged',
    ['invoice_id' => $inv['invoice_id'], 'payment_id' => $pid, 'status' => $after['status'], 'balance_due' => $after['balance_due']]);
