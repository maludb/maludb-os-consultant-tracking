<?php
declare(strict_types=1);

/**
 * Reports (slice 7): the firm's nine reports, each ONE function over a SQL function (db/014, db/017) or the expenses view. The function is the referee —
 * who sees which engagement, whether a cost comes back — so nothing here widens, and no figure is computed here beyond a totals row (a sum of the rows the
 * function answered, per currency). Every function answers ['columns' => [...], 'rows' => [...], 'totals' => [...]] (the page, the JSON answer and the CSV share it).
 * A column: key, label, type (text hours money pct int date budget), link? (the row key that holds its URL), status? (utilization|realization|margin).
 * A row carries its own `currency` when it has a money column; a totals row carries `currency` too (one per currency).
 */

const REPORT_MAX_MONTHS = 24;
const REPORT_PRESETS = ['this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'ytd' => 'Year to date'];
const REVENUE_GROUPS = ['client' => 'Client', 'engagement' => 'Engagement', 'kind' => 'Kind of line', 'practice' => 'Practice', 'month' => 'Month'];
const EXPENSE_REPORT_STATUSES = ['approved' => 'Approved', 'submitted' => 'Submitted', 'draft' => 'Draft', 'rejected' => 'Rejected', 'all' => 'Every status'];
const BUDGET_STATUSES = ['active' => 'Active', 'draft' => 'Draft', 'on_hold' => 'On hold', 'completed' => 'Completed', 'all' => 'Every status'];

/** The reports as the index and the router know them: key => [title, one line, the right that opens it, the filters it takes, whether it takes a period]. */
function report_defs(): array
{
    return [
        'utilization'     => ['title' => 'Utilization', 'blurb' => 'Billable hours against capacity, per person and practice.', 'right' => 'reports.view|reports.view_own', 'period' => true, 'filters' => ['member', 'department', 'by']],
        'realization'     => ['title' => 'Realization', 'blurb' => 'Of the hours approved, how many were billed, written off or still wait.', 'right' => 'reports.view', 'period' => true, 'filters' => ['engagement', 'client']],
        'wip'             => ['title' => 'Unbilled work', 'blurb' => 'What is approved and not yet on an invoice, per engagement.', 'right' => 'reports.view', 'period' => false, 'filters' => ['as_of', 'engagement', 'client']],
        'revenue'         => ['title' => 'Revenue', 'blurb' => 'What was invoiced in a period, by client, engagement, kind, practice or month.', 'right' => 'revenue.view', 'period' => true, 'filters' => ['by']],
        'margin'          => ['title' => 'Margin', 'blurb' => 'Bill value less cost, per engagement.', 'right' => 'margin.view', 'period' => true, 'filters' => ['engagement']],
        'budget-burn'     => ['title' => 'Budget burn', 'blurb' => 'Hours, fee, not-to-exceed and AI against budget, with the pace.', 'right' => 'reports.view', 'period' => false, 'filters' => ['status', 'department', 'manager']],
        'ai-passthrough'  => ['title' => 'AI pass-through', 'blurb' => 'What the clients\' AI and hosting cost and what was billed for it.', 'right' => 'reports.view', 'period' => true, 'filters' => ['engagement']],
        'expenses'        => ['title' => 'Expense report', 'blurb' => 'Expenses by person, engagement and category, with what is reimbursable and reimbursed.', 'right' => 'reports.view', 'period' => true, 'filters' => ['member', 'engagement', 'category', 'status']],
        'missing-time'    => ['title' => 'Missing time', 'blurb' => 'Who has not submitted a week, and which weekdays are empty.', 'right' => 'reports.view', 'period' => false, 'filters' => ['week']],
    ];
}

/** The cards the caller may open: [{key, title, blurb, url}] — the ones whose right they hold; the aging is slice 6's, listed last. */
function reports_for(PDO $pdo, int $memberId): array
{
    $out = [];
    foreach (report_defs() as $key => $d) {
        if (nav_has_right($d['right'])) {
            $out[] = ['key' => $key, 'title' => $d['title'], 'blurb' => $d['blurb'], 'url' => '/reports/' . $key];
        }
    }
    if (has_right('receivables.view')) {
        $out[] = ['key' => 'ar-aging', 'title' => 'Who owes us', 'blurb' => 'Open invoices by client in the five age buckets.', 'url' => '/reports/ar-aging'];
    }
    return $out;
}

/** A period from a query: ?preset= (this_month, last_month, this_quarter, ytd) or ?from=&to= (dates); this month by default; refused in words when malformed, backwards or over 24 months. */
function report_period(array $query, ?DateTimeImmutable $today = null): array
{
    $today ??= new DateTimeImmutable('today');
    $preset = (string) ($query['preset'] ?? '');
    $from = (string) ($query['from'] ?? '');
    $to = (string) ($query['to'] ?? '');
    if ($from === '' && $to === '') {
        $preset = isset(REPORT_PRESETS[$preset]) ? $preset : 'this_month';
        $y = (int) $today->format('Y');
        $m = (int) $today->format('n');
        switch ($preset) {
            case 'last_month':
                $f = $today->modify('first day of last month');
                return ['from' => $f->format('Y-m-d'), 'to' => $f->modify('last day of this month')->format('Y-m-d'), 'preset' => $preset];
            case 'this_quarter':
                $qm = intdiv($m - 1, 3) * 3 + 1;
                $f = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $qm));
                return ['from' => $f->format('Y-m-d'), 'to' => $f->modify('+2 months')->modify('last day of this month')->format('Y-m-d'), 'preset' => $preset];
            case 'ytd':
                return ['from' => sprintf('%04d-01-01', $y), 'to' => $today->format('Y-m-d'), 'preset' => $preset];
            default:
                $f = $today->modify('first day of this month');
                return ['from' => $f->format('Y-m-d'), 'to' => $f->modify('last day of this month')->format('Y-m-d'), 'preset' => 'this_month'];
        }
    }
    $df = $from === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $from);
    $dt = $to === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $to);
    if (($df === false || $df === null || $df->format('Y-m-d') !== $from) && $from !== '') {
        refuse(422, 'The first day of the period is a date (YYYY-MM-DD).');
    }
    if (($dt === false || $dt === null || $dt->format('Y-m-d') !== $to) && $to !== '') {
        refuse(422, 'The last day of the period is a date (YYYY-MM-DD).');
    }
    $df ??= $dt->modify('first day of this month');
    $dt ??= $today > $df ? $today : $df;
    if ($dt < $df) {
        refuse(422, 'The period ends before it begins.');
    }
    if ($dt > $df->modify('+' . REPORT_MAX_MONTHS . ' months')) {
        refuse(422, 'A report covers at most ' . REPORT_MAX_MONTHS . ' months; choose a shorter period.');
    }
    return ['from' => $df->format('Y-m-d'), 'to' => $dt->format('Y-m-d'), 'preset' => ''];
}

