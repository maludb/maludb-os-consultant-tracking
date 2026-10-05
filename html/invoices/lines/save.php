<?php
declare(strict_types=1);
/**
 * Action `invoice_line_save` (log `invoice.line_save`: kind, description, amount, currency — the line's own total from the database): invoices.draft AND manage; a draft. A fixed, adjustment or discount line typed by hand (a discount
 * is stored negative), a tax rate per line, a place in the order; `line` changes one — a line that bills items changes only its description, tax rate and place. Money per line and the invoice's totals are the database's.
 */
require_once dirname(__DIR__, 3) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('invoices.draft');
$pdo = db();
$me = (int) current_member_id();
$inv = invoice_from_request($pdo);
require_draft_on($inv);
$lineId = request_integer('line') ?? request_integer('line_id');
$curLine = null;
if ($lineId !== null) {
    $curLine = find_invoice_line($pdo, $lineId);
    if ($curLine === null || $curLine['invoice_id'] !== $inv['invoice_id']) {
        refuse(404, 'Line not found.');
    }
}
$errors = [];
$kind = req_has('kind') ? (string) req_val('kind') : ($curLine['kind'] ?? '');
if (!isset(LINE_KINDS[$kind]) || $kind === 'milestone' && $curLine === null) {
    $errors['kind'] = 'Choose what kind of line it is (fixed, adjustment, discount, time, expense or usage).';
}
$desc = req_has('description') ? (string) req_val('description') : ($curLine['description'] ?? '');
if ($desc === '' || mb_strlen($desc) > 500) {
    $errors['description'] = $desc === '' ? 'Say what the line is for.' : 'The description is at most 500 characters.';
}
$qty = req_has('quantity') && (string) req_val('quantity') !== '' ? (string) req_val('quantity') : ($curLine['quantity'] ?? '1');
if (!preg_match('/^\d+(\.\d{1,4})?$/', str_replace(',', '', $qty)) || (float) str_replace(',', '', $qty) <= 0) {
    $errors['quantity'] = 'The quantity is more than zero (up to four decimals).';
}
$qty = str_replace(',', '', $qty);
$price = null;
if (req_has('unit_price') && (string) req_val('unit_price') !== '') {
    $price = parse_money((string) req_val('unit_price'));
    if ($price === null) { $errors['unit_price'] = 'The price is a number with at most two decimals (1,200.00).'; }
} elseif ($curLine !== null) {
    $price = $curLine['unit_price'];
} else {
    $errors['unit_price'] = 'The price is required.';
}
if ($price !== null && !in_array($kind, ['adjustment', 'discount'], true) && (float) $price < 0) {
    $errors['unit_price'] = 'Only an adjustment or a discount is negative.';
}
$taxId = $curLine['tax_rate_id'] ?? null;
if (req_has('tax_rate') || req_has('tax_rate_id')) {
    $taxId = ct_ref($pdo, req_has('tax_rate') ? 'tax_rate' : 'tax_rate_id', $taxId, 'SELECT 1 FROM mcp_tax_rates WHERE tax_rate_id = :id AND archived_at IS NULL', 'tax rate', $errors, true);
}
$order = req_has('sort_order') && (string) req_val('sort_order') !== '' ? ct_int('sort_order', null, 0, 10000, 'The place', $errors) : null;
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$f = ['kind' => $kind, 'description' => $desc, 'quantity' => $qty, 'unit' => req_has('unit') ? ((string) req_val('unit') ?: null) : ($curLine['unit'] ?? null), 'unit_price' => $price, 'tax_rate_id' => $taxId, 'sort_order' => $order];
$id = ct_guard($pdo, static function () use ($pdo, $me, $inv, $lineId, $f): int {
    $pdo->beginTransaction();
    $id = save_invoice_line($pdo, $inv['invoice_id'], $lineId, $f, $me);
    $after = find_invoice($pdo, $inv['invoice_id']);
    $line = array_values(array_filter($after['lines'], static fn (array $l): bool => $l['line_id'] === $id))[0] ?? null;
    invoice_log($pdo, 'invoice.line_save', $after, ['after' => ['line_id' => $id, 'kind' => $f['kind'], 'description' => $f['description'], 'amount' => $line['line_total'] ?? null, 'currency' => $after['currency'], 'invoice_total' => $after['total'], 'created' => $lineId === null]]);
    $pdo->commit();
    return $id;
});
ct_done(($lineId === null ? 'Added' : 'Saved') . ' the line', $inv['invoice_id'], ct_land(return_path('/invoices/' . $inv['invoice_id'] . '/edit'), 'line_saved'), 'invoiceChanged', ['invoice_id' => $inv['invoice_id'], 'line_id' => $id]);
