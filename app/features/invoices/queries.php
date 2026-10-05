<?php
declare(strict_types=1);

/**
 * Invoices (slice 6): one engagement's bill for a period. Reads through mcp_invoices / mcp_invoice_lines / mcp_invoice_line_items / mcp_invoice_payments / mcp_payment_claims — the views decide who
 * sees an invoice (billing.write or sees-all, or invoices.draft on a visible engagement); this code never re-implements it. "Billed" is an invoice line item (db/012); the totals, the paid status
 * and the balance are the database's.
 */

const INVOICE_STATUSES = ['draft' => 'Draft', 'issued' => 'Issued', 'sent' => 'Sent', 'viewed' => 'Viewed', 'partially_paid' => 'Partially paid', 'paid' => 'Paid', 'void' => 'Void', 'written_off' => 'Written off'];
const INVOICE_TONES = ['draft' => 'secondary', 'issued' => 'primary', 'sent' => 'info', 'viewed' => 'info', 'partially_paid' => 'warning', 'paid' => 'success', 'void' => 'dark', 'written_off' => 'danger'];
const OPEN_INVOICE_STATUSES = ['issued', 'sent', 'viewed', 'partially_paid'];
const LINE_KINDS = ['time' => 'Time', 'expense' => 'Expense', 'usage' => 'Usage', 'milestone' => 'Milestone', 'fixed' => 'Fixed', 'adjustment' => 'Adjustment', 'discount' => 'Discount'];
const LINE_KIND_ICONS = ['time' => 'feather-clock', 'expense' => 'feather-credit-card', 'usage' => 'feather-cpu', 'milestone' => 'feather-flag', 'fixed' => 'feather-tag', 'adjustment' => 'feather-sliders', 'discount' => 'feather-percent'];
const EDITABLE_LINE_KINDS = ['fixed', 'adjustment', 'discount', 'time', 'expense', 'usage'];       // what a person may add by hand (a milestone line comes from an accepted milestone)
const PAYMENT_METHODS = ['transfer' => 'Bank transfer', 'card' => 'Card', 'cheque' => 'Cheque', 'cash' => 'Cash', 'other' => 'Other'];

/** Two money strings added, to the cent (no bcmath here; sums that matter are the database's). */
function money_add(?string $a, string $b): string
{
    return number_format(round(((float) $a + (float) $b) * 100) / 100, 2, '.', '');
}

function invoice_cast(array $i): array
{
    foreach (['invoice_id', 'client_id', 'engagement_id', 'department_id', 'issued_by', 'voided_by', 'written_off_by', 'created_by', 'reminder_count', 'open_claims', 'days_past_due'] as $k) {
        $i[$k] = ($i[$k] ?? null) === null ? null : (int) $i[$k];
    }
    $i['overdue'] = (bool) ($i['overdue'] ?? false);
    $i['currency'] = trim((string) $i['currency']);
    return $i;
}

/** The columns every read of invoices adds to the view's row: how many days past due. */
const INVOICE_SELECT = 'i.*, (current_date - i.due_date) AS days_past_due';

/** The WHERE of an invoice list. $filters: q, client_id, engagement_id, status[], overdue (bool), open (bool), issued_from, issued_to. Returns [sql, args]. */
function invoice_where(array $f): array
{
    $where = [];
    $args = [];
    if (($f['q'] ?? '') !== '') {
        $where[] = '(i.number ILIKE :q OR i.client_name ILIKE :q OR i.engagement_number ILIKE :q OR i.engagement_name ILIKE :q OR i.po_number ILIKE :q OR i.external_ref ILIKE :q)';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['q']) . '%';
    }
    if (($f['client_id'] ?? null) !== null) { $where[] = 'i.client_id = :client'; $args['client'] = (int) $f['client_id']; }
    if (($f['engagement_id'] ?? null) !== null) { $where[] = 'i.engagement_id = :eng'; $args['eng'] = (int) $f['engagement_id']; }
    if (($f['status'] ?? []) !== []) {
        $marks = [];
        foreach (array_values($f['status']) as $n => $s) { $marks[] = ':s' . $n; $args['s' . $n] = (string) $s; }
        $where[] = 'i.status IN (' . implode(', ', $marks) . ')';
    }
    if (!empty($f['open'])) { $where[] = "i.status IN ('issued', 'sent', 'viewed', 'partially_paid')"; }
    if (!empty($f['overdue'])) { $where[] = 'i.overdue'; }
    if (($f['issued_from'] ?? null) !== null) { $where[] = 'i.issue_date >= :from'; $args['from'] = $f['issued_from']; }
    if (($f['issued_to'] ?? null) !== null) { $where[] = 'i.issue_date <= :to'; $args['to'] = $f['issued_to']; }
    return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $args];
}