/** The filters of a report as the page parses them (only the ones that report takes), the period included. */
function report_filters(string $report, array $query): array
{
    $defs = report_defs();
    $d = $defs[$report] ?? null;
    if ($d === null) {
        return [];
    }
    $f = [];
    if ($d['period']) {
        $f += report_period($query);
    }
    $id = static function (string $k) use ($query): ?int {
        $v = $query[$k] ?? '';
        return $v === '' || !ctype_digit((string) $v) ? null : (int) $v;
    };
    $date = static function (string $k) use ($query): ?string {
        $v = (string) ($query[$k] ?? '');
        if ($v === '') {
            return null;
        }
        $x = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $x !== false && $x->format('Y-m-d') === $v ? $v : refuse(422, 'That date is not a date (YYYY-MM-DD).');
    };
    foreach ($d['filters'] as $name) {
        switch ($name) {
            case 'member': case 'engagement': case 'client': case 'department': case 'manager': case 'category':
                $f[$name] = $id($name);
                break;
            case 'as_of':
                $f['as_of'] = $date('as_of') ?? date('Y-m-d');
                break;
            case 'week':
                $f['week'] = $date('week');
                break;
            case 'by':
                $by = (string) ($query['by'] ?? '');
                $f['by'] = $report === 'utilization' ? ($by === 'practice' ? 'practice' : '') : (isset(REVENUE_GROUPS[$by]) ? $by : 'client');
                break;
            case 'status':
                $st = (string) ($query['status'] ?? '');
                $f['status'] = $report === 'expenses' ? (isset(EXPENSE_REPORT_STATUSES[$st]) ? $st : 'approved') : (isset(BUDGET_STATUSES[$st]) ? $st : 'active');
                break;
        }
    }
    return $f;
}

/** The pick lists a report's filter row offers — each through a view, so only what the caller sees. */
function report_picklists(PDO $pdo): array
{
    return [
        'engagements' => $pdo->query('SELECT engagement_id, number, name, client_id FROM mcp_engagements ORDER BY number')->fetchAll(),
        'clients' => $pdo->query('SELECT client_id, name FROM mcp_clients WHERE archived_at IS NULL ORDER BY name')->fetchAll(),
        'members' => $pdo->query("SELECT member_id, display_name FROM mcp_members WHERE member_kind = 'human' AND capability IS NOT NULL ORDER BY display_name")->fetchAll(),
        'departments' => $pdo->query('SELECT department_id, name FROM mcp_departments WHERE archived_at IS NULL ORDER BY name')->fetchAll(),
        'categories' => $pdo->query('SELECT category_id, label FROM mcp_expense_categories WHERE archived_at IS NULL ORDER BY sort_order, label')->fetchAll(),
    ];
}

// ---- helpers ---------------------------------------------------------------------------------------------------------------------------------------

