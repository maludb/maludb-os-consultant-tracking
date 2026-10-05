<?php
declare(strict_types=1);
/** Action `invoice_write_off` (log `invoice.write_off`: number, amount — the balance forgiven —, currency, reason; confirm; `money_out` for agents): billing.write; an open invoice owed something. The balance is forgiven; the items stay billed. A reason is required. */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$inv = invoice_from_request($pdo);
$reason = (string) req_val('reason');
if ($reason === '' || mb_strlen($reason) > 1000) {
    ct_refuse_fields(['reason' => $reason === '' ? 'Say why the balance is forgiven.' : 'The reason is at most 1,000 characters.']);
}
$row = ct_guard($pdo, static function () use ($pdo, $me, $inv, $reason): array {
    $pdo->beginTransaction();
    $row = write_off_invoice($pdo, $inv['invoice_id'], $me, $reason);
    invoice_log($pdo, 'invoice.write_off', $inv, ['before' => ['status' => $inv['status']], 'after' => ['status' => 'written_off', 'number' => $inv['number'], 'amount' => $inv['balance_due'], 'currency' => $inv['currency'], 'total' => $inv['total'], 'reason' => $reason]]);
    $pdo->commit();
    return $row;
});
ct_done('Wrote off ' . fmt_money($inv['balance_due'], $inv['currency']) . ' of ' . $inv['number'], $inv['invoice_id'], ct_land(return_path('/invoices/' . $inv['invoice_id']), 'written_off'), 'invoiceChanged', ['invoice_id' => $inv['invoice_id'], 'status' => 'written_off', 'amount' => $inv['balance_due']]);
