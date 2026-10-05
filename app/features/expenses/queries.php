<?php
declare(strict_types=1);

/**
 * Expenses and receipts (slice 4): a person's spend on an engagement. Reads through mcp_expenses / mcp_attachments / mcp_notes — the view decides who sees whose
 * expenses (one's own; a manager's reach with expenses.approve; Billing, Finance and the admin all); this code never re-implements it. The receipt rule, the markup,
 * the currency and every status move are the database's (db/010).
 */

const EXPENSE_STATUSES = ['draft' => 'Draft', 'submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected'];
const EXPENSE_TONES = ['draft' => 'secondary', 'submitted' => 'info', 'approved' => 'success', 'rejected' => 'warning'];
const PAID_WITH = ['personal' => 'Personal card or cash', 'company_card' => 'Company card'];

function expense_cast(array $x): array
{
    foreach (['expense_id', 'member_id', 'engagement_id', 'category_id', 'approved_by', 'rejected_by', 'department_id', 'receipt_id'] as $k) {
        $x[$k] = ($x[$k] ?? null) === null ? null : (int) $x[$k];
    }
    foreach (['reimbursable', 'billable', 'has_receipt', 'billed'] as $k) {
        $x[$k] = (bool) ($x[$k] ?? false);
    }
    $x['receipt_needed'] = !$x['has_receipt'] && (float) $x['amount'] >= (float) $x['receipt_threshold'];     // presentation only: the submit verb is the rule
    return $x;
}

/** SELECT list and the first receipt (its id and mime, for the thumbnail) shared by every read of expenses. */
const EXPENSE_SELECT = "x.*, (SELECT a.attachment_id FROM mcp_attachments a WHERE a.record_type = 'expense' AND a.record_id = x.expense_id ORDER BY a.attachment_id LIMIT 1) AS receipt_id,
                        (SELECT a.mime_type FROM mcp_attachments a WHERE a.record_type = 'expense' AND a.record_id = x.expense_id ORDER BY a.attachment_id LIMIT 1) AS receipt_mime";

