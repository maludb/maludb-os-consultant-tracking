<?php
declare(strict_types=1);

/** Writes of credit notes (slice 6): a draft to the base table (the guard copies the invoice's client, engagement and currency), lines while draft, the verb to issue, an application is a row the guard bounds. */

/**
 * Draft a credit note. $f: invoice_id (the one it corrects — the guard copies its client, engagement and currency), client_id (when none), reason, issue_date. Without an invoice the currency is the client's, else the firm's.
 * Answers the id.
 */
function save_credit_note(PDO $pdo, ?int $id, array $f, int $by): int
{
    if ($id === null) {
        $cur = one_value($pdo, 'SELECT COALESCE(c.currency, (SELECT base_currency FROM ct_settings WHERE id = 1)) FROM clients c WHERE c.id = :c', ['c' => $f['client_id']]);
        if ($cur === null) {
            throw new DomainException('Choose the client from the list.');
        }
        $st = billing_write($pdo, 'INSERT INTO credit_notes (client_id, invoice_id, issue_date, currency, reason, created_by) VALUES (:c, :i, COALESCE(CAST(:d AS date), current_date), :cur, :r, :by) RETURNING id',
            ['c' => $f['client_id'], 'i' => $f['invoice_id'] ?? null, 'd' => $f['issue_date'] ?? null, 'cur' => trim((string) $cur), 'r' => $f['reason'] ?? null, 'by' => $by]);
        return (int) $st->fetchColumn();
    }
    billing_write($pdo, 'UPDATE credit_notes SET issue_date = COALESCE(CAST(:d AS date), issue_date), reason = :r WHERE id = :id', ['d' => $f['issue_date'] ?? null, 'r' => $f['reason'] ?? null, 'id' => $id]);
    return $id;
}

/** Add ($lineId null) or change a line of a draft credit note. $f: kind, description, quantity, unit_price, tax_rate_id, sort_order. Answers the line id. */
function save_credit_note_line(PDO $pdo, int $noteId, ?int $lineId, array $f, int $by): int
{
    if ($lineId === null) {
        $order = array_key_exists('sort_order', $f) && $f['sort_order'] !== null ? (int) $f['sort_order'] : (int) one_value($pdo, 'SELECT COALESCE(max(sort_order), 0) + 1 FROM credit_note_lines WHERE credit_note_id = :c', ['c' => $noteId]);
        $st = billing_write($pdo, 'INSERT INTO credit_note_lines (credit_note_id, sort_order, kind, description, quantity, unit_price, tax_rate_id) VALUES (:c, :o, :k, :d, :q, :p, :t) RETURNING id',
            ['c' => $noteId, 'o' => $order, 'k' => $f['kind'], 'd' => $f['description'], 'q' => $f['quantity'], 'p' => $f['unit_price'], 't' => $f['tax_rate_id'] ?? null]);
        return (int) $st->fetchColumn();
    }
    $own = one_value($pdo, 'SELECT credit_note_id FROM credit_note_lines WHERE id = :l', ['l' => $lineId]);
    if ($own === null || (int) $own !== $noteId) {
        throw new DomainException('Not found.');
    }
    billing_write($pdo, 'UPDATE credit_note_lines SET kind = :k, description = :d, quantity = :q, unit_price = :p, tax_rate_id = :t, sort_order = COALESCE(:o, sort_order) WHERE id = :id',
        ['k' => $f['kind'], 'd' => $f['description'], 'q' => $f['quantity'], 'p' => $f['unit_price'], 't' => $f['tax_rate_id'] ?? null, 'o' => $f['sort_order'] ?? null, 'id' => $lineId]);
    return $lineId;
}

