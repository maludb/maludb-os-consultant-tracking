<?php
declare(strict_types=1);

/**
 * The versioned documents of the accounting interface (design §8, D15 — shares and exports, never shared tables): `os.ct-invoices/1`, `os.ct-credit-notes/1`, `os.ct-expenses/1`, `os.ct-wip/1`, `os.ct-reimbursements/1`,
 * `os.ct-engagements/1` are what the six `shares[]` of maludb-os.json answer (the tools are Phase 4's; these are their query functions), and what the exports page downloads — ONE function each, two doors. `os.ct-time/1` and
 * `os.ct-usage/1` are the page's own two details. Every document is {schema, generated_at, application, from, to (or as_of), currency_note, rows}; amounts are decimal strings carrying their own currency — nothing is converted.
 *
 * A function takes a `$caller`: 'person' (the default — the connection reads as the signed-in member, so the mcp_* views and the report functions decide the rows, and a right the share's gate names is checked in SQL) or 'kernel'
 * (the kernel's token, K7: every row, the connection reads as the firm's administrator for the length of the call and is put back; the kernel's token reaches ONLY these six). A caller who may not read a document gets ShareRefused.
 * A cost rate never appears: time carries bill value only under rates.view (the view), usage carries cost only under cost.view (the view).
 */

final class ShareRefused extends RuntimeException
{
}

const SHARE_APPLICATION = 'consultant_tracking';
const SHARE_CURRENCY_NOTE = 'amounts carry their own currency; nothing is converted';

/** The document's head. $period is ['from' =>, 'to' =>] or ['as_of' =>]. */
function share_document(string $schema, array $period, array $rows): array
{
    return ['schema' => $schema, 'generated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'application' => SHARE_APPLICATION] + $period + ['currency_note' => SHARE_CURRENCY_NOTE, 'rows' => $rows];
}

/** The kernel's token read a share: logged `share.read` (source application, no actor) with the tool, the argument KEYS that were given and the rows answered — never a row. A person's download is logged by the exports page. */
function share_log(PDO $pdo, string $caller, string $tool, array $given, array $doc): array
{
    if ($caller === 'kernel') {
        log_activity($pdo, 'share.read', 'share', null, ['source' => 'application', 'actor_member_id' => null, 'after' => ['direction' => 'in', 'tool' => $tool, 'keys' => array_keys(array_filter($given, static fn ($v): bool => $v !== null)), 'rows' => count($doc['rows'])]]);
    }
    return $doc;
}

/** A money value as the documents print it: two decimals always (a sum over no rows is '0' in SQL, never "0.00"). */
function share_money(mixed $v): string
{
    return number_format((float) $v, 2, '.', '');
}

/** A day (Y-m-d) or a sentence. */
function share_date(?string $d, string $what): string
{
    $x = $d === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    if ($x === false || $x->format('Y-m-d') !== $d) {
        throw new DomainException($what . ' is a day (2026-03-02).');
    }
    return (string) $d;
}

/** Run $fn as the caller reads: a person as themselves, the kernel as the firm's administrator (put back afterwards). */
function share_as(PDO $pdo, string $caller, callable $fn): mixed
{
    if ($caller !== 'kernel') {
        return $fn();
    }
    $actor = worker_actor_for_shares($pdo);
    if ($actor === null) {
        throw new ShareRefused('There is no administrator to read as; nothing can be shared yet.');
    }
    $prev = (string) one_value($pdo, "SELECT COALESCE(current_setting('app.member_id', true), '')");
    $pdo->prepare('SELECT set_config(?, ?, false)')->execute(['app.member_id', (string) $actor]);
    try {
        return $fn();
    } finally {
        $pdo->prepare('SELECT set_config(?, ?, false)')->execute(['app.member_id', $prev]);
    }
}

/** The firm's administrator: the lowest-numbered live human super-admin (else an admin-capability human). */
function worker_actor_for_shares(PDO $pdo): ?int
{
    $v = one_value($pdo, "SELECT id FROM members WHERE status = 'active' AND member_kind = 'human' AND capability IS NOT NULL AND (business_role = 'super_admin' OR capability = 'admin') ORDER BY (business_role = 'super_admin') DESC, id LIMIT 1");
    return $v === null ? null : (int) $v;
}

/** A person must hold one of the rights ("a|b"); the kernel is never asked. */
function share_gate(PDO $pdo, string $caller, string $rights, string $sentence): void
{
    if ($caller === 'kernel') {
        return;
    }
    foreach (explode('|', $rights) as $r) {
        if (db_bool($pdo, 'SELECT ct_has_right(:r)', ['r' => $r])) {
            return;
        }
    }
    throw new ShareRefused($sentence);
}

/** Lines of invoices or credit notes summarised by kind: [parent id => [{kind, subtotal, tax, total}]]. */
function share_lines_by_kind(PDO $pdo, string $view, string $parentKey, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $st = $pdo->prepare("SELECT l.$parentKey AS parent, l.kind, sum(l.line_subtotal) AS subtotal, sum(l.line_tax) AS tax, sum(l.line_total) AS total FROM $view l WHERE l.$parentKey = ANY (CAST(:ids AS bigint[])) GROUP BY 1, 2 ORDER BY 1, 2");
    $st->execute(['ids' => pg_array_literal(array_map('intval', $ids))]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['parent']][] = ['kind' => $r['kind'], 'subtotal' => $r['subtotal'], 'tax' => $r['tax'], 'total' => $r['total']];
    }
    return $out;
}

