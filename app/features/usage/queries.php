<?php
declare(strict_types=1);

/**
 * AI usage and hosting (slice 5): the costs that make AI consulting different, as billable charges, and the kernel's closed monthly AI statement placed on engagements by rules.
 * Reads through the mcp_usage_* / mcp_ai_statement* / mcp_allocation_rules / mcp_recurring_charges views — the view decides who sees a charge and its cost (D8: the figures are costs,
 * cost.view or the engagement's manager); this code never blanks a column and never re-implements visibility. The number, the money from the pricing, the lock once approved, the
 * statement's rounding and remainder are the database's (db/011).
 */

const USAGE_KINDS = ['model_usage' => 'Model usage', 'api' => 'API calls', 'hosting' => 'Hosting', 'storage' => 'Storage', 'other' => 'Other'];
const USAGE_UNITS = ['tokens' => 'tokens', 'calls' => 'calls', 'gb' => 'GB', 'gb_month' => 'GB-months', 'vm_hours' => 'VM-hours', 'months' => 'months', 'units' => 'units'];
const USAGE_STATUSES = ['draft' => 'Draft', 'approved' => 'Approved', 'void' => 'Void'];
const USAGE_TONES = ['draft' => 'secondary', 'approved' => 'success', 'void' => 'dark'];
const USAGE_PRICINGS = ['cost' => 'At cost', 'markup' => 'Cost plus a markup', 'resale' => 'A resale price per unit'];
const USAGE_SOURCES = ['manual' => 'By hand', 'csv_import' => 'CSV import', 'kernel_ledger' => 'The OS statement', 'schedule' => 'A schedule'];
const USAGE_SOURCE_ICONS = ['manual' => '', 'csv_import' => 'feather-upload', 'kernel_ledger' => 'feather-cpu', 'schedule' => 'feather-refresh-cw'];
const CADENCES = ['weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'];

function usage_cast(array $u): array
{
    foreach (['usage_charge_id', 'engagement_id', 'provider_id', 'import_id', 'statement_id', 'schedule_id', 'approved_by', 'voided_by'] as $k) {
        $u[$k] = ($u[$k] ?? null) === null ? null : (int) $u[$k];
    }
    foreach (['billable', 'billed'] as $k) {
        $u[$k] = (bool) ($u[$k] ?? false);
    }
    $u['currency'] = trim((string) ($u['currency'] ?? ''));
    return $u;
}

/** The WHERE of a charges filter: engagement_id, from, to (the period overlaps), kind, source, status, provider_id, import_id, statement_id, q. */
function usage_where(array $f): array
{
    $where = [];
    $args = [];
    if (($f['engagement_id'] ?? null) !== null) { $where[] = 'u.engagement_id = :eng'; $args['eng'] = (int) $f['engagement_id']; }
    if (($f['from'] ?? null) !== null) { $where[] = 'u.period_end >= :from'; $args['from'] = $f['from']; }
    if (($f['to'] ?? null) !== null) { $where[] = 'u.period_start <= :to'; $args['to'] = $f['to']; }
    foreach (['kind' => USAGE_KINDS, 'source' => USAGE_SOURCES, 'status' => USAGE_STATUSES] as $k => $allowed) {
        if (($f[$k] ?? '') !== '' && isset($allowed[$f[$k]])) { $where[] = "u.$k = :$k"; $args[$k] = $f[$k]; }
    }
    if (($f['provider_id'] ?? null) !== null) { $where[] = 'u.provider_id = :prov'; $args['prov'] = (int) $f['provider_id']; }
    if (($f['import_id'] ?? null) !== null) { $where[] = 'u.import_id = :imp'; $args['imp'] = (int) $f['import_id']; }
    if (($f['statement_id'] ?? null) !== null) { $where[] = 'u.statement_id = :stmt'; $args['stmt'] = (int) $f['statement_id']; }
    if (($f['q'] ?? '') !== '') {
        $where[] = '(u.number ILIKE :q OR u.description ILIKE :q)';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['q']) . '%';
    }
    return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $args];
}

