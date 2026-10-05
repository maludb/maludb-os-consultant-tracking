<?php
declare(strict_types=1);
/** Action `credit_note_unapply` (log `credit_note.unapply`: number, amount, currency, invoice_number; confirm; `money_out` for agents): billing.write. An application is taken away; the invoice owes it again. */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$cn = credit_note_from_request($pdo);
$inv = find_invoice($pdo, request_integer('invoice') ?? request_integer('invoice_id') ?? refuse(422, 'Say which invoice.')) ?? refuse(404, 'Invoice not found.');
$after = ct_guard($pdo, static function () use ($pdo, $me, $cn, $inv): array {
    $pdo->beginTransaction();
    $amount = unapply_credit_note($pdo, $cn['credit_note_id'], $inv['invoice_id'], $me);
    $after = find_invoice($pdo, $inv['invoice_id']);
    credit_log($pdo, 'credit_note.unapply', $cn, ['after' => ['number' => $cn['number'], 'amount' => $amount, 'currency' => $cn['currency'], 'invoice_id' => $inv['invoice_id'], 'invoice_number' => $inv['number'], 'invoice_status' => $after['status'], 'balance_due' => $after['balance_due']]] + ['invoice_id' => $inv['invoice_id']]);
    $pdo->commit();
    return $after;
});
ct_done('Took ' . $cn['number'] . ' off ' . $inv['number'] . '; it is owed ' . fmt_money($after['balance_due'], $cn['currency']) . ' again', $cn['credit_note_id'], ct_land(return_path('/credit-notes/' . $cn['credit_note_id']), 'unapplied'), 'creditNoteChanged',
    ['credit_note_id' => $cn['credit_note_id'], 'invoice_id' => $inv['invoice_id'], 'balance_due' => $after['balance_due'], 'status' => $after['status']]);
