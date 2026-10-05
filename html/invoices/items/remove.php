<?php
declare(strict_types=1);
/** Action `invoice_item_remove` (log `invoice.item_remove`: kind, amount, currency; confirm): invoices.draft AND manage; a draft. One backing item leaves its line and is unbilled again; the line's hours and total are recomputed from what remains (a line left with nothing is removed). */
require_once dirname(__DIR__, 3) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('invoices.draft');
$pdo = db();
$me = (int) current_member_id();
$itemId = request_integer('item') ?? request_integer('item_id') ?? refuse(422, 'Say which item.');
$item = find_invoice_item($pdo, $itemId) ?? refuse(404, 'Item not found.');
$inv = find_invoice($pdo, $item['invoice_id']) ?? refuse(404, 'Invoice not found.');
require_draft_on($inv);
$was = ct_guard($pdo, static function () use ($pdo, $me, $inv, $itemId): array {
    $pdo->beginTransaction();
    $was = remove_invoice_item($pdo, $itemId, $me);
    $after = find_invoice($pdo, $inv['invoice_id']);
    invoice_log($pdo, 'invoice.item_remove', $after, ['before' => ['total' => $inv['total']], 'after' => ['kind' => $was['kind'], 'amount' => $was['amount'], 'currency' => $after['currency'], 'invoice_total' => $after['total'], 'line_removed' => $was['line_removed'], 'items_left' => $was['items_left']]]);
    $pdo->commit();
    return $was;
});
ct_done('Took the item off; it is unbilled again' . ($was['line_removed'] ? ' and its line is gone' : ''), $inv['invoice_id'], ct_land(return_path('/invoices/' . $inv['invoice_id'] . '/edit'), 'item_removed'), 'invoiceChanged',
    ['invoice_id' => $inv['invoice_id'], 'line_removed' => $was['line_removed'], 'items_left' => $was['items_left']]);
