<?php
declare(strict_types=1);
/** Action `time_entry_delete` (log `time_entry.delete`; confirm; `deletion` for agents): own or manage; a draft or rejected entry (the trigger refuses the rest). */
require_once dirname(__DIR__, 3) . '/app/features/time/handler.php';
ct_handler_begin();
require_right('time.write');
$pdo = db();
$me = (int) current_member_id();
$e = entry_from_request($pdo);
if (!may_act_for($pdo, $me, $e['member_id'], $e['engagement_id'])) {
    refuse(403, 'You may not remove that entry.');
}
$was = ct_guard($pdo, static function () use ($pdo, $me, $e): array {
    $pdo->beginTransaction();
    $was = delete_time_entry($pdo, $e['entry_id'], $me);
    time_log($pdo, 'time_entry.delete', $was['timesheet_id'], $was['engagement_id'], ['before' => ['entry_date' => $was['entry_date'], 'engagement_id' => $was['engagement_id'], 'hours' => $was['hours'], 'billable' => $was['billable'], 'activity_type_id' => $was['activity_type_id'], 'member_id' => $e['member_id']]], 'time_entry', $e['entry_id']);
    $pdo->commit();
    return $was;
});
$ws = week_start_of($pdo, $was['entry_date']);
ct_done('Removed ' . fmt_hours($was['hours']) . ' on ' . $e['engagement_number'], $e['entry_id'], ct_land(return_path('/time/?week=' . $ws . ($e['member_id'] !== $me ? '&member=' . $e['member_id'] : '')), 'deleted'), 'timesheetChanged', ['timesheet_id' => $was['timesheet_id']]);
