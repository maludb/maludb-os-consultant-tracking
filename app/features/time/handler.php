<?php
declare(strict_types=1);

/** The prelude of a time handler: the feature's files; the entry or the week a request names (seen → else 404); the gates; time_log() with timesheet_id and engagement_id on every row. */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once dirname(__DIR__) . '/settings/queries.php';
require_once dirname(__DIR__) . '/activity/queries.php';
require_once dirname(__DIR__) . '/engagements/queries.php';

/** The week the request names: `timesheet` (an id) or `week` (a Monday, or a date in the week) + `member` (a manager's); this week by default. [timesheet|null, member_id, week_start] */
function week_from_request(PDO $pdo, int $me): array
{
    $id = request_integer('timesheet') ?? request_integer('timesheet_id') ?? request_integer('id');
    if ($id !== null) {
        $t = find_timesheet_by_id($pdo, $id) ?? refuse(404, 'Week not found.');
        return [$t, $t['member_id'], $t['week_start']];
    }
    $member = request_integer('member') ?? request_integer('member_id') ?? $me;
    if ($member !== $me && !has_right('time.approve') && !db_bool($pdo, 'SELECT ct_sees_all()')) {
        refuse(404, 'Week not found.');
    }
    $raw = trim((string) ($_REQUEST['week'] ?? ''));
    $date = $raw === '' ? date('Y-m-d') : ((request_date('week') ?: null) ?? refuse(422, 'The week is a date (its Monday).'));
    $ws = week_start_of($pdo, $date);
    return [find_timesheet($pdo, $member, $ws), $member, $ws];
}

/** The entry the request names, through the view: 404 when unseen. */
function entry_from_request(PDO $pdo): array
{
    $id = request_integer('entry') ?? request_integer('entry_id') ?? request_integer('id') ?? refuse(422, 'Say which entry.');
    return find_time_entry($pdo, $id) ?? refuse(404, 'Time entry not found.');
}

/** May the caller act on this person's time: their own, or a manager (time.approve) of an engagement the person is on, or Billing/admin. */
function may_act_for(PDO $pdo, int $me, int $memberId, ?int $engagementId = null): bool
{
    if ($memberId === $me) {
        return true;
    }
    if (!has_right('time.approve')) {
        return false;
    }
    if ($engagementId !== null) {
        return can_manage_engagement($engagementId);
    }
    return db_bool($pdo, 'SELECT ct_sees_all() OR EXISTS (SELECT 1 FROM engagement_members em WHERE em.member_id = :m AND em.engagement_id IN (SELECT ct_managed_engagement_ids()))', ['m' => $memberId]);
}

/** log_activity with the week's keys. */
function time_log(PDO $pdo, string $action, ?int $timesheetId, ?int $engagementId, array $opts = [], ?string $entityType = null, ?int $entityId = null): void
{
    log_activity($pdo, $action, $entityType ?? ($engagementId !== null && $entityType === null && $timesheetId === null ? 'engagement' : 'timesheet'), $entityId ?? $timesheetId, $opts + ['timesheet_id' => $timesheetId, 'engagement_id' => $engagementId]);
}

/** The live activity types for the form: [{id, label, billable_default}] */
function activity_types_for_pick(PDO $pdo): array
{
    return array_map(static fn (array $a): array => ['id' => (int) $a['activity_type_id'], 'label' => $a['label'], 'billable_default' => (bool) $a['billable_default']],
        $pdo->query('SELECT activity_type_id, label, billable_default FROM mcp_activity_types WHERE archived_at IS NULL ORDER BY sort_order, label')->fetchAll());
}
