<?php
declare(strict_types=1);

/**
 * Payments and claims (slice 6). A payment is a record of STATUS (D10: the ledger keeps the receipt); paid is derived by the database; a claim is what a client said on the secure link and is never money until
 * Billing confirms it. Reads through mcp_invoice_payments / mcp_payment_claims (the views follow the invoice's visibility).
 */

const CLAIM_STATUSES = ['submitted' => 'Waiting', 'confirmed' => 'Confirmed', 'dismissed' => 'Dismissed'];
const PAYMENT_SOURCES = ['manual' => 'Recorded by hand', 'claim' => 'A client\'s claim, confirmed', 'ledger' => 'The ledger'];

/** Payments with their invoice and client. $filters: client_id, invoice_id, from, to, status (recorded|void|''), source. */
function find_payments(PDO $pdo, array $filters = [], int $limit = 200, int $offset = 0): array
{
    $where = [];
    $args = [];
    if (($filters['client_id'] ?? null) !== null) { $where[] = 'i.client_id = :client'; $args['client'] = (int) $filters['client_id']; }
    if (($filters['invoice_id'] ?? null) !== null) { $where[] = 'p.invoice_id = :inv'; $args['inv'] = (int) $filters['invoice_id']; }
    if (($filters['from'] ?? null) !== null) { $where[] = 'p.paid_on >= :from'; $args['from'] = $filters['from']; }
    if (($filters['to'] ?? null) !== null) { $where[] = 'p.paid_on <= :to'; $args['to'] = $filters['to']; }
    if (($filters['status'] ?? '') !== '') { $where[] = 'p.status = :st'; $args['st'] = $filters['status']; }
    if (($filters['source'] ?? '') !== '') { $where[] = 'p.source = :src'; $args['src'] = $filters['source']; }
    $st = $pdo->prepare('SELECT p.*, i.client_id, i.client_name, i.engagement_id, i.engagement_number, m.display_name AS recorded_by_name FROM mcp_invoice_payments p JOIN mcp_invoices i ON i.invoice_id = p.invoice_id
                          LEFT JOIN members m ON m.id = p.recorded_by' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY p.paid_on DESC, p.payment_id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return array_map(static function (array $p): array {
        $p = payment_cast($p);
        $p['client_id'] = (int) $p['client_id'];
        $p['engagement_id'] = (int) $p['engagement_id'];
        return $p;
    }, $st->fetchAll());
}

function find_payment(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT p.*, i.client_id, i.client_name, i.engagement_id, i.engagement_number, i.balance_due, i.total, m.display_name AS recorded_by_name FROM mcp_invoice_payments p JOIN mcp_invoices i ON i.invoice_id = p.invoice_id
                          LEFT JOIN members m ON m.id = p.recorded_by WHERE p.payment_id = :id');
    $st->execute(['id' => $id]);
    $p = $st->fetch();
    if ($p === false) {
        return null;
    }
    $p = payment_cast($p);
    $p['client_id'] = (int) $p['client_id'];
    $p['engagement_id'] = (int) $p['engagement_id'];
    return $p;
}

function payment_id_from_request(): ?int
{
    return request_integer('payment') ?? request_integer('payment_id') ?? request_integer('id');
}

/** The money of a payments list per currency (recorded ones): [currency => total]. */
function payment_totals(array $rows): array
{
    $t = [];
    foreach ($rows as $p) {
        if ($p['status'] === 'recorded') {
            $t[$p['currency']] = money_add($t[$p['currency']] ?? '0', (string) $p['amount']);
        }
    }
    return $t;
}

/** Payment claims with their invoice and client. $filters: status ('submitted' by default; 'all' for every), invoice_id, client_id. Oldest first when waiting. */
function find_claims(PDO $pdo, array $filters = [], int $limit = 200): array
{
    $status = $filters['status'] ?? 'submitted';
    $where = [];
    $args = [];
    if ($status !== 'all' && $status !== '') { $where[] = 'k.status = :st'; $args['st'] = $status; }
    if (($filters['invoice_id'] ?? null) !== null) { $where[] = 'k.invoice_id = :inv'; $args['inv'] = (int) $filters['invoice_id']; }
    if (($filters['client_id'] ?? null) !== null) { $where[] = 'i.client_id = :client'; $args['client'] = (int) $filters['client_id']; }
    $st = $pdo->prepare('SELECT k.*, i.client_id, i.client_name, i.engagement_number, i.balance_due, i.total, i.currency, i.status AS invoice_status FROM mcp_payment_claims k JOIN mcp_invoices i ON i.invoice_id = k.invoice_id'
        . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ($status === 'submitted' ? ' ORDER BY k.created_at, k.claim_id' : ' ORDER BY k.claim_id DESC') . ' LIMIT ' . max(1, $limit));
    $st->execute($args);
    return array_map(static function (array $k): array {
        $k = claim_cast($k);
        $k['client_id'] = (int) $k['client_id'];
        $k['currency'] = trim((string) $k['currency']);
        return $k;
    }, $st->fetchAll());
}

function find_claim(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT k.*, i.client_id, i.engagement_id, i.engagement_number, i.balance_due, i.currency, i.status AS invoice_status FROM mcp_payment_claims k JOIN mcp_invoices i ON i.invoice_id = k.invoice_id WHERE k.claim_id = :id');
    $st->execute(['id' => $id]);
    $k = $st->fetch();
    if ($k === false) {
        return null;
    }
    $k = claim_cast($k);
    $k['client_id'] = (int) $k['client_id'];
    $k['engagement_id'] = (int) $k['engagement_id'];
    $k['currency'] = trim((string) $k['currency']);
    return $k;
}

function claim_id_from_request(): ?int
{
    return request_integer('claim') ?? request_integer('claim_id') ?? request_integer('id');
}