/** The sum of a column over rows, to $dec decimals ('' when no row has it). */
function report_sum(array $rows, string $key, int $dec = 2): string
{
    $s = 0.0;
    foreach ($rows as $r) {
        $s += (float) ($r[$key] ?? 0);
    }
    return number_format(round($s, $dec), $dec, '.', '');
}

/** A percentage of two sums, one decimal, null when the base is nothing — the function's own formula (100 * part / base) on the totals row. */
function report_pct(string $part, string $base): ?string
{
    return (float) $base > 0 ? number_format(round(100 * (float) $part / (float) $base, 1), 1, '.', '') : null;
}

/** Rows grouped by currency, in first-seen order. */
function report_by_currency(array $rows): array
{
    $g = [];
    foreach ($rows as $r) {
        $g[$r['currency']][] = $r;
    }
    return $g;
}

/** A map engagement_id => [currency, client_id, manager, status…] through the view (what the caller sees). */
function report_engagement_map(PDO $pdo): array
{
    $m = [];
    foreach ($pdo->query('SELECT engagement_id, number, name, client_id, client_name, currency, status, department_id, manager_member_id FROM mcp_engagements')->fetchAll() as $e) {
        $e['currency'] = trim((string) $e['currency']);
        $m[(int) $e['engagement_id']] = $e;
    }
    return $m;
}

function report_engagement_url(int $id): string
{
    return '/engagements/' . $id;
}

// ---- the nine ---------------------------------------------------------------------------------------------------------------------------------------

/** Utilization: ct_utilization(from, to, member, department) — each person's billable hours against capacity (own row under reports.view_own). `by` = practice adds a subtotal per practice. */
function report_utilization(PDO $pdo, array $f): array
{
    $st = $pdo->prepare('SELECT * FROM ct_utilization(CAST(:f AS date), CAST(:t AS date), CAST(:m AS bigint), CAST(:d AS bigint))');
    $st->execute(['f' => $f['from'], 't' => $f['to'], 'm' => $f['member'] ?? null, 'd' => $f['department'] ?? null]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['member_id'] = (int) $r['member_id'];
        $r['department_name'] = $r['department_name'] ?? '—';
    }
    unset($r);
    $total = static function (array $rs): array {
        $cap = report_sum($rs, 'capacity_hours');
        $bill = report_sum($rs, 'billable_hours');
        return ['capacity_hours' => $cap, 'logged_hours' => report_sum($rs, 'logged_hours'), 'billable_hours' => $bill, 'approved_billable_hours' => report_sum($rs, 'approved_billable_hours'), 'utilization_pct' => report_pct($bill, $cap)];
    };
    if (($f['by'] ?? '') === 'practice') {
        usort($rows, static fn (array $a, array $b): int => [$a['department_name'], $a['member_name']] <=> [$b['department_name'], $b['member_name']]);
        $out = [];
        foreach (array_values(array_unique(array_column($rows, 'department_name'))) as $dept) {
            $in = array_values(array_filter($rows, static fn (array $r): bool => $r['department_name'] === $dept));
            array_push($out, ...$in);
            $out[] = ['is_subtotal' => true, 'member_name' => 'Subtotal', 'department_name' => $dept] + $total($in);
        }
        $rows = $out;
        $plain = array_values(array_filter($rows, static fn (array $r): bool => empty($r['is_subtotal'])));
    } else {
        $plain = $rows;
    }
    return [
        'columns' => [['key' => 'member_name', 'label' => 'Person', 'type' => 'text'], ['key' => 'department_name', 'label' => 'Practice', 'type' => 'text'],
                      ['key' => 'capacity_hours', 'label' => 'Capacity', 'type' => 'hours'], ['key' => 'logged_hours', 'label' => 'Logged', 'type' => 'hours'],
                      ['key' => 'billable_hours', 'label' => 'Billable', 'type' => 'hours'], ['key' => 'approved_billable_hours', 'label' => 'Approved billable', 'type' => 'hours'],
                      ['key' => 'utilization_pct', 'label' => 'Utilization', 'type' => 'pct', 'status' => 'utilization']],
        'rows' => $rows,
        'totals' => $plain === [] ? [] : [['label' => 'Total'] + $total($plain)],
    ];
}

