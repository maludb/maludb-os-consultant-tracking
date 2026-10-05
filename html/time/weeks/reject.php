<?php
declare(strict_types=1);
/** Action `timesheet_reject` (log `timesheet.reject`: the reason; confirm; `other` for agents): time.approve; a reason is required (the verb); the person is told with it. */
require_once dirname(__DIR__, 3) . '/app/features/time/handler.php';
ct_handler_begin();
require_right('time.approve');
$pdo = db();
$me = (int) current_member_id();
[$t, $member, $ws] = week_from_request($pdo, $me);
$t ?? refuse(404, 'Week not found.');
$reason = trim((string) req_val('reason'));
if ($reason === '') {
    ct_refuse_fields(['reason' => 'Say what must change (the person reads it).']);
}
ct_guard($pdo, static function () use ($pdo, $me, $member, $t, $reason): void {
    $pdo->beginTransaction();
    reject_timesheet($pdo, $t['timesheet_id'], $me, mb_substr($reason, 0, 1000));
    time_log($pdo, 'timesheet.reject', $t['timesheet_id'], null, ['before' => ['status' => 'submitted'], 'after' => ['status' => 'rejected', 'week_start' => $t['week_start'], 'member_id' => $member, 'reason' => mb_substr($reason, 0, 1000)]]);
    queue_time_notice($pdo, $member, 'timesheet_rejected', $t['timesheet_id'], ['title' => 'Your ' . strtolower(week_label($t['week_start'])) . ' was sent back', 'body' => mb_substr($reason, 0, 1000)]);
    $pdo->commit();
});
ct_done('Sent back the ' . strtolower(week_label($t['week_start'])), $t['timesheet_id'], ct_land(return_path('/time/weeks/' . $t['timesheet_id']), 'rejected'), 'timesheetChanged', ['timesheet_id' => $t['timesheet_id'], 'status' => 'rejected']);
