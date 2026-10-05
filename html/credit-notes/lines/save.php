<?php
declare(strict_types=1);
/** Action `credit_note_line_save` (log `credit_note.line_save`: description, amount, currency): billing.write; a draft credit note. `line` changes one; `remove=yes` with a `line` takes it off. Money per line and the note's totals are the database's. */
require_once dirname(__DIR__, 3) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$cn = credit_note_from_request($pdo);
if ($cn['status'] !== 'draft') {
    refuse(422, 'Credit note ' . $cn['number'] . ' is ' . $cn['status'] . '; the lines of a credit note change only while it is a draft.');
}
$lineId = request_integer('line') ?? request_integer('line_id');
$cur = null;
if ($lineId !== null) {
    $cur = array_values(array_filter($cn['lines'], static fn (array $l): bool => $l['line_id'] === $lineId))[0] ?? refuse(404, 'Line not found.');
}
if ($lineId !== null && ct_yes('remove', false)) {
    $was = ct_guard($pdo, static function () use ($pdo, $me, $cn, $lineId): array {
        $pdo->beginTransaction();
        $was = remove_credit_note_line($pdo, $cn['credit_note_id'], $lineId, $me);
        credit_log($pdo, 'credit_note.line_save', $cn, ['before' => ['description' => $was['description'], 'amount' => $was['line_total'], 'currency' => $cn['currency']], 'after' => ['removed' => true, 'line_id' => $lineId]]);
        $pdo->commit();
        return $was;
    });
    ct_done('Removed the line', $cn['credit_note_id'], ct_land(return_path('/credit-notes/' . $cn['credit_note_id'] . '/edit'), 'line_removed'), 'creditNoteChanged', ['credit_note_id' => $cn['credit_note_id'], 'removed' => true]);
}
$errors = [];
$kind = req_has('kind') ? (string) req_val('kind') : ($cur['kind'] ?? 'adjustment');
if (!isset(CREDIT_LINE_KINDS[$kind])) {
    $errors['kind'] = 'Choose the kind of line.';
}
$desc = req_has('description') ? (string) req_val('description') : ($cur['description'] ?? '');
if ($desc === '' || mb_strlen($desc) > 500) {
    $errors['description'] = $desc === '' ? 'Say what is being credited.' : 'The description is at most 500 characters.';
}
$qty = req_has('quantity') && (string) req_val('quantity') !== '' ? str_replace(',', '', (string) req_val('quantity')) : ($cur['quantity'] ?? '1');
if (!preg_match('/^\d+(\.\d{1,4})?$/', $qty) || (float) $qty <= 0) {
    $errors['quantity'] = 'The quantity is more than zero (up to four decimals).';
}
$price = req_money('unit_price', 'The price', $errors, $cur === null, false);
if ($price === null && $cur !== null && !isset($errors['unit_price'])) {
    $price = $cur['unit_price'];
}
if ($price !== null && (float) $price < 0) {
    $errors['unit_price'] = 'A credit line is a positive amount — the note itself is the credit.';
}
$taxId = null;
$taxField = req_has('tax_rate') ? 'tax_rate' : (req_has('tax_rate_id') ? 'tax_rate_id' : null);
if ($taxField !== null) {
    $taxId = ct_ref($pdo, $taxField, null, 'SELECT 1 FROM mcp_tax_rates WHERE tax_rate_id = :id AND archived_at IS NULL', 'tax rate', $errors, true);
}
$order = req_has('sort_order') && (string) req_val('sort_order') !== '' ? ct_int('sort_order', null, 0, 10000, 'The place', $errors) : null;
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$id = ct_guard($pdo, static function () use ($pdo, $me, $cn, $lineId, $kind, $desc, $qty, $price, $taxId, $order): int {
    $pdo->beginTransaction();
    $id = save_credit_note_line($pdo, $cn['credit_note_id'], $lineId, ['kind' => $kind, 'description' => $desc, 'quantity' => $qty, 'unit_price' => $price, 'tax_rate_id' => $taxId, 'sort_order' => $order], $me);
    $after = find_credit_note($pdo, $cn['credit_note_id']);
    $line = array_values(array_filter($after['lines'], static fn (array $l): bool => $l['line_id'] === $id))[0] ?? null;
    credit_log($pdo, 'credit_note.line_save', $after, ['after' => ['line_id' => $id, 'description' => $desc, 'amount' => $line['line_total'] ?? null, 'currency' => $after['currency'], 'credit_total' => $after['total'], 'created' => $lineId === null]]);
    $pdo->commit();
    return $id;
});
ct_done(($lineId === null ? 'Added' : 'Saved') . ' the credit line', $cn['credit_note_id'], ct_land(return_path('/credit-notes/' . $cn['credit_note_id'] . '/edit'), 'line_saved'), 'creditNoteChanged', ['credit_note_id' => $cn['credit_note_id'], 'line_id' => $id]);
