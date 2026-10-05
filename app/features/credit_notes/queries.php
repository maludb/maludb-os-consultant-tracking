<?php
declare(strict_types=1);

/** Credit notes (slice 6): a correction after issue — never a refund (D14: nothing here pays anyone). Reads through mcp_credit_notes / mcp_credit_note_lines / mcp_credit_applications. */

const CREDIT_STATUSES = ['draft' => 'Draft', 'issued' => 'Issued', 'void' => 'Void'];
const CREDIT_TONES = ['draft' => 'secondary', 'issued' => 'primary', 'void' => 'dark'];
const CREDIT_LINE_KINDS = ['adjustment' => 'Adjustment', 'fixed' => 'Fixed', 'time' => 'Time', 'expense' => 'Expense', 'usage' => 'Usage', 'milestone' => 'Milestone'];

function credit_note_cast(array $c): array
{
    foreach (['credit_note_id', 'client_id', 'engagement_id', 'invoice_id', 'issued_by', 'voided_by', 'created_by'] as $k) {
        $c[$k] = ($c[$k] ?? null) === null ? null : (int) $c[$k];
    }
    $c['currency'] = trim((string) $c['currency']);
    return $c;
}

/** Credit notes through the view, newest first. $filters: client_id, status, invoice_id. */
function find_credit_notes(PDO $pdo, array $filters = [], int $limit = 100, int $offset = 0): array
{
    $where = [];
    $args = [];
    if (($filters['client_id'] ?? null) !== null) { $where[] = 'n.client_id = :client'; $args['client'] = (int) $filters['client_id']; }
    if (($filters['invoice_id'] ?? null) !== null) { $where[] = 'n.invoice_id = :inv'; $args['inv'] = (int) $filters['invoice_id']; }
    if (($filters['status'] ?? '') !== '') { $where[] = 'n.status = :st'; $args['st'] = $filters['status']; }
    $st = $pdo->prepare('SELECT n.* FROM mcp_credit_notes n' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY n.credit_note_id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return array_map('credit_note_cast', $st->fetchAll());
}

function credit_note_id_from_request(): ?int
{
    return request_integer('credit_note') ?? request_integer('credit_note_id') ?? request_integer('id');
}

/** One credit note with its lines, the invoices it is applied to, documents and notes — or null when the caller may not see it. */
function find_credit_note(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT n.* FROM mcp_credit_notes n WHERE n.credit_note_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $c = credit_note_cast($r);
    $ln = $pdo->prepare('SELECT * FROM mcp_credit_note_lines WHERE credit_note_id = :c ORDER BY sort_order, line_id');
    $ln->execute(['c' => $id]);
    $c['lines'] = array_map(static function (array $l): array {
        $l['line_id'] = (int) $l['line_id'];
        $l['credit_note_id'] = (int) $l['credit_note_id'];
        $l['sort_order'] = (int) $l['sort_order'];
        return $l;
    }, $ln->fetchAll());
    $ap = $pdo->prepare('SELECT a.invoice_id, i.number AS invoice_number, i.status AS invoice_status, i.balance_due, a.amount, a.applied_at FROM mcp_credit_applications a JOIN mcp_invoices i ON i.invoice_id = a.invoice_id WHERE a.credit_note_id = :c ORDER BY a.applied_at');
    $ap->execute(['c' => $id]);
    $c['applications'] = array_map(static function (array $a): array {
        $a['invoice_id'] = (int) $a['invoice_id'];
        return $a;
    }, $ap->fetchAll());
    require_once dirname(__DIR__) . '/documents/write.php';
    $c['attachments'] = find_attachments($pdo, 'credit_note', $id);
    return $c;
}

/** The open invoices of a client in a currency a credit may be applied to: [{invoice_id, number, engagement_number, balance_due}]. */
function applicable_invoices(PDO $pdo, int $clientId, string $currency): array
{
    $st = $pdo->prepare("SELECT invoice_id, number, engagement_number, balance_due FROM mcp_invoices WHERE client_id = :c AND currency = :cur AND status IN ('issued', 'sent', 'viewed', 'partially_paid') ORDER BY issue_date, invoice_id");
    $st->execute(['c' => $clientId, 'cur' => $currency]);
    return array_map(static function (array $i): array {
        $i['invoice_id'] = (int) $i['invoice_id'];
        return $i;
    }, $st->fetchAll());
}
