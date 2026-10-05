<?php
declare(strict_types=1);

/**
 * Engagements (slice 2): the record every later slice hangs from. Reads through mcp_engagements, mcp_engagement_members, mcp_engagement_role_rates,
 * mcp_milestones, mcp_budget_alerts (the views decide who sees what — a bill rate only with rates.view, never a cost); writes to the base tables with
 * the guards as the referee (the number, the legal moves, the retainer refusal, the staffing's non-overlap, the milestone's moves).
 */

const ENGAGEMENT_KINDS = ['time_and_materials' => 'Time and materials', 'fixed_bid' => 'Fixed bid'];
const ENGAGEMENT_STATUSES = ['draft' => 'Draft', 'active' => 'Active', 'on_hold' => 'On hold', 'closed' => 'Closed', 'cancelled' => 'Cancelled'];
const BILLING_CADENCES = ['weekly' => 'Weekly', 'biweekly' => 'Every two weeks', 'monthly' => 'Monthly', 'on_milestone' => 'On each milestone', 'manual' => 'By hand'];
const MILESTONE_STATUSES = ['planned' => 'Planned', 'delivered' => 'Delivered', 'accepted' => 'Accepted', 'invoiced' => 'Invoiced', 'cancelled' => 'Cancelled'];
const ENGAGEMENT_SELECT = 'SELECT e.*, (SELECT count(*) FROM milestones ms WHERE ms.engagement_id = e.engagement_id AND ms.status IN (\'planned\', \'delivered\')) AS open_milestones,
       (SELECT count(*) FROM budget_alerts b WHERE b.engagement_id = e.engagement_id AND b.acknowledged_at IS NULL) AS open_alerts
  FROM mcp_engagements e';

function engagement_cast(array $e): array
{
    foreach (['engagement_id', 'client_id', 'rate_card_id', 'department_id', 'manager_member_id', 'created_by', 'staffed_count', 'open_milestones', 'open_alerts'] as $k) {
        $e[$k] = ($e[$k] ?? null) === null ? null : (int) $e[$k];
    }
    foreach (['i_manage', 'i_am_staffed'] as $k) {
        $e[$k] = (bool) $e[$k];
    }
    $e['share_receipts_with_client'] = $e['share_receipts_with_client'] === null ? null : (bool) $e['share_receipts_with_client'];
    $e['expense_policy'] = is_string($e['expense_policy']) ? (json_decode($e['expense_policy'], true) ?: []) : ($e['expense_policy'] ?? []);
    return $e;
}

/** The engagements the caller may see. $filters: q, client_id, kind, status[] (default: every one but cancelled), department_id, manager, mine, ending_within_days. */
function find_engagements(PDO $pdo, array $filters = [], int $limit = 100, int $offset = 0): array
{
    [$where, $args] = engagement_where($filters);
    $st = $pdo->prepare(ENGAGEMENT_SELECT . $where . " ORDER BY array_position(ARRAY['active', 'on_hold', 'draft', 'closed', 'cancelled'], e.status), e.end_date NULLS LAST, e.engagement_id DESC LIMIT " . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute($args);
    return array_map('engagement_cast', $st->fetchAll());
}

function count_engagements(PDO $pdo, array $filters = []): int
{
    [$where, $args] = engagement_where($filters);
    $st = $pdo->prepare('SELECT count(*) FROM mcp_engagements e' . $where);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

function engagement_where(array $f): array
{
    $where = [];
    $args = [];
    if (($f['q'] ?? '') !== '') {
        $where[] = '(e.name ILIKE :q OR e.number ILIKE :q OR e.client_name ILIKE :q OR e.po_number ILIKE :q)';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $f['q']) . '%';
    }
    if (($f['client_id'] ?? null) !== null) {
        $where[] = 'e.client_id = :client';
        $args['client'] = (int) $f['client_id'];
    }
    if (($f['kind'] ?? '') !== '') {
        $where[] = 'e.kind = :kind';
        $args['kind'] = (string) $f['kind'];
    }
    $statuses = $f['status'] ?? ['draft', 'active', 'on_hold', 'closed'];
    if ($statuses !== [] && $statuses !== ['all']) {
        $where[] = 'e.status = ANY (CAST(:statuses AS text[]))';
        $args['statuses'] = pg_array_literal(array_values($statuses));
    }
    if (($f['department_id'] ?? null) !== null) {
        $where[] = 'e.department_id = :dept';
        $args['dept'] = (int) $f['department_id'];
    }
    if (($f['manager'] ?? null) !== null) {
        $where[] = 'e.manager_member_id = :manager';
        $args['manager'] = (int) $f['manager'];
    }
    if (!empty($f['mine'])) {
        $where[] = '(e.i_manage OR e.i_am_staffed)';
    }
    if (($f['ending_within_days'] ?? null) !== null) {
        $where[] = 'e.end_date IS NOT NULL AND e.end_date <= current_date + make_interval(days => :days) AND e.status = \'active\'';
        $args['days'] = (int) $f['ending_within_days'];
    }
    return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $args];
}