/** Realization: ct_realization(from, to, engagement) — hours approved, billed, written off, unbilled, with no rate; standard against billed value. The client filter selects rows. */
function report_realization(PDO $pdo, array $f): array
{
    $st = $pdo->prepare('SELECT * FROM ct_realization(CAST(:f AS date), CAST(:t AS date), CAST(:e AS bigint))');
    $st->execute(['f' => $f['from'], 't' => $f['to'], 'e' => $f['engagement'] ?? null]);
    $map = report_engagement_map($pdo);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $id = (int) $r['engagement_id'];
        if (($f['client'] ?? null) !== null && (int) ($map[$id]['client_id'] ?? 0) !== (int) $f['client']) {
            continue;
        }
        $r['engagement_id'] = $id;
        $r['currency'] = $map[$id]['currency'] ?? ct_base_currency();
        $r['engagement'] = $r['engagement_number'] . ' ' . $r['engagement_name'];
        $r['engagement_href'] = report_engagement_url($id);
        $rows[] = $r;
    }
    $totals = [];
    foreach (report_by_currency($rows) as $cur => $rs) {
        $appr = report_sum($rs, 'approved_hours');
        $billed = report_sum($rs, 'billed_hours');
        $std = report_sum($rs, 'standard_amount');
        $bam = report_sum($rs, 'billed_amount');
        $totals[] = ['label' => 'Total', 'currency' => $cur, 'approved_hours' => $appr, 'billed_hours' => $billed, 'written_off_hours' => report_sum($rs, 'written_off_hours'),
                     'unbilled_hours' => report_sum($rs, 'unbilled_hours'), 'no_rate_hours' => report_sum($rs, 'no_rate_hours'), 'standard_amount' => $std, 'billed_amount' => $bam,
                     'realization_hours_pct' => report_pct($billed, $appr), 'realization_amount_pct' => report_pct($bam, $std)];
    }
    return [
        'columns' => [['key' => 'engagement', 'label' => 'Engagement', 'type' => 'text', 'link' => 'engagement_href'], ['key' => 'client_name', 'label' => 'Client', 'type' => 'text'],
                      ['key' => 'approved_hours', 'label' => 'Approved', 'type' => 'hours'], ['key' => 'billed_hours', 'label' => 'Billed', 'type' => 'hours'],
                      ['key' => 'written_off_hours', 'label' => 'Written off', 'type' => 'hours'], ['key' => 'unbilled_hours', 'label' => 'Unbilled', 'type' => 'hours'],
                      ['key' => 'no_rate_hours', 'label' => 'No rate', 'type' => 'hours'], ['key' => 'standard_amount', 'label' => 'Standard', 'type' => 'money'],
                      ['key' => 'billed_amount', 'label' => 'Billed value', 'type' => 'money'], ['key' => 'realization_hours_pct', 'label' => 'Realization (hours)', 'type' => 'pct', 'status' => 'realization'],
                      ['key' => 'realization_amount_pct', 'label' => 'Realization (amount)', 'type' => 'pct', 'status' => 'realization']],
        'rows' => $rows,
        'totals' => $totals,
    ];
}

/** Unbilled work (WIP): ct_unbilled(as_of, engagement, client) — each engagement's approved, unbilled time, expenses, usage and accepted milestones, and the oldest item. */
function report_wip(PDO $pdo, array $f): array
{
    $st = $pdo->prepare('SELECT * FROM ct_unbilled(COALESCE(CAST(:a AS date), current_date), CAST(:e AS bigint), CAST(:c AS bigint))');
    $st->execute(['a' => $f['as_of'] ?? null, 'e' => $f['engagement'] ?? null, 'c' => $f['client'] ?? null]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['engagement_id'] = (int) $r['engagement_id'];
        $r['currency'] = trim((string) $r['currency']);
        $r['engagement'] = $r['engagement_number'] . ' ' . $r['engagement_name'];
        $r['engagement_href'] = '/billing/unbilled/' . $r['engagement_id'];
        $rows[] = $r;
    }
    $totals = [];
    foreach (report_by_currency($rows) as $cur => $rs) {
        $totals[] = ['label' => 'Total', 'currency' => $cur, 'time_hours' => report_sum($rs, 'time_hours'), 'time_amount' => report_sum($rs, 'time_amount'), 'time_no_rate_hours' => report_sum($rs, 'time_no_rate_hours'),
                     'expense_amount' => report_sum($rs, 'expense_amount'), 'usage_amount' => report_sum($rs, 'usage_amount'), 'milestone_amount' => report_sum($rs, 'milestone_amount'), 'total_amount' => report_sum($rs, 'total_amount')];
    }
    return [
        'columns' => [['key' => 'engagement', 'label' => 'Engagement', 'type' => 'text', 'link' => 'engagement_href'], ['key' => 'client_name', 'label' => 'Client', 'type' => 'text'],
                      ['key' => 'time_hours', 'label' => 'Hours', 'type' => 'hours'], ['key' => 'time_amount', 'label' => 'Time', 'type' => 'money'], ['key' => 'time_no_rate_hours', 'label' => 'No-rate hours', 'type' => 'hours'],
                      ['key' => 'expense_amount', 'label' => 'Expenses', 'type' => 'money'], ['key' => 'usage_amount', 'label' => 'Usage', 'type' => 'money'], ['key' => 'milestone_amount', 'label' => 'Milestones', 'type' => 'money'],
                      ['key' => 'total_amount', 'label' => 'Total', 'type' => 'money'], ['key' => 'oldest_item', 'label' => 'Oldest item', 'type' => 'date']],
        'rows' => $rows,
        'totals' => $totals,
    ];
}