/** Remove one line of a draft credit note. Answers its description. */
function remove_credit_note_line(PDO $pdo, int $noteId, int $lineId, int $by): array
{
    $st = $pdo->prepare('SELECT description, line_total FROM credit_note_lines WHERE id = :l AND credit_note_id = :c');
    $st->execute(['l' => $lineId, 'c' => $noteId]);
    $l = $st->fetch();
    if ($l === false) {
        throw new DomainException('Not found.');
    }
    billing_write($pdo, 'DELETE FROM credit_note_lines WHERE id = :l', ['l' => $lineId]);
    return $l;
}

/** Delete a draft credit note (its lines go with it; a draft has no number). Answers the row for the log. */
function delete_credit_note(PDO $pdo, int $id, int $by): array
{
    $st = $pdo->prepare('SELECT client_id, invoice_id, status, total, currency FROM credit_notes WHERE id = :id');
    $st->execute(['id' => $id]);
    $c = $st->fetch();
    if ($c === false) {
        throw new DomainException('Not found.');
    }
    if ($c['status'] !== 'draft') {
        throw new DomainException('Only a draft is deleted; an issued credit note is voided.');
    }
    billing_write($pdo, 'DELETE FROM credit_note_lines WHERE credit_note_id = :id', ['id' => $id]);
    billing_write($pdo, 'DELETE FROM credit_notes WHERE id = :id', ['id' => $id]);
    return ['client_id' => (int) $c['client_id'], 'invoice_id' => $c['invoice_id'] === null ? null : (int) $c['invoice_id'], 'total' => $c['total'], 'currency' => trim((string) $c['currency'])];
}

/** Issue: the gapless CN number, a note for nothing refused. Answers the row. */
function issue_credit_note(PDO $pdo, int $id, int $by): array
{
    return billing_verb($pdo, 'SELECT * FROM ct_credit_note_issue(:i, :by)', ['i' => $id, 'by' => $by]);
}

/** Apply an issued credit to an invoice for an amount (never over what the note holds or the invoice owes — the guard's words); applying again to the same invoice sets that amount. */
function apply_credit_note(PDO $pdo, int $id, int $invoiceId, string $amount, int $by): void
{
    billing_write($pdo, 'INSERT INTO credit_applications (credit_note_id, invoice_id, amount, applied_by) VALUES (:c, :i, :a, :by) ON CONFLICT (credit_note_id, invoice_id) DO UPDATE SET amount = EXCLUDED.amount, applied_by = EXCLUDED.applied_by, applied_at = now()',
        ['c' => $id, 'i' => $invoiceId, 'a' => $amount, 'by' => $by]);
}

/** Take an application away; answers its amount. */
function unapply_credit_note(PDO $pdo, int $id, int $invoiceId, int $by): string
{
    $st = $pdo->prepare('DELETE FROM credit_applications WHERE credit_note_id = :c AND invoice_id = :i RETURNING amount');
    $st->execute(['c' => $id, 'i' => $invoiceId]);
    $a = $st->fetchColumn();
    if ($a === false) {
        throw new DomainException('That credit note is not applied to that invoice.');
    }
    return (string) $a;
}

/** Void an issued credit note that is applied to nothing (the guard refuses an applied one in words). */
function void_credit_note(PDO $pdo, int $id, int $by, string $reason): array
{
    $c = $pdo->prepare('SELECT number, status, total, currency, invoice_id FROM credit_notes WHERE id = :id');
    $c->execute(['id' => $id]);
    $r = $c->fetch();
    if ($r === false) {
        throw new DomainException('Not found.');
    }
    if ($r['status'] !== 'issued') {
        throw new DomainException($r['status'] === 'draft' ? 'A draft is deleted, not voided.' : 'Credit note ' . $r['number'] . ' is already void.');
    }
    billing_write($pdo, "UPDATE credit_notes SET status = 'void', voided_at = now(), voided_by = :by, void_reason = :r WHERE id = :id", ['by' => $by, 'r' => $reason, 'id' => $id]);
    return ['number' => $r['number'], 'total' => $r['total'], 'currency' => trim((string) $r['currency']), 'invoice_id' => $r['invoice_id'] === null ? null : (int) $r['invoice_id']];
}
