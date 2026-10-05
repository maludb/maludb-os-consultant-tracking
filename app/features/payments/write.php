<?php
declare(strict_types=1);

/** Writes of payments and claims (slice 6): a payment is an INSERT the guard referees (the invoice's currency, an open invoice, never an overpayment); a void is an UPDATE; a claim is confirmed by the verb. Paid is derived by the database. */

/** Record a payment against an open invoice: $f paid_on, amount, method, reference. The currency is the invoice's. Answers the payment id. */
function record_payment(PDO $pdo, int $invoiceId, array $f, int $by, string $source = 'manual'): int
{
    $cur = one_value($pdo, 'SELECT currency FROM invoices WHERE id = :i', ['i' => $invoiceId]);
    if ($cur === null) {
        throw new DomainException('Not found.');
    }
    $st = billing_write($pdo, 'INSERT INTO invoice_payments (invoice_id, paid_on, amount, currency, method, reference, source, recorded_by) VALUES (:i, COALESCE(CAST(:d AS date), current_date), :a, :c, :m, :r, :s, :by) RETURNING id',
        ['i' => $invoiceId, 'd' => $f['paid_on'] ?? null, 'a' => $f['amount'], 'c' => trim((string) $cur), 'm' => $f['method'] ?? 'transfer', 'r' => $f['reference'] ?? null, 's' => $source, 'by' => $by]);
    return (int) $st->fetchColumn();
}

/** Void a recorded payment, with a reason: the invoice reopens (the database derives partially paid or open again). Answers the payment's facts for the log. */
function void_payment(PDO $pdo, int $id, int $by, string $reason): array
{
    $st = $pdo->prepare('SELECT invoice_id, amount, currency, method, reference, source, status FROM invoice_payments WHERE id = :id');
    $st->execute(['id' => $id]);
    $p = $st->fetch();
    if ($p === false) {
        throw new DomainException('Not found.');
    }
    if ($p['status'] === 'void') {
        throw new DomainException('That payment is already void.');
    }
    billing_write($pdo, "UPDATE invoice_payments SET status = 'void', voided_by = :by, void_reason = :r WHERE id = :id", ['by' => $by, 'r' => $reason, 'id' => $id]);
    return ['invoice_id' => (int) $p['invoice_id'], 'amount' => $p['amount'], 'currency' => trim((string) $p['currency']), 'method' => $p['method'], 'reference' => $p['reference'], 'source' => $p['source']];
}

/** Confirm a claim into a payment (source claim) for the amount given, else the claim's, else the balance. Answers the payment row. */
function confirm_claim(PDO $pdo, int $id, int $by, ?string $amount): array
{
    return billing_verb($pdo, 'SELECT * FROM ct_payment_claim_confirm(:c, :by, CAST(:a AS numeric))', ['c' => $id, 'by' => $by, 'a' => $amount]);
}

/** Dismiss a waiting claim (a decision too). The reason goes in the log. */
function dismiss_claim(PDO $pdo, int $id, int $by, ?string $reason): void
{
    $st = $pdo->prepare("UPDATE payment_claims SET status = 'dismissed', decided_by = :by, decided_at = now() WHERE id = :id AND status = 'submitted' RETURNING id");
    $st->execute(['by' => $by, 'id' => $id]);
    if ($st->fetchColumn() === false) {
        $now = one_value($pdo, 'SELECT status FROM payment_claims WHERE id = :id', ['id' => $id]);
        throw new DomainException($now === null ? 'Not found.' : 'The claim is already ' . $now);
    }
}
