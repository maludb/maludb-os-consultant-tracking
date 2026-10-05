<?php
declare(strict_types=1);
/**
 * Action `timesheet_approve` (log `timesheet.approve`: approved, left_submitted, week_status; confirm; `other` for agents): time.approve. Approves the entries on the engagements the approver
 * manages, snapshotting the rate and the cost (the verb); the week turns approved when nothing is left submitted; then the budget check on each engagement touched and the person's notice.
 */
require_once dirname(__DIR__, 3) . '/app/features/time/handler.php';
require_once dirname(__DIR__, 3) . '/app/features/engagements/write.php';
ct_handler_begin();
require_right('time.approve');
$pdo = db();
$me = (int) current_member_id();
[$t, $member, $ws] = week_from_request($pdo, $me);
$t ?? refuse(404, 'Week not found.');
$r = ct_guard($pdo, static function () use ($pdo, $me, $member, $t): array {
    $pdo->beginTransaction();
    $r = approve_timesheet($pdo, $t['timesheet_id'], $me);
    if ($r['approved'] === 0) {
        throw new DomainException('Nothing in this week is on an engagement you manage.');
    }
    time_log($pdo, 'timesheet.approve', $t['timesheet_id'], count($r['engagements']) === 1 ? $r['engagements'][0] : null, ['before' => ['status' => 'submitted'], 'after' => ['status' => $r['week_status'], 'week_start' => $t['week_start'], 'member_id' => $member, 'approved' => $r['approved'], 'left_submitted' => $r['left_submitted'], 'engagements' => $r['engagements']]]);
    foreach ($r['engagements'] as $eid) {
        check_budget_alerts($pdo, $eid);
    }
    if ($r['week_status'] === 'approved') {
        queue_time_notice($pdo, $member, 'timesheet_approved', $t['timesheet_id'], ['title' => 'Your ' . strtolower(week_label($t['week_start'])) . ' was approved', 'body' => fmt_hours($t['total_hours']) . ' approved.', 'dedupe' => 'ts-approved:' . $t['timesheet_id']]);
    }
    $pdo->commit();
    return $r;
});
$left = $r['left_submitted'] > 0 ? ' — ' . $r['left_submitted'] . ' entr' . ($r['left_submitted'] === 1 ? 'y' : 'ies') . ' on other engagements await their manager' : '';
ct_done('Approved ' . $r['approved'] . ' entr' . ($r['approved'] === 1 ? 'y' : 'ies') . ' of the ' . strtolower(week_label($t['week_start'])) . $left, $t['timesheet_id'], ct_land(return_path('/time/weeks/' . $t['timesheet_id']), $r['week_status'] === 'approved' ? 'approved' : 'partly'), 'timesheetChanged', ['approved' => $r['approved'], 'left_submitted' => $r['left_submitted'], 'week_status' => $r['week_status']]);
