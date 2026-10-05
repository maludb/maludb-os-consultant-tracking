<?php
declare(strict_types=1);
/** Action `credit_note_apply` (log `credit_note.apply`: number, amount, currency, invoice_id, invoice_number; confirm; `money_out` for agents): billing.write; an issued credit and an open invoice of the same client and currency. Never more than the credit holds or the invoice owes (the guard's words). Applying again to the same invoice sets that amount. */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$cn = credit_note_from_request($pdo);
$inv = find_invoice($pdo, request_integer('invoice') ?? request_integer('invoice_id') ?? $cn['invoice_id'] ?? refuse(422, 'Say which invoice.')) ?? refuse(404, 'Invoice not found.');
$errors = [];
$amount = req_money('amount', 'The amount', $errors, true, true);
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$after = ct_guard($pdo, static function () use ($pdo, $me, $cn, $inv, $amount): array {
    $pdo->beginTransaction();
    apply_credit_note($pdo, $cn['credit_note_id'], $inv['invoice_id'], $amount, $me);
    $after = find_invoice($pdo, $inv['invoice_id']);
    credit_log($pdo, 'credit_note.apply', $cn, ['after' => ['number' => $cn['number'], 'amount' => $amount, 'currency' => $cn['currency'], 'invoice_id' => $inv['invoice_id'], 'invoice_number' => $inv['number'], 'invoice_status' => $after['status'], 'balance_due' => $after['balance_due']]] + ['invoice_id' => $inv['invoice_id']]);
    if ($after['status'] === 'paid' && $inv['status'] !== 'paid') {
        notify_paid($pdo, $after, $me);
    }
    $pdo->commit();
    return $after;
});
ct_done('Applied ' . fmt_money($amount, $cn['currency']) . ' of ' . $cn['number'] . ' to ' . $inv['number'] . '; it is owed ' . fmt_money($after['balance_due'], $cn['currency']), $cn['credit_note_id'], ct_land(return_path('/credit-notes/' . $cn['credit_note_id']), 'applied'), 'creditNoteChanged',
    ['credit_note_id' => $cn['credit_note_id'], 'invoice_id' => $inv['invoice_id'], 'balance_due' => $after['balance_due'], 'status' => $after['status']]);
