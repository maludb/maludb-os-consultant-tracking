<?php
declare(strict_types=1);

/**
 * Time — THE EXEMPLAR (slice 3): a person's week (timesheets), its entries, the timer. Reads through mcp_timesheets / mcp_time_entries /
 * mcp_time_timers — the views decide who sees whose hours, and whether a bill rate, a bill value or a cost comes with them; this code never
 * blanks a column itself. Every write goes to the base table or a verb, and the database is the referee.
 */

const WEEK_STATUSES = ['open' => 'Open', 'submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected'];
const ENTRY_STATUSES = ['draft' => 'Draft', 'submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected', 'written_off' => 'Written off'];
const HOUR_CHIPS = ['0.5', '1', '2', '4', '8'];

/** The Monday (the settings' week start) of a date: ct_week_start(). */
function week_start_of(PDO $pdo, string $date): string
{
    return (string) one_value($pdo, 'SELECT ct_week_start(:d)', ['d' => $date]);
}

/** The seven days of a week: [{date, dow, label}] */
function week_days(string $weekStart): array
{
    $out = [];
    $d = new DateTimeImmutable($weekStart);
    for ($i = 0; $i < 7; $i++) {
        $x = $d->modify('+' . $i . ' days');
        $out[] = ['date' => $x->format('Y-m-d'), 'dow' => $x->format('D'), 'label' => $x->format('D j'), 'weekend' => (int) $x->format('N') >= 6, 'today' => $x->format('Y-m-d') === date('Y-m-d')];
    }
    return $out;
}

function timesheet_cast(array $t): array
{
    foreach (['timesheet_id', 'member_id', 'approved_by', 'rejected_by', 'submitted_entries'] as $k) {
        $t[$k] = ($t[$k] ?? null) === null ? null : (int) $t[$k];
    }
    return $t;
}

/** A person's week by its Monday, through the view: null when no row yet (the page shows an empty open week). */
function find_timesheet(PDO $pdo, int $memberId, string $weekStart): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_timesheets WHERE member_id = :m AND week_start = :w');
    $st->execute(['m' => $memberId, 'w' => $weekStart]);
    $r = $st->fetch();
    return $r === false ? null : timesheet_cast($r);
}

function find_timesheet_by_id(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_timesheets WHERE timesheet_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : timesheet_cast($r);
}

function entry_cast(array $e): array
{
    foreach (['entry_id', 'timesheet_id', 'member_id', 'engagement_id', 'activity_type_id', 'consulting_role_id', 'department_id', 'approved_by', 'written_off_by'] as $k) {
        $e[$k] = ($e[$k] ?? null) === null ? null : (int) $e[$k];
    }
    $e['billable'] = (bool) $e['billable'];
    $e['billed'] = (bool) ($e['billed'] ?? false);
    return $e;
}

/** A week's entries, by day then id, through the view. */
function timesheet_entries(PDO $pdo, int $timesheetId): array
{
    $st = $pdo->prepare('SELECT * FROM mcp_time_entries WHERE timesheet_id = :t ORDER BY entry_date, entry_id');
    $st->execute(['t' => $timesheetId]);
    return array_map('entry_cast', $st->fetchAll());
}

function find_time_entry(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_time_entries WHERE entry_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : entry_cast($r);
}

/** The entries grouped by day: [date => [entries]] with the day's total; the grid: [engagement_id => [date => hours]]. One query, two shapes. */
function week_shape(array $entries, string $weekStart): array
{
    $days = [];
    $grid = [];
    $rows = [];
    foreach (week_days($weekStart) as $d) {
        $days[$d['date']] = ['day' => $d, 'entries' => [], 'total' => 0.0];
    }
    foreach ($entries as $e) {
        if ($e['status'] === 'rejected') {
            // rejected entries stay on the list (editable) but a manager's totals count what stands; the day list shows them with the chip
        }
        $days[$e['entry_date']]['entries'][] = $e;
        $days[$e['entry_date']]['total'] += (float) $e['hours'];
        $rows[$e['engagement_id']] ??= ['engagement_id' => $e['engagement_id'], 'number' => $e['engagement_number'], 'name' => $e['engagement_name'], 'total' => 0.0];
        $rows[$e['engagement_id']]['total'] += (float) $e['hours'];
        $grid[$e['engagement_id']][$e['entry_date']] = ($grid[$e['engagement_id']][$e['entry_date']] ?? 0.0) + (float) $e['hours'];
    }
    return ['days' => $days, 'rows' => array_values($rows), 'grid' => $grid];
}

/** The engagements a person may log on for a date (ct_can_log_on: active, started, staffed then — or its manager), as the view shows them. */
function loggable_engagements(PDO $pdo, int $memberId, string $date): array
{
    $st = $pdo->prepare('SELECT engagement_id, number, name, client_name, currency FROM mcp_engagements e WHERE ct_can_log_on(e.engagement_id, :m, :d) ORDER BY number');
    $st->execute(['m' => $memberId, 'd' => $date]);
    return array_map(static function (array $e): array {
        $e['engagement_id'] = (int) $e['engagement_id'];
        return $e;
    }, $st->fetchAll());
}

/** The caller's running timer, or null. */
function my_timer(PDO $pdo, int $memberId): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_time_timers WHERE member_id = :m');
    $st->execute(['m' => $memberId]);
    $t = $st->fetch();
    if ($t === false) {
        return null;
    }
    $t['member_id'] = (int) $t['member_id'];
    $t['engagement_id'] = (int) $t['engagement_id'];
    $t['activity_type_id'] = $t['activity_type_id'] === null ? null : (int) $t['activity_type_id'];
    return $t;
}