/** Invoices through the view, newest first. */
function find_invoices(PDO $pdo, array $filters = [], int $limit = 100, int $offset = 0): array
{
    [$w, $args] = invoice_where($filters);
    $st = $pdo->prepare('SELECT ' . INVOICE_SELECT . ' FROM mcp_invoices i' . $w . ' ORDER BY i.issue_date DESC, i.invoice_id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return array_map('invoice_cast', $st->fetchAll());
}

function count_invoices(PDO $pdo, array $filters = []): int
{
    [$w, $args] = invoice_where($filters);
    $st = $pdo->prepare('SELECT count(*) FROM mcp_invoices i' . $w);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

/** The totals footer per currency of an invoice list (void and draft invoices carry no balance): [currency => [count, total, paid, balance]]. */
function invoice_totals(PDO $pdo, array $filters = []): array
{
    [$w, $args] = invoice_where($filters);
    $st = $pdo->prepare("SELECT i.currency, count(*) AS n, sum(i.total) AS total, sum(i.amount_paid) AS paid,
                                COALESCE(sum(i.balance_due) FILTER (WHERE i.status IN ('issued', 'sent', 'viewed', 'partially_paid')), 0) AS balance
                           FROM mcp_invoices i" . $w . ' GROUP BY i.currency ORDER BY i.currency');
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[trim((string) $r['currency'])] = ['count' => (int) $r['n'], 'total' => $r['total'], 'paid' => $r['paid'], 'balance' => $r['balance']];
    }
    return $out;
}

function invoice_id_from_request(): ?int
{
    return request_integer('invoice') ?? request_integer('invoice_id') ?? request_integer('id');
}

/** One invoice through the view with its lines, backing items, payments, credits, claims, link state, documents and notes — or null when the caller may not see it. */
function find_invoice(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . INVOICE_SELECT . ' FROM mcp_invoices i WHERE i.invoice_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $i = invoice_cast($r);
    $i['lines'] = invoice_lines($pdo, $id);
    $i['payments'] = invoice_payments($pdo, $id);
    $i['credits'] = invoice_credits($pdo, $id);
    $i['claims'] = invoice_claims($pdo, $id);
    $i['link'] = invoice_link_state($pdo, $id);
    require_once dirname(__DIR__) . '/documents/write.php';
    $i['attachments'] = find_attachments($pdo, 'invoice', $id);
    $i['notes'] = find_notes($pdo, 'invoice', $id);
    $i['engagement_kind'] = (string) one_value($pdo, 'SELECT kind FROM mcp_engagements WHERE engagement_id = :e', ['e' => $i['engagement_id']]);
    $i['share_receipts'] = (bool) one_value($pdo, 'SELECT COALESCE(g.share_receipts_with_client, (SELECT share_receipts_default FROM ct_settings WHERE id = 1)) FROM mcp_engagements g WHERE g.engagement_id = :e', ['e' => $i['engagement_id']]);
    return $i;
}

/** The invoice a number names (INV-00042), if the caller may see it. */
function find_invoice_by_number(PDO $pdo, string $number): ?int
{
    $v = one_value($pdo, 'SELECT invoice_id FROM mcp_invoices WHERE number = :n', ['n' => $number]);
    return $v === null ? null : (int) $v;
}

/** An invoice's lines with the items each bills (the records behind them, through their views — a cost never comes here). */
function invoice_lines(PDO $pdo, int $invoiceId): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_invoice_lines WHERE invoice_id = :i ORDER BY sort_order, line_id');
    $st->execute(['i' => $invoiceId]);
    $lines = [];
    foreach ($st->fetchAll() as $l) {
        foreach (['line_id', 'invoice_id', 'sort_order', 'tax_rate_id', 'item_count'] as $k) {
            $l[$k] = $l[$k] === null ? null : (int) $l[$k];
        }
        $l['items'] = [];
        $lines[$l['line_id']] = $l;
    }
    if ($lines === []) {
        return [];
    }
    $it = $pdo->prepare("SELECT x.item_id, x.line_id, x.amount, x.time_entry_id, x.expense_id, x.usage_charge_id, x.milestone_id,
            t.entry_date AS t_date, t.member_name AS t_member, t.hours AS t_hours, t.note AS t_note,
            e.expense_date AS e_date, e.number AS e_number, e.description AS e_description, e.category_label AS e_category, e.has_receipt AS e_has_receipt,
            (SELECT a.attachment_id FROM mcp_attachments a WHERE a.record_type = 'expense' AND a.record_id = x.expense_id ORDER BY a.attachment_id LIMIT 1) AS e_receipt_id,
            u.number AS u_number, u.description AS u_description, u.provider_name AS u_provider, u.period_start AS u_start, u.period_end AS u_end, u.quantity AS u_quantity, u.unit AS u_unit,
            ms.name AS m_name, ms.accepted_at AS m_accepted
          FROM mcp_invoice_line_items x
          LEFT JOIN mcp_time_entries t ON t.entry_id = x.time_entry_id
          LEFT JOIN mcp_expenses e ON e.expense_id = x.expense_id
          LEFT JOIN mcp_usage_charges u ON u.usage_charge_id = x.usage_charge_id
          LEFT JOIN mcp_milestones ms ON ms.milestone_id = x.milestone_id
         WHERE x.invoice_id = :i ORDER BY COALESCE(t.entry_date, e.expense_date, u.period_end, ms.accepted_at::date), x.item_id");
    $it->execute(['i' => $invoiceId]);
    foreach ($it->fetchAll() as $x) {
        foreach (['item_id', 'line_id', 'time_entry_id', 'expense_id', 'usage_charge_id', 'milestone_id', 'e_receipt_id'] as $k) {
            $x[$k] = $x[$k] === null ? null : (int) $x[$k];
        }
        $x['e_has_receipt'] = (bool) $x['e_has_receipt'];
        if (isset($lines[$x['line_id']])) {
            $lines[$x['line_id']]['items'][] = $x;
        }
    }
    return array_values($lines);
}

function invoice_payments(PDO $pdo, int $invoiceId): array
{
    $st = $pdo->prepare('SELECT p.*, m.display_name AS recorded_by_name FROM mcp_invoice_payments p LEFT JOIN members m ON m.id = p.recorded_by WHERE p.invoice_id = :i ORDER BY p.paid_on, p.payment_id');
    $st->execute(['i' => $invoiceId]);
    return array_map('payment_cast', $st->fetchAll());
}

function payment_cast(array $p): array
{
    foreach (['payment_id', 'invoice_id', 'recorded_by', 'voided_by'] as $k) {
        $p[$k] = ($p[$k] ?? null) === null ? null : (int) $p[$k];
    }
    $p['currency'] = trim((string) $p['currency']);
    return $p;
}

/** Credit applications on an invoice: the credit note, its number, how much. */
function invoice_credits(PDO $pdo, int $invoiceId): array
{
    $st = $pdo->prepare('SELECT a.credit_note_id, c.number, a.amount, a.applied_at, c.currency FROM mcp_credit_applications a JOIN mcp_credit_notes c ON c.credit_note_id = a.credit_note_id WHERE a.invoice_id = :i ORDER BY a.applied_at');
    $st->execute(['i' => $invoiceId]);
    return array_map(static function (array $a): array {
        $a['credit_note_id'] = (int) $a['credit_note_id'];
        $a['currency'] = trim((string) $a['currency']);
        return $a;
    }, $st->fetchAll());
}

function invoice_claims(PDO $pdo, int $invoiceId): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_payment_claims WHERE invoice_id = :i ORDER BY claim_id DESC');
    $st->execute(['i' => $invoiceId]);
    return array_map('claim_cast', $st->fetchAll());
}

function claim_cast(array $k): array
{
    foreach (['claim_id', 'invoice_id', 'payment_id', 'decided_by'] as $x) {
        $k[$x] = ($k[$x] ?? null) === null ? null : (int) $k[$x];
    }
    return $k;
}

/** The link's state, never the token: live, rotated or expired; the views counted and when it was last opened; or null when none was made yet. */
function invoice_link_state(PDO $pdo, int $invoiceId): ?array
{
    $st = $pdo->prepare('SELECT id, expires_at, rotated_at, last_used_at, view_count, created_at, (rotated_at IS NULL AND (expires_at IS NULL OR expires_at > now())) AS live, (rotated_at IS NULL AND expires_at IS NOT NULL AND expires_at <= now()) AS expired
                           FROM invoice_links_secure WHERE invoice_id = :i ORDER BY id DESC LIMIT 1');
    $st->execute(['i' => $invoiceId]);
    $l = $st->fetch();
    if ($l === false) {
        return null;
    }
    return ['state' => $l['live'] ? 'live' : ($l['expired'] ? 'expired' : 'rotated'), 'expires_at' => $l['expires_at'], 'last_used_at' => $l['last_used_at'], 'view_count' => (int) $l['view_count'], 'created_at' => $l['created_at'],
            'links_made' => (int) one_value($pdo, 'SELECT count(*) FROM invoice_links_secure WHERE invoice_id = :i', ['i' => $invoiceId])];
}

function invoice_timeline(PDO $pdo, int $invoiceId, int $limit = 100): array
{
    return find_record_activity($pdo, 'invoice', $invoiceId, $limit);
}

/** The live tax rates for a line's picker: [{tax_rate_id, name, rate, is_default}]. */
function tax_rates_for_pick(PDO $pdo): array
{
    return array_map(static function (array $t): array {
        $t['tax_rate_id'] = (int) $t['tax_rate_id'];
        $t['is_default'] = (bool) $t['is_default'];
        return $t;
    }, $pdo->query('SELECT tax_rate_id, name, rate, is_default FROM mcp_tax_rates WHERE archived_at IS NULL ORDER BY is_default DESC, name')->fetchAll());
}

/** The engagements a person may draft an invoice for (invoices.draft and manage — or Billing): live ones through the view, as pick rows. */
function draftable_engagements(PDO $pdo): array
{
    return array_map(static function (array $e): array {
        $e['engagement_id'] = (int) $e['engagement_id'];
        $e['currency'] = trim((string) $e['currency']);
        return $e;
    }, $pdo->query("SELECT engagement_id, number, name, client_name, currency, kind, po_number, status FROM mcp_engagements WHERE status IN ('active', 'on_hold', 'closed') AND (i_manage OR (SELECT ct_has_right('billing.write'))) ORDER BY number")->fetchAll());
}

// ---- WIP and what is due to draft ---------------------------------------------------------------------------------------------------------------------------------

/** ct_unbilled(): the approved, billable, unbilled work per engagement. */
function unbilled(PDO $pdo, ?string $asOf = null, ?int $engagementId = null, ?int $clientId = null): array
{
    $st = $pdo->prepare('SELECT * FROM ct_unbilled(COALESCE(CAST(:a AS date), current_date), CAST(:e AS bigint), CAST(:c AS bigint))');
    $st->execute(['a' => $asOf, 'e' => $engagementId, 'c' => $clientId]);
    return array_map(static function (array $u): array {
        foreach (['engagement_id', 'client_id'] as $k) { $u[$k] = (int) $u[$k]; }
        $u['currency'] = trim((string) $u['currency']);
        return $u;
    }, $st->fetchAll());
}

/** ct_unbilled_items(): the items behind one engagement's WIP. */
function unbilled_items(PDO $pdo, int $engagementId, ?string $asOf = null): array
{
    $st = $pdo->prepare('SELECT * FROM ct_unbilled_items(:e, COALESCE(CAST(:a AS date), current_date))');
    $st->execute(['e' => $engagementId, 'a' => $asOf]);
    return array_map(static function (array $u): array {
        $u['item_id'] = (int) $u['item_id'];
        $u['member_id'] = $u['member_id'] === null ? null : (int) $u['member_id'];
        $u['no_rate'] = (bool) $u['no_rate'];
        return $u;
    }, $st->fetchAll());
}

/**
 * What awaits an invoice: [engagements whose billing date passed with WIP, accepted milestones not invoiced, engagements with an open draft]. The Clerk's list, the due-to-draft screen's.
 * `next_billing_date` is the engagement's; on_milestone and manual cadences never come due by date.
 */
function invoices_due_to_draft(PDO $pdo, ?string $asOf = null): array
{
    $asOf ??= date('Y-m-d');
    $wip = [];
    foreach (unbilled($pdo, $asOf) as $u) {
        $wip[$u['engagement_id']] = $u;
    }
    $due = [];
    $st = $pdo->prepare("SELECT engagement_id, number, name, client_name, currency, billing_cadence, next_billing_date FROM mcp_engagements
                          WHERE status = 'active' AND next_billing_date IS NOT NULL AND next_billing_date <= :a AND billing_cadence NOT IN ('manual', 'on_milestone') AND (i_manage OR (SELECT ct_has_right('billing.write')))
                          ORDER BY next_billing_date, number");
    $st->execute(['a' => $asOf]);
    foreach ($st->fetchAll() as $e) {
        $e['engagement_id'] = (int) $e['engagement_id'];
        if (!isset($wip[$e['engagement_id']]) || (float) $wip[$e['engagement_id']]['total_amount'] <= 0) {
            continue;
        }
        $e['currency'] = trim((string) $e['currency']);
        $e['wip'] = $wip[$e['engagement_id']];
        $due[] = $e;
    }
    $ms = $pdo->prepare("SELECT m.milestone_id, m.engagement_id, m.name, m.amount, m.accepted_at, g.number AS engagement_number, g.name AS engagement_name, g.client_name, g.currency
                           FROM mcp_milestones m JOIN mcp_engagements g ON g.engagement_id = m.engagement_id
                          WHERE m.status = 'accepted' AND m.amount > 0 AND NOT m.billed AND (g.i_manage OR (SELECT ct_has_right('billing.write'))) ORDER BY m.accepted_at, m.milestone_id");
    $ms->execute();
    $milestones = array_map(static function (array $m): array {
        $m['milestone_id'] = (int) $m['milestone_id'];
        $m['engagement_id'] = (int) $m['engagement_id'];
        $m['currency'] = trim((string) $m['currency']);
        return $m;
    }, $ms->fetchAll());
    $dr = $pdo->query("SELECT i.invoice_id, i.engagement_id, i.engagement_number, i.engagement_name, i.client_name, i.total, i.currency, i.created_at, i.period_start, i.period_end FROM mcp_invoices i WHERE i.status = 'draft' ORDER BY i.created_at");
    $drafts = array_map(static function (array $d): array {
        $d['invoice_id'] = (int) $d['invoice_id'];
        $d['engagement_id'] = (int) $d['engagement_id'];
        $d['currency'] = trim((string) $d['currency']);
        return $d;
    }, $dr->fetchAll());
    return ['due' => $due, 'milestones' => $milestones, 'drafts' => $drafts];
}

/** ct_ar_aging(): who owes us, in the five buckets (billing.write or reports.view — the function answers nothing to anyone else). */
function ar_aging(PDO $pdo, ?string $asOf = null): array
{
    $st = $pdo->prepare('SELECT * FROM ct_ar_aging(COALESCE(CAST(:a AS date), current_date))');
    $st->execute(['a' => $asOf]);
    return array_map(static function (array $a): array {
        $a['client_id'] = (int) $a['client_id'];
        $a['currency'] = trim((string) $a['currency']);
        $a['open_invoices'] = (int) $a['open_invoices'];
        foreach (['current_due', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'] as $k) { $a[$k] = $a[$k] ?? '0.00'; }
        return $a;
    }, $st->fetchAll());
}

/** ct_client_statement(): invoices, credit notes, payments and write-offs with the running balance per currency. */
function client_statement(PDO $pdo, int $clientId, ?string $from = null, ?string $to = null): array
{
    $st = $pdo->prepare('SELECT * FROM ct_client_statement(:c, CAST(:f AS date), COALESCE(CAST(:t AS date), current_date))');
    $st->execute(['c' => $clientId, 'f' => $from, 't' => $to]);
    return array_map(static function (array $r): array {
        $r['currency'] = trim((string) $r['currency']);
        return $r;
    }, $st->fetchAll());
}

/** A client's open invoices (for its page): number, engagement, due, balance, status. */
function client_open_invoices(PDO $pdo, int $clientId, int $limit = 20): array
{
    return find_invoices($pdo, ['client_id' => $clientId, 'open' => true], $limit);
}

/** An engagement's invoices (for its Invoices tab). */
function engagement_invoices(PDO $pdo, int $engagementId, int $limit = 50): array
{
    return find_invoices($pdo, ['engagement_id' => $engagementId], $limit);
}

/** Billing's figures for the home: WIP, drafts to issue, due to draft, who owes us (per currency), claims waiting. Null for someone who is none of Billing, a drafter or a reporter. */
function billing_summary(PDO $pdo): array
{
    $wip = [];
    foreach (unbilled($pdo, null) as $u) {
        $wip[$u['currency']] = money_add($wip[$u['currency']] ?? '0', (string) $u['total_amount']);
    }
    $drafts = find_invoices($pdo, ['status' => ['draft']], 50);
    $owed = [];
    $overdue = 0;
    foreach (find_invoices($pdo, ['open' => true], 500) as $i) {
        $owed[$i['currency']] = money_add($owed[$i['currency']] ?? '0', (string) $i['balance_due']);
        $overdue += $i['overdue'] ? 1 : 0;
    }
    $due = invoices_due_to_draft($pdo);
    $claims = has_right('billing.write') ? count(find_claims($pdo, ['status' => 'submitted'])) : 0;
    $aging = has_right('receivables.view') ? ar_aging($pdo) : [];
    $buckets = ['current_due' => [], 'd1_30' => [], 'd31_60' => [], 'd61_90' => [], 'd90_plus' => []];
    foreach ($aging as $a) {
        foreach (array_keys($buckets) as $b) {
            $buckets[$b][$a['currency']] = money_add($buckets[$b][$a['currency']] ?? '0', (string) $a[$b]);
        }
    }
    return ['wip' => $wip, 'drafts' => array_slice($drafts, 0, 8), 'draft_count' => count($drafts), 'owed' => $owed, 'overdue' => $overdue, 'due_to_draft' => count($due['due']) + count($due['milestones']),
            'due' => array_slice($due['due'], 0, 5), 'milestones_waiting' => count($due['milestones']), 'claims' => $claims, 'aging' => $buckets];
}
