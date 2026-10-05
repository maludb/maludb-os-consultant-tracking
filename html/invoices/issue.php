<?php
declare(strict_types=1);
/**
 * Action `invoice_issue` (log `invoice.issue`: number, amount, currency, nte_override_reason, receipts_shared; confirm; `money_out` for agents): billing.write (a Manager drafts, never issues). The verb refuses in the database's words — a client's
 * PO rule, the not-to-exceed cap (above it Billing gives `nte_override_reason`), a draft with no lines or nothing owed — and numbers the invoice gaplessly (INV-00001…), marking its milestones invoiced. Then the secure link is minted (the raw
 * token is never kept) and the engagement's receipts are shared with the client exactly when the engagement says so.
 */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$inv = invoice_from_request($pdo);
$reason = req_has('nte_override_reason') ? (string) req_val('nte_override_reason') : null;
if ($reason !== null && mb_strlen($reason) > 1000) {
    ct_refuse_fields(['nte_override_reason' => 'The reason is at most 1,000 characters.']);
}
$row = ct_guard($pdo, static function () use ($pdo, $me, $inv, $reason): array {
    $pdo->beginTransaction();
    $row = issue_invoice($pdo, $inv['invoice_id'], $me, $reason === '' ? null : $reason);
    $after = find_invoice($pdo, $inv['invoice_id']);
    invoice_log($pdo, 'invoice.issue', $after, ['before' => ['status' => 'draft'], 'after' => ['status' => $after['status'], 'number' => $after['number'], 'amount' => $after['total'], 'currency' => $after['currency'], 'total' => $after['total'],
        'subtotal' => $after['subtotal'], 'tax_total' => $after['tax_total'], 'nte_override_reason' => $row['nte_override_reason'] ?? null, 'receipts_shared' => $row['receipts_shared'], 'po_number' => $after['po_number']]]);
    $pdo->commit();
    return $row + ['after' => $after];
});
$after = $row['after'];
ct_done('Issued ' . invoice_label($after), $inv['invoice_id'], ct_land(return_path('/invoices/' . $inv['invoice_id']), 'issued'), 'invoiceChanged',
    ['invoice_id' => $inv['invoice_id'], 'status' => $after['status'], 'number' => $after['number'], 'total' => $after['total'], 'currency' => $after['currency']]);