/** One engagement with its staffing, role rates, milestones, budget status, open alerts and documents. Null when unseen. */
function find_engagement(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(ENGAGEMENT_SELECT . ' WHERE e.engagement_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $e = engagement_cast($r);
    $e['staffing'] = find_staffing($pdo, $id);
    $e['role_rates'] = find_role_rates($pdo, $id);
    $e['milestones'] = find_milestones($pdo, $id);
    $e['budget'] = engagement_budget($pdo, $id);
    $e['alerts'] = find_budget_alerts($pdo, $id, true);
    return $e;
}

function find_engagement_by_number(PDO $pdo, string $number): ?int
{
    $v = one_value($pdo, 'SELECT engagement_id FROM mcp_engagements WHERE number = :n', ['n' => strtoupper(trim($number))]);
    return $v === null ? null : (int) $v;
}

/** The base row (the handler's "a field left out stays"). */
function engagement_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM engagements WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['client_id', 'rate_card_id', 'department_id', 'manager_member_id'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    $r['share_receipts_with_client'] = $r['share_receipts_with_client'] === null ? null : (bool) $r['share_receipts_with_client'];
    $r['expense_policy'] = json_decode((string) $r['expense_policy'], true) ?: [];
    return $r;
}

/** What an engagement's log payload carries. */
function engagement_loggable(array $s): array
{
    return ['number' => $s['number'], 'client_id' => $s['client_id'], 'name' => $s['name'], 'kind' => $s['kind'], 'status' => $s['status'], 'start_date' => $s['start_date'], 'end_date' => $s['end_date'],
            'currency' => $s['currency'], 'po_number' => $s['po_number'], 'rate_card_id' => $s['rate_card_id'], 'nte_amount' => $s['nte_amount'], 'budget_hours' => $s['budget_hours'], 'budget_amount' => $s['budget_amount'],
            'ai_budget_amount' => $s['ai_budget_amount'], 'expense_policy' => $s['expense_policy'], 'billing_cadence' => $s['billing_cadence'], 'time_grouping' => $s['time_grouping'],
            'share_receipts_with_client' => $s['share_receipts_with_client'], 'department_id' => $s['department_id'], 'manager_member_id' => $s['manager_member_id'], 'projects_ref' => $s['projects_ref']];
}

/** ct_budget_status(): the four budgets with their use, the pace and the weeks left. Null when unseen. */
function engagement_budget(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM ct_budget_status(:id)');
    $st->execute(['id' => $id]);
    $b = $st->fetch();
    if ($b === false) {
        return null;
    }
    unset($b['engagement_id']);
    return $b;
}

function engagement_timeline(PDO $pdo, int $id, int $limit = 100): array
{
    return find_record_activity($pdo, 'engagement', $id, $limit);
}

/** The staffing rows (live first), through the view: the override only with rates.view. */
function find_staffing(PDO $pdo, int $engagementId): array
{
    $st = $pdo->prepare('SELECT staffing_id, engagement_id, member_id, member_name, member_kind, consulting_role_id, role_title, bill_rate_override, valid_from, valid_to, note, created_at,
                                (valid_from <= current_date AND (valid_to IS NULL OR valid_to > current_date)) AS is_current
                           FROM mcp_engagement_members WHERE engagement_id = :e ORDER BY (valid_to IS NULL OR valid_to > current_date) DESC, lower(member_name), valid_from DESC');
    $st->execute(['e' => $engagementId]);
    return array_map(static function (array $s): array {
        foreach (['staffing_id', 'engagement_id', 'member_id', 'consulting_role_id'] as $k) {
            $s[$k] = (int) $s[$k];
        }
        $s['is_current'] = (bool) $s['is_current'];
        return $s;
    }, $st->fetchAll());
}

function staffing_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, engagement_id, member_id, consulting_role_id, bill_rate_override, valid_from, valid_to, note FROM engagement_members WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['id', 'engagement_id', 'member_id', 'consulting_role_id'] as $k) {
        $r[$k] = (int) $r[$k];
    }
    return $r;
}

