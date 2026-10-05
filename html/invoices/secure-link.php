<?php
declare(strict_types=1);
/**
 * Action `secure_link_rotate` (log `invoice.secure_link_rotate`: number — never the token; confirm): billing.write; an issued invoice. A new link is made and the old one dies at once. The raw token is not shown anywhere (only a hash is kept):
 * send the invoice again to give the client the new link.
 */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$inv = invoice_from_request($pdo);
if ($inv['status'] === 'draft') {
    refuse(422, 'A draft has no link; issue the invoice first.');
}
ct_guard($pdo, static function () use ($pdo, $me, $inv): void {
    $pdo->beginTransaction();
    rotate_secure_link($pdo, $inv['invoice_id'], $me);                       // the raw token is dropped here on purpose
    invoice_log($pdo, 'invoice.secure_link_rotate', $inv, ['after' => ['number' => $inv['number']]]);
    $pdo->commit();
});
ct_flash('success', 'The old link no longer works. Send the invoice again to give the client a new one.');
ct_done('Rotated the secure link of ' . $inv['number'], $inv['invoice_id'], ct_land(return_path('/invoices/' . $inv['invoice_id'])), 'invoiceChanged', ['invoice_id' => $inv['invoice_id']]);