/** Charges through the view, newest period first. */
function find_usage_charges(PDO $pdo, array $filters = [], int $limit = 100, int $offset = 0): array
{
    [$w, $args] = usage_where($filters);
    $st = $pdo->prepare('SELECT u.* FROM mcp_usage_charges u' . $w . ' ORDER BY u.period_start DESC, u.usage_charge_id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return array_map('usage_cast', $st->fetchAll());
}

/** The footer's totals per currency over the same filter (void left out): count, billable (what bills), cost (null when the view hides it from the caller). */
function usage_totals(PDO $pdo, array $filters = []): array
{
    [$w, $args] = usage_where($filters);
    $w .= ($w === '' ? ' WHERE' : ' AND') . " u.status <> 'void'";
    $st = $pdo->prepare('SELECT u.currency, count(*) AS charges, sum(u.billable_amount) FILTER (WHERE u.billable) AS billable_amount, sum(u.cost_amount) AS cost_amount, bool_and(u.cost_amount IS NOT NULL) AS cost_known
                           FROM mcp_usage_charges u' . $w . ' GROUP BY u.currency ORDER BY u.currency');
    $st->execute($args);
    return array_map(static function (array $r): array {
        $r['currency'] = trim((string) $r['currency']);
        $r['charges'] = (int) $r['charges'];
        $r['billable_amount'] = number_format((float) $r['billable_amount'], 2, '.', '');
        $r['cost_amount'] = $r['cost_known'] ? number_format((float) $r['cost_amount'], 2, '.', '') : null;
        unset($r['cost_known']);
        return $r;
    }, $st->fetchAll());
}

/** One charge through the view with its source detail, documents and notes, or null when the caller may not see it. */
function find_usage_charge(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT u.* FROM mcp_usage_charges u WHERE u.usage_charge_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $u = usage_cast($r);
    require_once dirname(__DIR__) . '/documents/write.php';
    $u['documents'] = find_attachments($pdo, 'usage_charge', $id);
    $u['notes'] = find_notes($pdo, 'usage_charge', $id);
    $u['import'] = null;
    $u['schedule'] = null;
    $u['statement'] = null;
    $u['pieces'] = [];
    if ($u['import_id'] !== null) {
        $i = $pdo->prepare('SELECT import_id, file_name, row_count, inserted, skipped, created_at FROM mcp_usage_imports WHERE import_id = :i');
        $i->execute(['i' => $u['import_id']]);
        $u['import'] = $i->fetch() ?: null;
    }
    if ($u['schedule_id'] !== null) {
        $i = $pdo->prepare('SELECT schedule_id, description, cadence, every_n, next_date, active FROM mcp_recurring_charges WHERE schedule_id = :i');
        $i->execute(['i' => $u['schedule_id']]);
        $u['schedule'] = $i->fetch() ?: null;
    }
    if ($u['statement_id'] !== null) {
        $i = $pdo->prepare('SELECT ai_statement_id, period, kernel_status, status FROM mcp_ai_statements WHERE ai_statement_id = :i');
        $i->execute(['i' => $u['statement_id']]);
        $u['statement'] = $i->fetch() ?: null;
        $p = $pdo->prepare('SELECT a.allocation_id, a.amount, l.line_id, l.line_no, l.provider, l.model, l.agent, l.department, l.application, l.amount AS line_amount, a.rule_id, r.name AS rule_name, r.share_pct
                              FROM mcp_ai_statement_allocations a JOIN mcp_ai_statement_lines l ON l.line_id = a.line_id LEFT JOIN mcp_allocation_rules r ON r.rule_id = a.rule_id
                             WHERE a.usage_charge_id = :u ORDER BY l.line_no, a.allocation_id');
        $p->execute(['u' => $id]);
        $u['pieces'] = $p->fetchAll();
    }
    return $u;
}

/** The charge a request names, or null. */
function usage_id_from_request(): ?int
{
    return request_integer('charge') ?? request_integer('usage_charge') ?? request_integer('usage_charge_id') ?? request_integer('id');
}

/** A record's trail, without the screen views. */
function usage_timeline(PDO $pdo, int $id, int $limit = 100): array
{
    return array_values(array_filter(find_record_activity($pdo, 'usage_charge', $id, $limit + 20), static fn (array $r): bool => $r['action'] !== 'screen.view'));
}

/** Draft charges on the engagements the caller manages, oldest first — the home's Approvals waiting. */
function usage_pending(PDO $pdo, int $limit = 100): array
{
    $st = $pdo->prepare("SELECT u.* FROM mcp_usage_charges u WHERE u.status = 'draft' AND u.engagement_id IN (SELECT ct_managed_engagement_ids()) ORDER BY u.created_at, u.usage_charge_id LIMIT " . max(1, $limit));
    $st->execute();
    return array_map('usage_cast', $st->fetchAll());
}

/** The engagements a charge may be recorded on by the caller: live, managed (the manage rule is the database's i_manage), with the currency. */
function usage_engagements(PDO $pdo): array
{
    return array_map(static function (array $e): array {
        $e['engagement_id'] = (int) $e['engagement_id'];
        return $e;
    }, $pdo->query("SELECT e.engagement_id, e.number, e.name, e.client_name, e.currency FROM mcp_engagements e WHERE e.i_manage AND e.status IN ('active', 'on_hold') ORDER BY e.number")->fetchAll());
}

/** The live providers for the pick: [{provider_id, provider_key, name, csv_mapping}]. */
function usage_providers_for_pick(PDO $pdo): array
{
    return array_map(static function (array $p): array {
        $p['provider_id'] = (int) $p['provider_id'];
        $p['csv_mapping'] = is_string($p['csv_mapping']) ? json_decode($p['csv_mapping'], true) : $p['csv_mapping'];
        return $p;
    }, $pdo->query('SELECT provider_id, provider_key, name, csv_mapping FROM mcp_usage_providers WHERE archived_at IS NULL ORDER BY sort_order, name')->fetchAll());
}

/** How many AI-budget alerts an engagement has had (the approve handler compares before and after the check to know whether this approval crossed the threshold). */
function count_ai_alerts(PDO $pdo, int $engagementId): int
{
    return (int) one_value($pdo, "SELECT count(*) FROM budget_alerts WHERE engagement_id = :e AND kind = 'ai'", ['e' => $engagementId]);
}

// ---- the imports -------------------------------------------------------------------------------------------------------------------------------------------------------

function find_usage_imports(PDO $pdo, int $limit = 20): array
{
    $st = $pdo->prepare('SELECT i.import_id, i.provider_id, i.provider_name, i.file_name, i.row_count, i.inserted, i.skipped, i.detail, i.created_by, i.created_at,
                                (SELECT count(*) FROM mcp_usage_charges u WHERE u.import_id = i.import_id AND u.status = \'draft\') AS drafts,
                                (SELECT count(*) FROM mcp_usage_charges u WHERE u.import_id = i.import_id) AS charges
                           FROM mcp_usage_imports i ORDER BY i.import_id DESC LIMIT ' . max(1, $limit));
    $st->execute();
    return array_map(static function (array $r): array {
        foreach (['import_id', 'provider_id', 'row_count', 'inserted', 'skipped', 'drafts', 'charges'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        }
        return $r;
    }, $st->fetchAll());
}

function find_usage_import(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT import_id, provider_id, provider_name, file_name, mapping, row_count, inserted, skipped, detail, created_by, created_at FROM mcp_usage_imports WHERE import_id = :i');
    $st->execute(['i' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['import_id', 'provider_id', 'row_count', 'inserted', 'skipped'] as $k) {
        $r[$k] = (int) $r[$k];
    }
    $r['mapping'] = is_string($r['mapping']) ? json_decode($r['mapping'], true) : $r['mapping'];
    return $r;
}

// ---- the allocation rules ----------------------------------------------------------------------------------------------------------------------------------------------

function rule_cast(array $r): array
{
    foreach (['rule_id', 'kernel_agent_id', 'kernel_department_id', 'kernel_application_id', 'engagement_id', 'created_by'] as $k) {
        $r[$k] = ($r[$k] ?? null) === null ? null : (int) $r[$k];
    }
    $r['active'] = (bool) $r['active'];
    return $r;
}

/** The names the statement's lines carried for the kernel's ids: ['agent' => [id => name], 'department' => [...], 'application' => [...]] — a rule shows the name when the statement has been seen, else the id. */
function kernel_names(PDO $pdo): array
{
    $out = ['agent' => [], 'department' => [], 'application' => []];
    foreach ($pdo->query('SELECT DISTINCT kernel_agent_id AS i, agent AS n, kernel_department_id AS d, department AS dn, kernel_application_id AS a, application AS an FROM mcp_ai_statement_lines')->fetchAll() as $r) {
        if ($r['i'] !== null && $r['n'] !== null) { $out['agent'][(int) $r['i']] = $r['n']; }
        if ($r['d'] !== null && $r['dn'] !== null) { $out['department'][(int) $r['d']] = $r['dn']; }
        if ($r['a'] !== null && $r['an'] !== null) { $out['application'][(int) $r['a']] = $r['an']; }
    }
    return $out;
}

/** Rules through the view, with what each placed on the latest allocated month ([period, amount]). $filters: engagement_id, active (bool). */
function find_allocation_rules(PDO $pdo, array $filters = []): array
{
    $where = [];
    $args = [];
    if (($filters['engagement_id'] ?? null) !== null) { $where[] = 'r.engagement_id = :eng'; $args['eng'] = (int) $filters['engagement_id']; }
    if (isset($filters['active'])) { $where[] = $filters['active'] ? 'r.active' : 'NOT r.active'; }
    $st = $pdo->prepare('SELECT r.* FROM mcp_allocation_rules r' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY r.engagement_number, r.rule_id');
    $st->execute($args);
    $rows = array_map('rule_cast', $st->fetchAll());
    $last = $pdo->query("SELECT ai_statement_id, period FROM mcp_ai_statements WHERE status = 'allocated' ORDER BY period DESC LIMIT 1")->fetch();
    $made = [];
    if ($last !== false) {
        $p = $pdo->prepare('SELECT a.rule_id, sum(a.amount) AS amount FROM mcp_ai_statement_allocations a JOIN mcp_usage_charges u ON u.usage_charge_id = a.usage_charge_id
                             WHERE a.statement_id = :s AND u.status <> \'void\' GROUP BY a.rule_id');
        $p->execute(['s' => (int) $last['ai_statement_id']]);
        foreach ($p->fetchAll() as $m) {
            $made[(int) $m['rule_id']] = number_format((float) $m['amount'], 2, '.', '');
        }
    }
    foreach ($rows as &$r) {
        $r['last'] = $last === false ? null : ['period' => $last['period'], 'amount' => $made[$r['rule_id']] ?? '0.00'];
    }
    return $rows;
}

function find_allocation_rule(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT r.* FROM mcp_allocation_rules r WHERE r.rule_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : rule_cast($r);
}

// ---- the hosting schedules ---------------------------------------------------------------------------------------------------------------------------------------------

function schedule_cast(array $r): array
{
    foreach (['schedule_id', 'engagement_id', 'provider_id', 'every_n', 'run_count'] as $k) {
        $r[$k] = ($r[$k] ?? null) === null ? null : (int) $r[$k];
    }
    $r['active'] = (bool) $r['active'];
    $r['due'] = $r['active'] && $r['next_date'] <= date('Y-m-d');
    return $r;
}

/** Schedules through the view. $filters: engagement_id, active, due (bool). */
function find_schedules(PDO $pdo, array $filters = []): array
{
    $where = [];
    $args = [];
    if (($filters['engagement_id'] ?? null) !== null) { $where[] = 'r.engagement_id = :eng'; $args['eng'] = (int) $filters['engagement_id']; }
    if (isset($filters['active'])) { $where[] = $filters['active'] ? 'r.active' : 'NOT r.active'; }
    if (!empty($filters['due'])) { $where[] = 'r.active AND r.next_date <= current_date'; }
    $st = $pdo->prepare('SELECT r.* FROM mcp_recurring_charges r' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY r.next_date, r.schedule_id');
    $st->execute($args);
    return array_map('schedule_cast', $st->fetchAll());
}

function find_schedule(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT r.* FROM mcp_recurring_charges r WHERE r.schedule_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : schedule_cast($r);
}

// ---- the kernel's statement --------------------------------------------------------------------------------------------------------------------------------------------

function statement_cast(array $s): array
{
    foreach (['ai_statement_id', 'allocated_by', 'late_calls'] as $k) {
        $s[$k] = ($s[$k] ?? null) === null ? null : (int) $s[$k];
    }
    $s['statement_amount'] = number_format((float) $s['statement_amount'], 2, '.', '');
    $s['allocated_amount'] = number_format((float) $s['allocated_amount'], 2, '.', '');
    $s['remainder'] = number_format((float) $s['statement_amount'] - (float) $s['allocated_amount'], 2, '.', '');
    $s['charges'] = (int) ($s['charges'] ?? 0);
    $s['lines'] = (int) ($s['lines'] ?? 0);
    return $s;
}

/** The months this application holds, newest first (the kernel's status, ours, the totals and the remainder), or the one asked. */
function ai_statement_status(PDO $pdo, ?string $period = null): array
{
    $st = $pdo->prepare('SELECT s.ai_statement_id, s.period, s.period_start, s.period_end, s.kernel_status, s.document_hash, s.pulled_at, s.status, s.allocated_at, s.allocated_by, s.late_calls,
                                s.statement_amount, s.allocated_amount,
                                (SELECT count(*) FROM mcp_ai_statement_lines l WHERE l.statement_id = s.ai_statement_id) AS lines,
                                (SELECT count(*) FROM mcp_usage_charges u WHERE u.statement_id = s.ai_statement_id AND u.status <> \'void\') AS charges
                           FROM mcp_ai_statements s' . ($period === null ? '' : ' WHERE s.period = :p') . ' ORDER BY s.period DESC');
    $st->execute($period === null ? [] : ['p' => $period]);
    return array_map('statement_cast', $st->fetchAll());
}

/** A month's lines with what each placed and what remains, in the statement's order. */
function ai_statement_lines(PDO $pdo, int $statementId): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_ai_statement_lines WHERE statement_id = :s ORDER BY line_no');
    $st->execute(['s' => $statementId]);
    return array_map(static function (array $l): array {
        foreach (['line_id', 'statement_id', 'line_no', 'kernel_department_id', 'kernel_agent_id', 'kernel_application_id', 'calls', 'late_calls', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens'] as $k) {
            $l[$k] = $l[$k] === null ? null : (int) $l[$k];
        }
        $l['currency'] = trim((string) $l['currency']);
        $l['tokens'] = $l['input_tokens'] + $l['output_tokens'] + $l['cache_read_tokens'] + $l['cache_write_tokens'];
        return $l;
    }, $st->fetchAll());
}

/** The month's state per provider and currency (ct_ai_statement_summary): [{provider, currency, statement_amount, allocated_amount, remainder, lines}]. */
function ai_statement_summary(PDO $pdo, int $statementId): array
{
    $st = $pdo->prepare('SELECT * FROM ct_ai_statement_summary(:s)');
    $st->execute(['s' => $statementId]);
    return array_map(static function (array $r): array {
        $r['currency'] = trim((string) $r['currency']);
        $r['lines'] = (int) $r['lines'];
        return $r;
    }, $st->fetchAll());
}

/** The charges a month made (the view), oldest first. */
function ai_statement_charges(PDO $pdo, int $statementId): array
{
    return find_usage_charges($pdo, ['statement_id' => $statementId], 500);
}

/** The AI pass-through report (db/014 ct_ai_passthrough): per engagement, provider, kind and currency. */
function ai_passthrough(PDO $pdo, string $from, string $to, ?int $engagementId = null): array
{
    $st = $pdo->prepare('SELECT * FROM ct_ai_passthrough(:f, :t, CAST(:e AS bigint))');
    $st->execute(['f' => $from, 't' => $to, 'e' => $engagementId]);
    return $st->fetchAll();
}