/** The client of a row as the documents name it. */
function share_client(array $r): array
{
    return ['client_id' => (int) $r['client_id'], 'name' => $r['client_name'], 'legal_name' => $r['legal_name'] ?? null, 'code' => $r['code'] ?? null];
}

/** os.ct-invoices/1 — invoices issued (not draft, not void) with an issue date in the period, lines summarised by kind. Gate: billing.write or reports.view. */
function share_invoices_issued(PDO $pdo, string $from, string $to, ?int $clientId = null, string $caller = 'person'): array
{
    $from = share_date($from, 'The first day');
    $to = share_date($to, 'The last day');
    share_gate($pdo, $caller, 'billing.write|reports.view', 'You may not read the invoices issued; it is Billing\'s and Finance\'s.');
    $doc = share_as($pdo, $caller, static function () use ($pdo, $from, $to, $clientId): array {
        $st = $pdo->prepare("SELECT i.invoice_id, i.number, i.client_id, i.client_name, c.legal_name, c.code, i.engagement_number, i.issue_date, i.due_date, (i.due_date - i.issue_date) AS terms_days, i.currency, i.subtotal, i.tax_total, i.total, i.status, i.external_ref
                               FROM mcp_invoices i LEFT JOIN mcp_clients c ON c.client_id = i.client_id
                              WHERE i.status NOT IN ('draft', 'void') AND i.issue_date >= CAST(:f AS date) AND i.issue_date <= CAST(:t AS date) AND (CAST(:c AS bigint) IS NULL OR i.client_id = CAST(:c2 AS bigint)) ORDER BY i.issue_date, i.number");
        $st->execute(['f' => $from, 't' => $to, 'c' => $clientId, 'c2' => $clientId]);
        $r = $st->fetchAll();
        $lines = share_lines_by_kind($pdo, 'mcp_invoice_lines', 'invoice_id', array_column($r, 'invoice_id'));
        return share_document('os.ct-invoices/1', ['from' => $from, 'to' => $to], array_map(static fn (array $i): array => [
            'number' => $i['number'], 'client' => share_client($i), 'engagement_number' => $i['engagement_number'], 'issue_date' => $i['issue_date'], 'due_date' => $i['due_date'], 'terms_days' => (int) $i['terms_days'],
            'currency' => trim((string) $i['currency']), 'subtotal' => $i['subtotal'], 'tax_total' => $i['tax_total'], 'total' => $i['total'], 'status' => $i['status'], 'external_ref' => $i['external_ref'],
            'lines_by_kind' => $lines[(int) $i['invoice_id']] ?? []], $r));
    });
    return share_log($pdo, $caller, 'invoices_issued', ['from' => $from, 'to' => $to, 'client_id' => $clientId], $doc);
}

/** os.ct-credit-notes/1 — credit notes issued in the period (amounts positive; the reader negates). Gate: billing.write. */
function share_credit_notes_issued(PDO $pdo, string $from, string $to, ?int $clientId = null, string $caller = 'person'): array
{
    $from = share_date($from, 'The first day');
    $to = share_date($to, 'The last day');
    share_gate($pdo, $caller, 'billing.write', 'You may not read the credit notes issued; it is Billing\'s.');
    $doc = share_as($pdo, $caller, static function () use ($pdo, $from, $to, $clientId): array {
        $st = $pdo->prepare("SELECT n.credit_note_id, n.number, n.client_id, n.client_name, c.legal_name, c.code, g.number AS engagement_number, n.invoice_number, n.issue_date, n.currency, n.subtotal, n.tax_total, n.total, n.status, n.external_ref
                               FROM mcp_credit_notes n LEFT JOIN mcp_clients c ON c.client_id = n.client_id LEFT JOIN engagements g ON g.id = n.engagement_id
                              WHERE n.status = 'issued' AND n.issue_date >= CAST(:f AS date) AND n.issue_date <= CAST(:t AS date) AND (CAST(:c AS bigint) IS NULL OR n.client_id = CAST(:c2 AS bigint)) ORDER BY n.issue_date, n.number");
        $st->execute(['f' => $from, 't' => $to, 'c' => $clientId, 'c2' => $clientId]);
        $r = $st->fetchAll();
        $lines = share_lines_by_kind($pdo, 'mcp_credit_note_lines', 'credit_note_id', array_column($r, 'credit_note_id'));
        return share_document('os.ct-credit-notes/1', ['from' => $from, 'to' => $to], array_map(static fn (array $n): array => [
            'number' => $n['number'], 'client' => share_client($n), 'engagement_number' => $n['engagement_number'], 'invoice_number' => $n['invoice_number'], 'issue_date' => $n['issue_date'],
            'currency' => trim((string) $n['currency']), 'subtotal' => $n['subtotal'], 'tax_total' => $n['tax_total'], 'total' => $n['total'], 'status' => $n['status'], 'external_ref' => $n['external_ref'],
            'lines_by_kind' => $lines[(int) $n['credit_note_id']] ?? []], $r));
    });
    return share_log($pdo, $caller, 'credit_notes_issued', ['from' => $from, 'to' => $to, 'client_id' => $clientId], $doc);
}

/** os.ct-expenses/1 — approved COMPANY-CARD expenses by month, category, engagement and billable flag; no person named. Gate: billing.write or reports.view. */
function share_expenses_by_category(PDO $pdo, string $from, string $to, string $caller = 'person'): array
{
    $from = share_date($from, 'The first day');
    $to = share_date($to, 'The last day');
    share_gate($pdo, $caller, 'billing.write|reports.view', 'You may not read the expenses by category; it is Billing\'s and Finance\'s.');
    $doc = share_as($pdo, $caller, static function () use ($pdo, $from, $to): array {
        $st = $pdo->prepare("SELECT to_char(x.expense_date, 'YYYY-MM') AS month, k.key AS category_key, k.label AS category_label, x.engagement_number, x.currency, sum(x.amount) AS amount, sum(x.tax_amount) AS tax_amount, x.billable, count(*)::int AS count
                               FROM mcp_expenses x JOIN expense_categories k ON k.id = x.category_id
                              WHERE x.status = 'approved' AND x.paid_with = 'company_card' AND x.expense_date >= CAST(:f AS date) AND x.expense_date <= CAST(:t AS date)
                              GROUP BY 1, k.key, k.label, x.engagement_number, x.currency, x.billable ORDER BY 1, k.key, x.engagement_number, x.currency, x.billable DESC");
        $st->execute(['f' => $from, 't' => $to]);
        return share_document('os.ct-expenses/1', ['from' => $from, 'to' => $to], array_map(static fn (array $r): array => [
            'month' => $r['month'], 'category' => ['key' => $r['category_key'], 'label' => $r['category_label']], 'engagement_number' => $r['engagement_number'], 'currency' => trim((string) $r['currency']),
            'amount' => $r['amount'], 'tax_amount' => $r['tax_amount'], 'billable' => (bool) $r['billable'], 'count' => (int) $r['count']], $st->fetchAll()));
    });
    return share_log($pdo, $caller, 'expenses_by_category', ['from' => $from, 'to' => $to], $doc);
}

/** os.ct-wip/1 — approved, unbilled work at bill value per engagement as of a date (ct_unbilled()). Gate: invoices.draft, reports.view or billing.write (a Consultant sees no bill value). */
function share_unbilled_summary(PDO $pdo, ?string $asOf = null, ?int $engagementId = null, ?int $clientId = null, string $caller = 'person'): array
{
    $asOf = $asOf === null || $asOf === '' ? gmdate('Y-m-d') : share_date($asOf, 'The date');
    share_gate($pdo, $caller, 'invoices.draft|reports.view|billing.write', 'You may not read the unbilled work; it carries bill value.');
    $doc = share_as($pdo, $caller, static function () use ($pdo, $asOf, $engagementId, $clientId): array {
        $st = $pdo->prepare('SELECT * FROM ct_unbilled(CAST(:a AS date), CAST(:e AS bigint), CAST(:c AS bigint))');
        $st->execute(['a' => $asOf, 'e' => $engagementId, 'c' => $clientId]);
        return share_document('os.ct-wip/1', ['as_of' => $asOf], array_map(static fn (array $r): array => [
            'as_of' => $asOf, 'engagement_number' => $r['engagement_number'], 'client_name' => $r['client_name'], 'currency' => trim((string) $r['currency']), 'time_amount' => share_money($r['time_amount']),
            'expense_amount' => share_money($r['expense_amount']), 'usage_amount' => share_money($r['usage_amount']), 'milestone_amount' => share_money($r['milestone_amount']), 'total_amount' => share_money($r['total_amount'])], $st->fetchAll()));
    });
    return share_log($pdo, $caller, 'unbilled_summary', ['as_of' => $asOf, 'engagement_id' => $engagementId, 'client_id' => $clientId], $doc);
}

/** os.ct-reimbursements/1 (people: true — HR reads it) — approved, unreimbursed personal-card expenses per member id. Gate: billing.write. */
function share_reimbursements_approved(PDO $pdo, ?string $asOf = null, ?int $memberId = null, string $caller = 'person'): array
{
    $asOf = $asOf === null || $asOf === '' ? gmdate('Y-m-d') : share_date($asOf, 'The date');
    share_gate($pdo, $caller, 'billing.write', 'You may not read what is owed to people; it is Billing\'s.');
    $doc = share_as($pdo, $caller, static function () use ($pdo, $asOf, $memberId): array {
        $st = $pdo->prepare("SELECT x.member_id, x.number, x.expense_date, x.currency, x.amount, x.description FROM mcp_expenses x
                              WHERE x.status = 'approved' AND x.paid_with = 'personal' AND x.reimbursed_at IS NULL AND x.expense_date <= CAST(:a AS date) AND (CAST(:m AS bigint) IS NULL OR x.member_id = CAST(:m2 AS bigint))
                              ORDER BY x.member_id, x.expense_date, x.number");
        $st->execute(['a' => $asOf, 'm' => $memberId, 'm2' => $memberId]);
        return share_document('os.ct-reimbursements/1', ['as_of' => $asOf], array_map(static fn (array $r): array => [
            'member_id' => (int) $r['member_id'], 'expense_number' => $r['number'], 'expense_date' => $r['expense_date'], 'currency' => trim((string) $r['currency']), 'amount' => $r['amount'], 'description' => $r['description']], $st->fetchAll()));
    });
    return share_log($pdo, $caller, 'reimbursements_approved', ['as_of' => $asOf, 'member_id' => $memberId], $doc);
}

/** os.ct-engagements/1 — engagements (active unless told) with the client's short name and the manager's name; no money. Gate: any admitted member (the view shows the engagements the caller may see). */
function share_engagement_list(PDO $pdo, ?string $status = 'active', ?int $clientId = null, string $caller = 'person'): array
{
    $status = $status === null || $status === '' ? 'active' : $status;
    if (!isset(ENGAGEMENT_STATUSES[$status]) && $status !== 'all') {
        throw new DomainException('A status is draft, active, on_hold, closed, cancelled or all.');
    }
    $doc = share_as($pdo, $caller, static function () use ($pdo, $status, $clientId): array {
        $st = $pdo->prepare("SELECT e.number, e.name, e.client_name, e.kind, e.status, e.start_date, e.end_date, e.manager_name FROM mcp_engagements e
                              WHERE (:s = 'all' OR e.status = :s2) AND (CAST(:c AS bigint) IS NULL OR e.client_id = CAST(:c2 AS bigint)) ORDER BY e.number");
        $st->execute(['s' => $status, 's2' => $status, 'c' => $clientId, 'c2' => $clientId]);
        return share_document('os.ct-engagements/1', ['status' => $status], $st->fetchAll());
    });
    return share_log($pdo, $caller, 'engagement_list', ['status' => $status, 'client' => $clientId], $doc);
}

/** os.ct-time/1 — approved time entries in a period: day, person, engagement, hours, billable, bill value (only under rates.view), the client-visible note, billed. No cost, no internal note. Gate: exports.download. */
function export_time_detail(PDO $pdo, string $from, string $to, ?int $engagementId = null, string $caller = 'person'): array
{
    $from = share_date($from, 'The first day');
    $to = share_date($to, 'The last day');
    share_gate($pdo, $caller, 'exports.download', 'You may not download exports.');
    return share_as($pdo, $caller, static function () use ($pdo, $from, $to, $engagementId): array {
        $st = $pdo->prepare("SELECT t.entry_date, t.member_id, t.member_name, t.engagement_number, t.hours, t.billable, t.bill_value, t.currency, t.note, t.billed FROM mcp_time_entries t
                              WHERE t.status = 'approved' AND t.entry_date >= CAST(:f AS date) AND t.entry_date <= CAST(:t AS date) AND (CAST(:e AS bigint) IS NULL OR t.engagement_id = CAST(:e2 AS bigint))
                              ORDER BY t.entry_date, t.member_name, t.entry_id");
        $st->execute(['f' => $from, 't' => $to, 'e' => $engagementId, 'e2' => $engagementId]);
        return share_document('os.ct-time/1', ['from' => $from, 'to' => $to], array_map(static fn (array $r): array => [
            'entry_date' => $r['entry_date'], 'member_id' => (int) $r['member_id'], 'member_name' => $r['member_name'], 'engagement_number' => $r['engagement_number'], 'hours' => $r['hours'], 'billable' => (bool) $r['billable'],
            'bill_value' => $r['bill_value'], 'currency' => trim((string) $r['currency']), 'note' => $r['note'], 'billed' => (bool) $r['billed']], $st->fetchAll()));
    });
}

/** os.ct-usage/1 — usage charges (not void) whose period ends in the window: engagement, provider, kind, quantity, unit, cost (only under cost.view), billable amount, billed. Gate: exports.download. */
function export_usage_detail(PDO $pdo, string $from, string $to, ?int $engagementId = null, string $caller = 'person'): array
{
    $from = share_date($from, 'The first day');
    $to = share_date($to, 'The last day');
    share_gate($pdo, $caller, 'exports.download', 'You may not download exports.');
    return share_as($pdo, $caller, static function () use ($pdo, $from, $to, $engagementId): array {
        $st = $pdo->prepare("SELECT u.period_start, u.period_end, u.engagement_number, u.provider_name, u.kind, u.quantity, u.unit, u.cost_amount, u.billable_amount, u.billable, u.billed, u.currency, u.status, u.source FROM mcp_usage_charges u
                              WHERE u.status <> 'void' AND u.period_end >= CAST(:f AS date) AND u.period_end <= CAST(:t AS date) AND (CAST(:e AS bigint) IS NULL OR u.engagement_id = CAST(:e2 AS bigint))
                              ORDER BY u.period_start, u.engagement_number, u.usage_charge_id");
        $st->execute(['f' => $from, 't' => $to, 'e' => $engagementId, 'e2' => $engagementId]);
        return share_document('os.ct-usage/1', ['from' => $from, 'to' => $to], array_map(static fn (array $r): array => [
            'period_start' => $r['period_start'], 'period_end' => $r['period_end'], 'engagement_number' => $r['engagement_number'], 'provider' => $r['provider_name'], 'kind' => $r['kind'], 'quantity' => $r['quantity'], 'unit' => $r['unit'],
            'cost_amount' => $r['cost_amount'], 'billable_amount' => $r['billable_amount'], 'billable' => (bool) $r['billable'], 'billed' => (bool) $r['billed'], 'currency' => trim((string) $r['currency']), 'status' => $r['status'], 'source' => $r['source']], $st->fetchAll()));
    });
}