/** The submitted weeks awaiting the approver: weeks with submitted entries on the engagements they manage, oldest first, with the people and the engagements in them. */
function timesheets_pending(PDO $pdo, int $approverId, ?int $memberId = null): array
{
    $sql = "SELECT t.*, (SELECT count(*) FROM mcp_time_entries e WHERE e.timesheet_id = t.timesheet_id AND e.status = 'submitted' AND e.engagement_id IN (SELECT ct_managed_engagement_ids())) AS mine_to_approve,
                   (SELECT string_agg(DISTINCT e.engagement_number, ', ' ORDER BY e.engagement_number) FROM mcp_time_entries e WHERE e.timesheet_id = t.timesheet_id AND e.status = 'submitted') AS engagements_in
              FROM mcp_timesheets t WHERE t.status = 'submitted' AND t.member_id <> :me" . ($memberId === null ? '' : ' AND t.member_id = :member') . '
               AND EXISTS (SELECT 1 FROM mcp_time_entries e WHERE e.timesheet_id = t.timesheet_id AND e.status = \'submitted\' AND e.engagement_id IN (SELECT ct_managed_engagement_ids()))
             ORDER BY t.week_start, t.member_name';
    $st = $pdo->prepare($sql);
    $st->execute(['me' => $approverId] + ($memberId === null ? [] : ['member' => $memberId]));
    return array_map(static function (array $t): array {
        $t = timesheet_cast($t);
        $t['mine_to_approve'] = (int) $t['mine_to_approve'];
        return $t;
    }, $st->fetchAll());
}

/** Everyone's hours per day for a week (people down, days across), of what the caller may see; an optional practice. */
function team_time(PDO $pdo, string $weekStart, ?int $departmentId = null): array
{
    $st = $pdo->prepare('SELECT e.member_id, e.member_name, e.entry_date, sum(e.hours) AS hours FROM mcp_time_entries e
                          WHERE e.entry_date >= :w AND e.entry_date < CAST(:w2 AS date) + 7 AND e.status <> \'rejected\'' . ($departmentId === null ? '' : ' AND e.department_id = :d') . '
                          GROUP BY e.member_id, e.member_name, e.entry_date ORDER BY e.member_name, e.entry_date');
    $st->execute(['w' => $weekStart, 'w2' => $weekStart] + ($departmentId === null ? [] : ['d' => $departmentId]));
    $people = [];
    foreach ($st->fetchAll() as $r) {
        $m = (int) $r['member_id'];
        $people[$m] ??= ['member_id' => $m, 'member_name' => $r['member_name'], 'days' => [], 'total' => 0.0, 'status' => null, 'timesheet_id' => null];
        $people[$m]['days'][$r['entry_date']] = (float) $r['hours'];
        $people[$m]['total'] += (float) $r['hours'];
    }
    foreach ($people as $m => &$p) {
        $t = find_timesheet($pdo, $m, $weekStart);
        $p['status'] = $t['status'] ?? 'open';
        $p['timesheet_id'] = $t['timesheet_id'] ?? null;
    }
    unset($p);
    return array_values($people);
}

/** ct_missing_time(): who has not submitted the week (last week by default) and which weekdays are empty. */
function missing_time(PDO $pdo, ?string $weekStart): array
{
    $st = $pdo->prepare('SELECT * FROM ct_missing_time(:w)');
    $st->execute(['w' => $weekStart]);
    return array_map(static function (array $r): array {
        $r['member_id'] = (int) $r['member_id'];
        $r['timesheet_id'] = $r['timesheet_id'] === null ? null : (int) $r['timesheet_id'];
        $r['empty_weekdays'] = (int) $r['empty_weekdays'];
        $r['nudged'] = false;
        return $r;
    }, $st->fetchAll());
}

/** The hours written off in a period, with reasons. $filters: from, to, engagement_id. */
function written_off(PDO $pdo, array $filters): array
{
    $where = ["e.status = 'written_off'"];
    $args = [];
    if (($filters['from'] ?? null) !== null) { $where[] = 'e.entry_date >= :from'; $args['from'] = $filters['from']; }
    if (($filters['to'] ?? null) !== null) { $where[] = 'e.entry_date <= :to'; $args['to'] = $filters['to']; }
    if (($filters['engagement_id'] ?? null) !== null) { $where[] = 'e.engagement_id = :e'; $args['e'] = (int) $filters['engagement_id']; }
    $st = $pdo->prepare('SELECT * FROM mcp_time_entries e WHERE ' . implode(' AND ', $where) . ' ORDER BY e.written_off_at DESC, e.entry_id DESC LIMIT 500');
    $st->execute($args);
    return array_map('entry_cast', $st->fetchAll());
}

function week_timeline(PDO $pdo, int $timesheetId, int $limit = 100): array
{
    return find_record_activity($pdo, 'timesheet', $timesheetId, $limit);
}

/** The managers to tell when a week is submitted: the managers of the engagements in it (never the person). */
function week_managers(PDO $pdo, int $timesheetId, int $exceptMember): array
{
    $st = $pdo->prepare("SELECT DISTINCT g.manager_member_id FROM time_entries e JOIN engagements g ON g.id = e.engagement_id WHERE e.timesheet_id = :t AND g.manager_member_id IS NOT NULL AND g.manager_member_id <> :m");
    $st->execute(['t' => $timesheetId, 'm' => $exceptMember]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** "Week of Mar 2–8". */
function week_label(string $weekStart): string
{
    $a = new DateTimeImmutable($weekStart);
    $b = $a->modify('+6 days');
    return 'Week of ' . $a->format('M j') . '–' . ($a->format('M') === $b->format('M') ? $b->format('j') : $b->format('M j')) . ($a->format('Y') !== date('Y') ? ', ' . $a->format('Y') : '');
}
