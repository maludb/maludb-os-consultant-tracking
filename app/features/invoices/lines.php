<?php
declare(strict_types=1);

/**
 * The lines of a draft invoice (slice 6): a fixed, adjustment or discount line typed by hand, a tax rate per line, the order; a line removed releases its backing items (they are unbilled again); one item
 * removed recomputes its line from what remains (hours summed — the unit price is the group's rate, kept). The money of a line (subtotal, tax, total) and the invoice's totals are the database's: PHP never adds them.
 */

function find_invoice_line(PDO $pdo, int $lineId): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_invoice_lines WHERE line_id = :l');
    $st->execute(['l' => $lineId]);
    $l = $st->fetch();
    if ($l === false) {
        return null;
    }
    foreach (['line_id', 'invoice_id', 'sort_order', 'tax_rate_id', 'item_count'] as $k) {
        $l[$k] = $l[$k] === null ? null : (int) $l[$k];
    }
    return $l;
}

function find_invoice_item(PDO $pdo, int $itemId): ?array
{
    $st = $pdo->prepare('SELECT item_id, invoice_id, line_id, time_entry_id, expense_id, usage_charge_id, milestone_id, amount FROM mcp_invoice_line_items WHERE item_id = :i');
    $st->execute(['i' => $itemId]);
    $x = $st->fetch();
    if ($x === false) {
        return null;
    }
    foreach (['item_id', 'invoice_id', 'line_id', 'time_entry_id', 'expense_id', 'usage_charge_id', 'milestone_id'] as $k) {
        $x[$k] = $x[$k] === null ? null : (int) $x[$k];
    }
    return $x;
}

/**
 * Add ($lineId null) or change a line of a draft. $f: kind, description, quantity, unit, unit_price, tax_rate_id, sort_order. A discount is stored as a negative price whatever sign was typed. A line with backing
 * items changes only its description, tax rate and place (its quantity and price are what the items made). The guard refuses an issued invoice and a bad amount in words. Answers the line id.
 */
function save_invoice_line(PDO $pdo, int $invoiceId, ?int $lineId, array $f, int $by): int
{
    $price = $f['unit_price'];
    if (($f['kind'] ?? '') === 'discount') {
        $price = number_format(-abs((float) $price), 2, '.', '');
    }
    if ($lineId === null) {
        $order = array_key_exists('sort_order', $f) && $f['sort_order'] !== null ? (int) $f['sort_order'] : (int) one_value($pdo, 'SELECT COALESCE(max(sort_order), 0) + 1 FROM invoice_lines WHERE invoice_id = :i', ['i' => $invoiceId]);
        $st = billing_write($pdo, 'INSERT INTO invoice_lines (invoice_id, sort_order, kind, description, quantity, unit, unit_price, tax_rate_id) VALUES (:i, :o, :k, :d, :q, :u, :p, :t) RETURNING id',
            ['i' => $invoiceId, 'o' => $order, 'k' => $f['kind'], 'd' => $f['description'], 'q' => $f['quantity'], 'u' => $f['unit'] ?? null, 'p' => $price, 't' => $f['tax_rate_id'] ?? null]);
        return (int) $st->fetchColumn();
    }
    $cur = find_invoice_line($pdo, $lineId);
    if ($cur === null || $cur['invoice_id'] !== $invoiceId) {
        throw new DomainException('Not found.');
    }
    if ($cur['item_count'] > 0) {
        if (((string) $f['quantity'] !== '' && (float) $f['quantity'] != (float) $cur['quantity']) || ((float) $price != (float) $cur['unit_price'] && $f['unit_price'] !== null)) {
            throw new DomainException('This line bills ' . $cur['item_count'] . ' item' . ($cur['item_count'] === 1 ? '' : 's') . '; its quantity and price are what they make. Take an item off, or remove the line, to change it.');
        }
        billing_write($pdo, 'UPDATE invoice_lines SET description = :d, tax_rate_id = :t, sort_order = COALESCE(:o, sort_order) WHERE id = :id', ['d' => $f['description'], 't' => $f['tax_rate_id'] ?? null, 'o' => $f['sort_order'] ?? null, 'id' => $lineId]);
        return $lineId;
    }
    billing_write($pdo, 'UPDATE invoice_lines SET kind = :k, description = :d, quantity = :q, unit = :u, unit_price = :p, tax_rate_id = :t, sort_order = COALESCE(:o, sort_order) WHERE id = :id',
        ['k' => $f['kind'], 'd' => $f['description'], 'q' => $f['quantity'], 'u' => $f['unit'] ?? null, 'p' => $price, 't' => $f['tax_rate_id'] ?? null, 'o' => $f['sort_order'] ?? null, 'id' => $lineId]);
    return $lineId;
}

/** Remove a line of a draft; its backing items go with it and are unbilled again. Answers what it was: kind, description, line_total, items released. */
function remove_invoice_line(PDO $pdo, int $lineId, int $by): array
{
    $l = find_invoice_line($pdo, $lineId);
    if ($l === null) {
        throw new DomainException('Not found.');
    }
    billing_write($pdo, 'DELETE FROM invoice_lines WHERE id = :id', ['id' => $lineId]);
    return ['invoice_id' => $l['invoice_id'], 'kind' => $l['kind'], 'description' => $l['description'], 'line_total' => $l['line_total'], 'items_released' => $l['item_count']];
}

/** Take one backing item off its line (it is unbilled again) and recompute the line from what remains: hours summed for a time line; a line left with nothing is removed. Answers what happened. */
function remove_invoice_item(PDO $pdo, int $itemId, int $by): array
{
    $x = find_invoice_item($pdo, $itemId);
    if ($x === null) {
        throw new DomainException('Not found.');
    }
    $line = find_invoice_line($pdo, $x['line_id']);
    billing_write($pdo, 'DELETE FROM invoice_line_items WHERE id = :id', ['id' => $itemId]);
    $left = (int) one_value($pdo, 'SELECT count(*) FROM invoice_line_items WHERE line_id = :l', ['l' => $x['line_id']]);
    $removed = false;
    if ($left === 0) {
        billing_write($pdo, 'DELETE FROM invoice_lines WHERE id = :l', ['l' => $x['line_id']]);
        $removed = true;
    } elseif ($line !== null && $line['kind'] === 'time') {
        billing_write($pdo, 'UPDATE invoice_lines SET quantity = (SELECT sum(t.hours) FROM invoice_line_items i JOIN time_entries t ON t.id = i.time_entry_id WHERE i.line_id = :l) WHERE id = :l2', ['l' => $x['line_id'], 'l2' => $x['line_id']]);
    }
    $kind = $x['time_entry_id'] !== null ? 'time' : ($x['expense_id'] !== null ? 'expense' : ($x['usage_charge_id'] !== null ? 'usage' : 'milestone'));
    return ['invoice_id' => $x['invoice_id'], 'line_id' => $x['line_id'], 'kind' => $kind, 'amount' => $x['amount'], 'line_removed' => $removed, 'items_left' => $left];
}