/** One row per live consulting role: the card's rate, this engagement's override; and each currently staffed person's resolved rate today with its source. */
function find_role_rates(PDO $pdo, int $engagementId): array
{
    $e = $pdo->prepare('SELECT e.rate_card_id, c.default_rate_card_id FROM engagements e JOIN clients c ON c.id = e.client_id WHERE e.id = :e');
    $e->execute(['e' => $engagementId]);
    $row = $e->fetch();
    $card = $row === false ? null : ($row['rate_card_id'] ?? $row['default_rate_card_id']);
    $st = $pdo->prepare('SELECT cr.consulting_role_id, cr.title AS role_title, x.bill_rate AS card_rate, r.bill_rate AS engagement_rate
                           FROM mcp_consulting_roles cr
                           LEFT JOIN mcp_rate_card_rates x ON x.consulting_role_id = cr.consulting_role_id AND x.rate_card_id = :card
                           LEFT JOIN mcp_engagement_role_rates r ON r.consulting_role_id = cr.consulting_role_id AND r.engagement_id = :e
                          WHERE cr.archived_at IS NULL OR r.bill_rate IS NOT NULL ORDER BY cr.sort_order, cr.title');
    $st->execute(['card' => $card, 'e' => $engagementId]);
    $roles = array_map(static function (array $x): array {
        $x['consulting_role_id'] = (int) $x['consulting_role_id'];
        return $x;
    }, $st->fetchAll());
    $people = [];
    if (has_right('rates.view')) {
        foreach (find_staffing($pdo, $engagementId) as $s) {
            if (!$s['is_current']) {
                continue;
            }
            $people[] = ['member_id' => $s['member_id'], 'member_name' => $s['member_name'], 'role_title' => $s['role_title']] + resolve_rate($pdo, $engagementId, $s['member_id'], date('Y-m-d'));
        }
    }
    return ['card_id' => $card === null ? null : (int) $card, 'card_name' => $card === null ? null : (string) one_value($pdo, 'SELECT name FROM rate_cards WHERE id = :id', ['id' => $card]), 'roles' => $roles, 'people' => $people];
}

/** ct_resolve_rate(): [bill_rate, rate_source, consulting_role_id]. */
function resolve_rate(PDO $pdo, int $engagementId, int $memberId, string $date): array
{
    $st = $pdo->prepare('SELECT * FROM ct_resolve_rate(:e, :m, :d)');
    $st->execute(['e' => $engagementId, 'm' => $memberId, 'd' => $date]);
    $r = $st->fetch() ?: ['bill_rate' => null, 'rate_source' => 'none', 'consulting_role_id' => null];
    return ['bill_rate' => $r['bill_rate'], 'rate_source' => $r['rate_source'] ?? 'none', 'consulting_role_id' => $r['consulting_role_id'] === null ? null : (int) $r['consulting_role_id']];
}

function find_milestones(PDO $pdo, int $engagementId): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_milestones WHERE engagement_id = :e ORDER BY sort_order, due_date NULLS LAST, milestone_id');
    $st->execute(['e' => $engagementId]);
    return array_map('milestone_cast', $st->fetchAll());
}

function milestone_cast(array $m): array
{
    foreach (['milestone_id', 'engagement_id', 'accepted_by', 'sort_order'] as $k) {
        $m[$k] = $m[$k] === null ? null : (int) $m[$k];
    }
    $m['billed'] = (bool) $m['billed'];
    return $m;
}

function find_milestone(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_milestones WHERE milestone_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : milestone_cast($r);
}

/** The alerts (open ones, or every one) of an engagement or of every engagement the caller may see. */
function find_budget_alerts(PDO $pdo, ?int $engagementId, bool $unacknowledged): array
{
    $sql = 'SELECT b.*, e.name AS engagement_name, e.client_name FROM mcp_budget_alerts b JOIN mcp_engagements e ON e.engagement_id = b.engagement_id WHERE 1 = 1'
        . ($engagementId === null ? '' : ' AND b.engagement_id = :e') . ($unacknowledged ? ' AND b.acknowledged_at IS NULL' : '') . ' ORDER BY b.written_at DESC, b.alert_id DESC LIMIT 200';
    $st = $pdo->prepare($sql);
    $st->execute($engagementId === null ? [] : ['e' => $engagementId]);
    return array_map(static function (array $a): array {
        foreach (['alert_id', 'engagement_id', 'threshold_pct', 'acknowledged_by'] as $k) {
            $a[$k] = $a[$k] === null ? null : (int) $a[$k];
        }
        return $a;
    }, $st->fetchAll());
}

/** The budget bars of every active engagement the caller may see (the budgets screen). */
function find_active_budgets(PDO $pdo): array
{
    $out = [];
    foreach (find_engagements($pdo, ['status' => ['active']], 200) as $e) {
        $e['budget'] = engagement_budget($pdo, $e['engagement_id']);
        $out[] = $e;
    }
    return $out;
}

/** The live consulting roles for a select: [{id, label}]. */
function vocabulary_pick_roles(): array
{
    return db()->query('SELECT consulting_role_id AS id, title AS label FROM mcp_consulting_roles WHERE archived_at IS NULL ORDER BY sort_order, title')->fetchAll();
}