/** Revenue: ct_revenue(from, to, by) — what was invoiced (issued, not void) in the period, per currency. */
function report_revenue(PDO $pdo, array $f): array
{
    $by = $f['by'] ?? 'client';
    $st = $pdo->prepare('SELECT * FROM ct_revenue(CAST(:f AS date), CAST(:t AS date), :b)');
    $st->execute(['f' => $f['from'], 't' => $f['to'], 'b' => $by]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['currency'] = trim((string) $r['currency']);
        $r['invoices'] = (int) $r['invoices'];
        if ($by === 'kind') {
            $r['group_label'] = ucfirst((string) $r['group_label']);
        }
        $rows[] = $r;
    }
    $totals = [];
    foreach (report_by_currency($rows) as $cur => $rs) {
        $totals[] = ['label' => 'Total', 'currency' => $cur, 'invoices' => $by === 'kind' ? null : array_sum(array_column($rs, 'invoices')), 'subtotal' => report_sum($rs, 'subtotal'), 'tax' => report_sum($rs, 'tax'), 'total' => report_sum($rs, 'total')];
    }
    return [
        'columns' => [['key' => 'group_label', 'label' => REVENUE_GROUPS[$by] ?? 'Client', 'type' => 'text'], ['key' => 'invoices', 'label' => 'Invoices', 'type' => 'int'],
                      ['key' => 'subtotal', 'label' => 'Subtotal', 'type' => 'money'], ['key' => 'tax', 'label' => 'Tax', 'type' => 'money'], ['key' => 'total', 'label' => 'Total', 'type' => 'money']],
        'rows' => $rows,
        'totals' => $totals,
    ];
}

/** Margin: ct_margin(from, to, engagement) — bill value less cost; answers only under cost.view, and a Manager's only their own engagements. */
function report_margin(PDO $pdo, array $f): array
{
    $st = $pdo->prepare('SELECT * FROM ct_margin(CAST(:f AS date), CAST(:t AS date), CAST(:e AS bigint))');
    $st->execute(['f' => $f['from'], 't' => $f['to'], 'e' => $f['engagement'] ?? null]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['engagement_id'] = (int) $r['engagement_id'];
        $r['currency'] = trim((string) $r['currency']);
        $r['engagement'] = $r['engagement_number'] . ' ' . $r['engagement_name'];
        $r['engagement_href'] = report_engagement_url($r['engagement_id']);
        $rows[] = $r;
    }
    $totals = [];
    foreach (report_by_currency($rows) as $cur => $rs) {
        $bill = report_sum($rs, 'bill_value');
        $margin = report_sum($rs, 'margin');
        $totals[] = ['label' => 'Total', 'currency' => $cur, 'bill_value' => $bill, 'time_cost' => report_sum($rs, 'time_cost'), 'expense_cost' => report_sum($rs, 'expense_cost'), 'usage_cost' => report_sum($rs, 'usage_cost'),
                     'total_cost' => report_sum($rs, 'total_cost'), 'margin' => $margin, 'margin_pct' => report_pct($margin, $bill)];
    }
    return [
        'columns' => [['key' => 'engagement', 'label' => 'Engagement', 'type' => 'text', 'link' => 'engagement_href'], ['key' => 'client_name', 'label' => 'Client', 'type' => 'text'],
                      ['key' => 'bill_value', 'label' => 'Bill value', 'type' => 'money'], ['key' => 'time_cost', 'label' => 'Time cost', 'type' => 'money'], ['key' => 'expense_cost', 'label' => 'Expense cost', 'type' => 'money'],
                      ['key' => 'usage_cost', 'label' => 'Usage cost', 'type' => 'money'], ['key' => 'total_cost', 'label' => 'Total cost', 'type' => 'money'], ['key' => 'margin', 'label' => 'Margin', 'type' => 'money'],
                      ['key' => 'margin_pct', 'label' => 'Margin %', 'type' => 'pct', 'status' => 'margin']],
        'rows' => $rows,
        'totals' => $totals,
    ];
}

