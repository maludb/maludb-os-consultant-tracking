<?php
declare(strict_types=1);
/** Action `timesheet_reopen` (log `timesheet.reopen`): own or manage; a submitted week nobody approved anything in (the verb decides). */
require_once dirname(__DIR__, 3) . '/app/features/time/handler.php';
ct_handler_begin();
require_right('time.write');
$pdo = db();
$me = (int) current_member_id();
[$t, $member, $ws] = week_from_request($pdo, $me);
$t ?? refuse(404, 'Week not found.');
if ($member !== $me && !may_act_for($pdo, $me, $member)) {
    refuse(403, 'You may not reopen that person\'s week.');
}
ct_guard($pdo, static function () use ($pdo, $me, $member, $t): void {
    $pdo->beginTransaction();
    reopen_timesheet($pdo, $t['timesheet_id'], $me);
    time_log($pdo, 'timesheet.reopen', $t['timesheet_id'], null, ['before' => ['status' => 'submitted'], 'after' => ['status' => 'open', 'week_start' => $t['week_start'], 'member_id' => $member, 'reopened_by' => $me]]);
    $pdo->commit();
});
ct_done('Reopened the ' . strtolower(week_label($t['week_start'])), $t['timesheet_id'], ct_land(return_path('/time/?week=' . $t['week_start'] . ($member !== $me ? '&member=' . $member : '')), 'reopened'), 'timesheetChanged', ['timesheet_id' => $t['timesheet_id'], 'status' => 'open']);
