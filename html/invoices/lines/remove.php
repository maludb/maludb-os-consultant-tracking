<?php
declare(strict_types=1);
/** Action `invoice_line_remove` (log `invoice.line_remove`: kind, description, amount, currency, items released; confirm): invoices.draft AND manage; a draft. The line's backing items go with it and are unbilled again. */
require_once dirname(__DIR__, 3) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('invoices.draft');
$pdo = db();
$me = (int) current_member_id();
$lineId = request_integer('line') ?? request_integer('line_id') ?? refuse(422, 'Say which line.');
$line = find_invoice_line($pdo, $lineId) ?? refuse(404, 'Line not found.');
$inv = find_invoice($pdo, $line['invoice_id']) ?? refuse(404, 'Invoice not found.');
require_draft_on($inv);
$was = ct_guard($pdo, static function () use ($pdo, $me, $inv, $lineId): array {
    $pdo->beginTransaction();
    $was = remove_invoice_line($pdo, $lineId, $me);
    invoice_log($pdo, 'invoice.line_remove', $inv, ['before' => ['kind' => $was['kind'], 'description' => $was['description'], 'amount' => $was['line_total'], 'currency' => $inv['currency']], 'after' => ['items_released' => $was['items_released']]]);
    $pdo->commit();
    return $was;
});
ct_done('Removed the line' . ($was['items_released'] > 0 ? '; its ' . $was['items_released'] . ' item' . ($was['items_released'] === 1 ? ' is' : 's are') . ' unbilled again' : ''), $inv['invoice_id'], ct_land(return_path('/invoices/' . $inv['invoice_id'] . '/edit'), 'line_removed'), 'invoiceChanged',
    ['invoice_id' => $inv['invoice_id'], 'items_released' => $was['items_released']]);