/** Expenses through the view, newest first. $filters: member_id, engagement_id, status[], from, to, missing_receipt (bool), q. */
function find_expenses(PDO $pdo, array $filters = [], int $limit = 100, int $offset = 0): array
{
    $where = [];
    $args = [];
    if (($filters['member_id'] ?? null) !== null) { $where[] = 'x.member_id = :member'; $args['member'] = (int) $filters['member_id']; }
    if (($filters['engagement_id'] ?? null) !== null) { $where[] = 'x.engagement_id = :eng'; $args['eng'] = (int) $filters['engagement_id']; }
    if (($filters['status'] ?? []) !== []) {
        $marks = [];
        foreach (array_values($filters['status']) as $i => $s) { $marks[] = ':s' . $i; $args['s' . $i] = (string) $s; }
        $where[] = 'x.status IN (' . implode(', ', $marks) . ')';
    }
    if (($filters['from'] ?? null) !== null) { $where[] = 'x.expense_date >= :from'; $args['from'] = $filters['from']; }
    if (($filters['to'] ?? null) !== null) { $where[] = 'x.expense_date <= :to'; $args['to'] = $filters['to']; }
    if (!empty($filters['missing_receipt'])) { $where[] = 'NOT x.has_receipt AND x.amount >= x.receipt_threshold'; }
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(x.number ILIKE :q OR x.description ILIKE :q OR x.vendor_name ILIKE :q)';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }
    $st = $pdo->prepare('SELECT ' . EXPENSE_SELECT . ' FROM mcp_expenses x' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        . ' ORDER BY x.expense_date DESC, x.expense_id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return array_map('expense_cast', $st->fetchAll());
}

/** One expense through the view with its receipts and notes, or null when the caller may not see it. */
function find_expense(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . EXPENSE_SELECT . ' FROM mcp_expenses x WHERE x.expense_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $x = expense_cast($r);
    require_once dirname(__DIR__) . '/documents/write.php';
    $x['receipts'] = find_attachments($pdo, 'expense', $id);
    $x['notes'] = find_notes($pdo, 'expense', $id);
    return $x;
}

/** The expense a request names, or null. */
function expense_id_from_request(): ?int
{
    return request_integer('expense') ?? request_integer('expense_id') ?? request_integer('id');
}

/** Submitted expenses on the engagements the approver manages, oldest first (never their own). $filters: engagement_id, missing_receipt. */
function expenses_pending(PDO $pdo, int $approverId, array $filters = []): array
{
    $where = ["x.status = 'submitted'", 'x.member_id <> :me', 'x.engagement_id IN (SELECT ct_managed_engagement_ids())'];
    $args = ['me' => $approverId];
    if (($filters['engagement_id'] ?? null) !== null) { $where[] = 'x.engagement_id = :eng'; $args['eng'] = (int) $filters['engagement_id']; }
    if (!empty($filters['missing_receipt'])) { $where[] = 'NOT x.has_receipt AND x.amount >= x.receipt_threshold'; }
    $st = $pdo->prepare('SELECT ' . EXPENSE_SELECT . ' FROM mcp_expenses x WHERE ' . implode(' AND ', $where) . ' ORDER BY x.submitted_at, x.expense_id');
    $st->execute($args);
    return array_map('expense_cast', $st->fetchAll());
}

/** What is owed: approved personal-card expenses not yet reimbursed, grouped per person with the total per currency. [{member_id, member_name, expenses[], totals{currency => amount}}] */
function reimbursements_due(PDO $pdo, ?int $memberId = null): array
{
    $st = $pdo->prepare("SELECT " . EXPENSE_SELECT . " FROM mcp_expenses x WHERE x.status = 'approved' AND x.reimbursable AND x.reimbursed_at IS NULL" . ($memberId === null ? '' : ' AND x.member_id = :m') . ' ORDER BY x.member_name, x.expense_date, x.expense_id');
    $st->execute($memberId === null ? [] : ['m' => $memberId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $x = expense_cast($r);
        $m = $x['member_id'];
        $out[$m] ??= ['member_id' => $m, 'member_name' => $x['member_name'], 'expenses' => [], 'totals' => []];
        $out[$m]['expenses'][] = $x;
        $out[$m]['totals'][$x['currency']] = number_format((float) ($out[$m]['totals'][$x['currency']] ?? 0) + (float) $x['amount'], 2, '.', '');
    }
    return array_values($out);
}

/** Approved company-card spend by month and category (and currency): [{month, category_id, category, currency, amount, tax_amount, count}]. */
function company_card_spend(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare("SELECT to_char(date_trunc('month', x.expense_date), 'YYYY-MM') AS month, x.category_id, x.category_label AS category, x.currency, sum(x.amount) AS amount, sum(x.tax_amount) AS tax_amount, count(*) AS count
                           FROM mcp_expenses x WHERE x.status = 'approved' AND x.paid_with = 'company_card' AND x.expense_date >= :f AND x.expense_date <= :t
                          GROUP BY 1, x.category_id, x.category_label, x.currency ORDER BY 1, x.category_label, x.currency");
    $st->execute(['f' => $from, 't' => $to]);
    return array_map(static function (array $r): array {
        $r['category_id'] = (int) $r['category_id'];
        $r['count'] = (int) $r['count'];
        $r['amount'] = number_format((float) $r['amount'], 2, '.', '');
        $r['tax_amount'] = number_format((float) $r['tax_amount'], 2, '.', '');
        return $r;
    }, $st->fetchAll());
}

/** A record's trail, without the screen views. */
function expense_timeline(PDO $pdo, int $id, int $limit = 100): array
{
    return array_values(array_filter(find_record_activity($pdo, 'expense', $id, $limit + 20), static fn (array $r): bool => $r['action'] !== 'screen.view'));
}

/** The managers to tell when an expense is submitted: the engagement's manager (never the person). */
function expense_managers(PDO $pdo, int $expenseId, int $exceptMember): array
{
    $st = $pdo->prepare('SELECT g.manager_member_id FROM expenses x JOIN engagements g ON g.id = x.engagement_id WHERE x.id = :x AND g.manager_member_id IS NOT NULL AND g.manager_member_id <> :m');
    $st->execute(['x' => $expenseId, 'm' => $exceptMember]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** The engagements a person may record an expense on for a date (ct_can_log_on), as the view shows them, with the engagement's policy markup and receipt rule. */
function expense_engagements(PDO $pdo, int $memberId, string $date): array
{
    $st = $pdo->prepare("SELECT e.engagement_id, e.number, e.name, e.client_name, e.currency, (e.expense_policy->>'markup_pct') AS policy_markup, (e.expense_policy->>'requires_receipt_above') AS policy_receipt,
                                COALESCE(e.share_receipts_with_client, (SELECT share_receipts_default FROM ct_settings WHERE id = 1)) AS share_receipts
                           FROM mcp_engagements e WHERE ct_can_log_on(e.engagement_id, :m, :d) ORDER BY e.number");
    $st->execute(['m' => $memberId, 'd' => $date]);
    return array_map(static function (array $e): array {
        $e['engagement_id'] = (int) $e['engagement_id'];
        $e['share_receipts'] = (bool) $e['share_receipts'];
        return $e;
    }, $st->fetchAll());
}

/** The live expense categories for the form: [{category_id, label, receipt_required_above, default_markup_pct, billable_default}] */
function expense_categories_for_pick(PDO $pdo): array
{
    return array_map(static function (array $c): array {
        $c['category_id'] = (int) $c['category_id'];
        $c['billable_default'] = (bool) $c['billable_default'];
        return $c;
    }, $pdo->query('SELECT category_id, label, receipt_required_above, default_markup_pct, billable_default FROM mcp_expense_categories WHERE archived_at IS NULL ORDER BY sort_order, label')->fetchAll());
}

/** The markup the database will give a new expense on this category and engagement: the category's, else the engagement policy's, else the settings'. */
function policy_markup(PDO $pdo, int $categoryId, int $engagementId): string
{
    return number_format((float) one_value($pdo, "SELECT COALESCE((SELECT default_markup_pct FROM expense_categories WHERE id = :c), (SELECT (expense_policy->>'markup_pct')::numeric FROM engagements WHERE id = :e),
                                                                (SELECT default_markup_pct FROM ct_settings WHERE id = 1), 0)", ['c' => $categoryId, 'e' => $engagementId]), 2, '.', '');
}

/** The caller's home region: counts by status, what awaits a receipt, what they are owed, the latest few. */
function my_expenses_summary(PDO $pdo, int $memberId): array
{
    $mine = find_expenses($pdo, ['member_id' => $memberId], 200);
    $counts = ['draft' => 0, 'submitted' => 0, 'approved' => 0, 'rejected' => 0];
    $needs = 0;
    foreach ($mine as $x) {
        $counts[$x['status']]++;
        if (in_array($x['status'], ['draft', 'rejected'], true) && $x['receipt_needed']) {
            $needs++;
        }
    }
    $due = reimbursements_due($pdo, $memberId);
    return ['counts' => $counts, 'needs_receipt' => $needs, 'owed' => $due === [] ? [] : $due[0]['totals'], 'owed_count' => $due === [] ? 0 : count($due[0]['expenses']), 'latest' => array_slice($mine, 0, 3)];
}