/** Budget burn: ct_budget_status() for each engagement the caller sees (the view), by status (active by default), practice and manager. The AI columns only under cost.view or usage.write. */
function report_budget_burn(PDO $pdo, array $f): array
{
    $st = $pdo->prepare("SELECT engagement_id, number, name, client_name, status, currency, kind FROM mcp_engagements
                          WHERE (CAST(:s AS text) IS NULL OR status = CAST(:s AS text)) AND (CAST(:d AS bigint) IS NULL OR department_id = CAST(:d AS bigint))
                            AND (CAST(:m AS bigint) IS NULL OR manager_member_id = CAST(:m AS bigint)) ORDER BY number");
    $status = ($f['status'] ?? 'active') === 'all' ? null : ($f['status'] ?? 'active');
    $st->execute(['s' => $status, 'd' => $f['department'] ?? null, 'm' => $f['manager'] ?? null]);
    $bs = $pdo->prepare('SELECT * FROM ct_budget_status(:e)');
    $rows = [];
    foreach ($st->fetchAll() as $e) {
        $bs->execute(['e' => $e['engagement_id']]);
        $b = $bs->fetch();
        if ($b === false) {
            continue;
        }
        $id = (int) $e['engagement_id'];
        $rows[] = ['engagement_id' => $id, 'engagement_number' => $e['number'], 'engagement_name' => $e['name'], 'engagement' => $e['number'] . ' ' . $e['name'], 'engagement_href' => report_engagement_url($id), 'client_name' => $e['client_name'], 'currency' => trim((string) $e['currency']), 'kind' => $e['kind']] + $b;
    }
    $ai = has_right('cost.view') || has_right('usage.write');
    $cols = [['key' => 'engagement', 'label' => 'Engagement', 'type' => 'text', 'link' => 'engagement_href'], ['key' => 'client_name', 'label' => 'Client', 'type' => 'text'],
             ['key' => 'hours', 'label' => 'Hours', 'type' => 'budget', 'used' => 'hours_used', 'of' => 'budget_hours', 'pct' => 'hours_pct', 'unit' => 'hours'],
             ['key' => 'amount', 'label' => 'Amount', 'type' => 'budget', 'used' => 'amount_used', 'of' => 'budget_amount', 'pct' => 'amount_pct', 'unit' => 'money'],
             ['key' => 'nte', 'label' => 'Invoiced / NTE', 'type' => 'budget', 'used' => 'invoiced_total', 'of' => 'nte_amount', 'pct' => 'nte_pct', 'unit' => 'money']];
    if ($ai) {
        $cols[] = ['key' => 'ai', 'label' => 'AI cost / budget', 'type' => 'budget', 'used' => 'ai_cost', 'of' => 'ai_budget_amount', 'pct' => 'ai_pct', 'unit' => 'money'];
    }
    $cols[] = ['key' => 'pace_hours_per_week', 'label' => 'Pace (h/week)', 'type' => 'hours'];
    $cols[] = ['key' => 'weeks_left_at_pace', 'label' => 'Weeks left', 'type' => 'number'];
    return ['columns' => $cols, 'rows' => $rows, 'totals' => []];
}

/** AI pass-through: ct_ai_passthrough(from, to, engagement) — charges per engagement and provider with cost, billable, billed, unbilled and the markup. */
function report_ai_passthrough(PDO $pdo, array $f): array
{
    $st = $pdo->prepare('SELECT * FROM ct_ai_passthrough(CAST(:f AS date), CAST(:t AS date), CAST(:e AS bigint))');
    $st->execute(['f' => $f['from'], 't' => $f['to'], 'e' => $f['engagement'] ?? null]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['engagement_id'] = (int) $r['engagement_id'];
        $r['currency'] = trim((string) $r['currency']);
        $r['charges'] = (int) $r['charges'];
        $r['engagement_href'] = report_engagement_url($r['engagement_id']);
        $r['kind'] = ucwords(str_replace('_', ' ', (string) $r['kind']));
        foreach (['billable_amount', 'billed_amount', 'unbilled_amount', 'markup'] as $k) {
            $r[$k] = $r[$k] ?? '0.00';
        }
        $rows[] = $r;
    }
    $totals = [];
    foreach (report_by_currency($rows) as $cur => $rs) {
        $totals[] = ['label' => 'Total', 'currency' => $cur, 'charges' => array_sum(array_column($rs, 'charges')), 'tokens' => report_sum($rs, 'tokens', 0), 'cost_amount' => report_sum($rs, 'cost_amount'),
                     'billable_amount' => report_sum($rs, 'billable_amount'), 'billed_amount' => report_sum($rs, 'billed_amount'), 'unbilled_amount' => report_sum($rs, 'unbilled_amount'), 'markup' => report_sum($rs, 'markup')];
    }
    $cost = has_right('cost.view') || has_right('usage.write');
    $cols = [['key' => 'engagement_number', 'label' => 'Engagement', 'type' => 'text', 'link' => 'engagement_href'], ['key' => 'provider', 'label' => 'Provider', 'type' => 'text'], ['key' => 'kind', 'label' => 'Kind', 'type' => 'text'],
             ['key' => 'charges', 'label' => 'Charges', 'type' => 'int'], ['key' => 'tokens', 'label' => 'Tokens', 'type' => 'number0']];
    if ($cost) {
        $cols[] = ['key' => 'cost_amount', 'label' => 'Cost', 'type' => 'money'];
    }
    array_push($cols, ['key' => 'billable_amount', 'label' => 'Billable', 'type' => 'money'], ['key' => 'billed_amount', 'label' => 'Billed', 'type' => 'money'], ['key' => 'unbilled_amount', 'label' => 'Unbilled', 'type' => 'money']);
    if ($cost) {
        $cols[] = ['key' => 'markup', 'label' => 'Markup', 'type' => 'money'];
    }
    return ['columns' => $cols, 'rows' => $rows, 'totals' => $totals];
}

/** The expense report: mcp_expenses (a person's own, an approver's engagements, everything for Billing/Finance/admin) grouped by person, engagement, category and currency. */
function report_expenses(PDO $pdo, array $f): array
{
    $status = ($f['status'] ?? 'approved') === 'all' ? null : ($f['status'] ?? 'approved');
    $st = $pdo->prepare("SELECT member_id, member_name, engagement_id, engagement_number, category_id, category_label, currency, count(*)::int AS expenses,
                                sum(amount) AS amount, sum(tax_amount) AS tax_amount, COALESCE(sum(billable_amount) FILTER (WHERE billable), 0) AS billable_amount,
                                COALESCE(sum(amount) FILTER (WHERE reimbursable), 0) AS reimbursable_amount, COALESCE(sum(amount) FILTER (WHERE reimbursed_at IS NOT NULL), 0) AS reimbursed_amount
                           FROM mcp_expenses
                          WHERE expense_date BETWEEN CAST(:f AS date) AND CAST(:t AS date) AND (CAST(:s AS text) IS NULL OR status = CAST(:s AS text))
                            AND (CAST(:m AS bigint) IS NULL OR member_id = CAST(:m AS bigint)) AND (CAST(:e AS bigint) IS NULL OR engagement_id = CAST(:e AS bigint))
                            AND (CAST(:c AS bigint) IS NULL OR category_id = CAST(:c AS bigint))
                          GROUP BY member_id, member_name, engagement_id, engagement_number, category_id, category_label, currency
                          ORDER BY member_name, engagement_number, category_label, currency");
    $st->execute(['f' => $f['from'], 't' => $f['to'], 's' => $status, 'm' => $f['member'] ?? null, 'e' => $f['engagement'] ?? null, 'c' => $f['category'] ?? null]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['currency'] = trim((string) $r['currency']);
        $r['engagement_id'] = (int) $r['engagement_id'];
        $r['engagement_href'] = report_engagement_url($r['engagement_id']);
        $rows[] = $r;
    }
    $totals = [];
    foreach (report_by_currency($rows) as $cur => $rs) {
        $totals[] = ['label' => 'Total', 'currency' => $cur, 'expenses' => array_sum(array_column($rs, 'expenses')), 'amount' => report_sum($rs, 'amount'), 'tax_amount' => report_sum($rs, 'tax_amount'),
                     'billable_amount' => report_sum($rs, 'billable_amount'), 'reimbursable_amount' => report_sum($rs, 'reimbursable_amount'), 'reimbursed_amount' => report_sum($rs, 'reimbursed_amount')];
    }
    return [
        'columns' => [['key' => 'member_name', 'label' => 'Person', 'type' => 'text'], ['key' => 'engagement_number', 'label' => 'Engagement', 'type' => 'text', 'link' => 'engagement_href'], ['key' => 'category_label', 'label' => 'Category', 'type' => 'text'],
                      ['key' => 'expenses', 'label' => 'Expenses', 'type' => 'int'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'], ['key' => 'tax_amount', 'label' => 'Tax', 'type' => 'money'],
                      ['key' => 'billable_amount', 'label' => 'Billable', 'type' => 'money'], ['key' => 'reimbursable_amount', 'label' => 'Reimbursable', 'type' => 'money'], ['key' => 'reimbursed_amount', 'label' => 'Reimbursed', 'type' => 'money']],
        'rows' => $rows,
        'totals' => $totals,
    ];
}

/** Missing time: ct_missing_time(week) — who has not submitted the week (last week by default) and which weekdays are empty. The nudge is slice 3's /time/missing. */
function report_missing_time(PDO $pdo, array $f): array
{
    $st = $pdo->prepare('SELECT * FROM ct_missing_time(CAST(:w AS date))');
    $st->execute(['w' => $f['week'] ?? null]);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $r['member_id'] = (int) $r['member_id'];
        $r['empty_weekdays'] = (int) $r['empty_weekdays'];
        $r['status_label'] = $r['status'] === 'none' ? 'No week' : ucfirst((string) $r['status']);
        $rows[] = $r;
    }
    $week = $rows[0]['week_start'] ?? (string) one_value($pdo, 'SELECT COALESCE(ct_week_start(CAST(:w AS date)), ct_week_start(current_date) - 7)', ['w' => $f['week'] ?? null]);
    return [
        'columns' => [['key' => 'member_name', 'label' => 'Person', 'type' => 'text'], ['key' => 'status_label', 'label' => 'Week', 'type' => 'text'], ['key' => 'hours_logged', 'label' => 'Logged', 'type' => 'hours'],
                      ['key' => 'empty_weekdays', 'label' => 'Empty weekdays', 'type' => 'int'], ['key' => 'capacity_hours', 'label' => 'Capacity', 'type' => 'hours']],
        'rows' => $rows,
        'totals' => [],
        'week_start' => $week,
    ];
}

/** The rows a report counts (a practice subtotal is not one). */
function report_row_count(array $report): int
{
    return count(array_filter($report['rows'], static fn (array $r): bool => empty($r['is_subtotal'])));
}

/** Which function answers a report key. */
function report_run(PDO $pdo, string $report, array $f): array
{
    return match ($report) {
        'utilization' => report_utilization($pdo, $f),
        'realization' => report_realization($pdo, $f),
        'wip' => report_wip($pdo, $f),
        'revenue' => report_revenue($pdo, $f),
        'margin' => report_margin($pdo, $f),
        'budget-burn' => report_budget_burn($pdo, $f),
        'ai-passthrough' => report_ai_passthrough($pdo, $f),
        'expenses' => report_expenses($pdo, $f),
        'missing-time' => report_missing_time($pdo, $f),
    };
}

/** Whose engagements the margin covers: "Every engagement" (the owner, Billing, Finance, the admin) or "Your engagements" (a Manager). */
function report_scope_label(PDO $pdo): string
{
    return db_bool($pdo, 'SELECT ct_sees_all()') ? 'Every engagement' : 'Your engagements';
}

// ---- cells and CSV (one truth, two shapes) ---------------------------------------------------------------------------------------------------------

/** One cell as its text — the page shows it, the JSON answer carries it, the CSV writes it. */
function report_cell(array $col, array $row): string
{
    $key = $col['key'];
    $cur = in_array($col['type'], ['money', 'budget'], true) ? (string) ($row['currency'] ?? ct_base_currency()) : '';
    switch ($col['type']) {
        case 'hours':
            return ($row[$key] ?? null) === null ? '—' : fmt_hours($row[$key]);
        case 'money':
            return ($row[$key] ?? null) === null ? '—' : fmt_money($row[$key], $cur);
        case 'pct':
            return ($row[$key] ?? null) === null ? '—' : number_format((float) $row[$key], 1, '.', ',') . ' %';
        case 'int':
            return ($row[$key] ?? null) === null ? '—' : (string) (int) $row[$key];
        case 'number':
            return ($row[$key] ?? null) === null ? '—' : rtrim(rtrim(number_format((float) $row[$key], 1, '.', ','), '0'), '.');
        case 'number0':
            return ($row[$key] ?? null) === null ? '—' : number_format((float) $row[$key], 0, '.', ',');
        case 'budget':
            $used = $row[$col['used']] ?? null;
            $of = $row[$col['of']] ?? null;
            $fmt = static fn ($v): string => $col['unit'] === 'hours' ? fmt_hours($v) : fmt_money($v, $cur);
            if ($of === null || (float) $of <= 0) {
                return $fmt($used ?? '0') . ' (no budget)';
            }
            return $fmt($used ?? '0') . ' of ' . $fmt($of) . ' (' . number_format((float) ($row[$col['pct']] ?? 0), 1, '.', ',') . ' %)';
        default:
            return (string) ($row[$key] ?? '');
    }
}

/** The report as strings: ['header' => [...], 'rows' => [[...]], 'totals' => [[...]]] — a totals row's first cell is "Total" (with its currency when the report is in several). */
function report_cells(array $report): array
{
    $cols = $report['columns'];
    $header = array_column($cols, 'label');
    $line = static function (array $row) use ($cols): array {
        $cells = [];
        foreach ($cols as $c) {
            $cells[] = report_cell($c, $row);
        }
        return $cells;
    };
    $rows = array_map($line, $report['rows']);
    $multi = count($report['totals']) > 1;
    $totals = [];
    foreach ($report['totals'] as $t) {
        $cells = $line($t);
        $cells[0] = 'Total' . ($multi ? ' ' . $t['currency'] : '');
        $totals[] = $cells;
    }
    return ['header' => $header, 'rows' => $rows, 'totals' => $totals];
}

/** The CSV text of a report: the header, the rows, the totals — exactly the cells the page shows (a cell that begins with = + - @ is quoted with a leading apostrophe, as spreadsheets need). */
function report_csv(array $report): string
{
    $cells = report_cells($report);
    $out = fopen('php://temp', 'r+');
    $safe = static fn (string $c): string => preg_match('/^[=+\-@\t\r]/', $c) === 1 ? "'" . $c : $c;     // a name that begins like a formula is not one in a spreadsheet
    fputcsv($out, $cells['header'], ',', '"', '');
    foreach (array_merge($cells['rows'], $cells['totals']) as $line) {
        fputcsv($out, array_map($safe, $line), ',', '"', '');
    }
    rewind($out);
    $text = (string) stream_get_contents($out);
    fclose($out);
    return $text;
}

/** The status colour of a percentage cell: utilization under 50 danger, 50-70 warning, over 70 success; realization under 80 warning; margin negative danger. */
function report_status(string $kind, ?string $value): string
{
    if ($value === null) {
        return '';
    }
    $v = (float) $value;
    return match ($kind) {
        'utilization' => $v < 50 ? 'danger' : ($v <= 70 ? 'warning' : 'success'),
        'realization' => $v < 80 ? 'warning' : '',
        'margin' => $v < 0 ? 'danger' : '',
        default => '',
    };
}
