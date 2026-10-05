<?php
declare(strict_types=1);
/** Action `invoice_delete` (log `invoice.delete`: amount, currency; confirm; `deletion` for agents): invoices.draft AND manage; a draft only. Its lines and items go with it, the items are unbilled again, and no number is burned — a draft has none. */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('invoices.draft');
$pdo = db();
$me = (int) current_member_id();
$inv = invoice_from_request($pdo);
require_draft_on($inv);
$was = ct_guard($pdo, static function () use ($pdo, $me, $inv): array {
    $pdo->beginTransaction();
    $was = delete_invoice($pdo, $inv['invoice_id'], $me);
    invoice_log($pdo, 'invoice.delete', $inv, ['before' => ['status' => 'draft', 'amount' => $was['total'], 'currency' => $was['currency'], 'total' => $was['total'], 'lines' => count($inv['lines'])]]);
    $pdo->commit();
    return $was;
});
ct_done('Deleted the draft for ' . $inv['engagement_number'], $inv['invoice_id'], ct_land(return_path('/invoices/'), 'deleted'), 'invoiceChanged', ['invoice_id' => $inv['invoice_id'], 'deleted' => true]);
