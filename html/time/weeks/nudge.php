<?php
declare(strict_types=1);
/** Action `timesheet_nudge_send` (log `timesheet.nudge`; confirm; `external_send` for agents): time.approve or billing.write. Queues the `missing_time` notice (the week and the empty days) once per person per week; slice 8 sends. */
require_once dirname(__DIR__, 3) . '/app/features/time/handler.php';
ct_handler_begin();
require_any_right('time.approve|billing.write');
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$member = ct_ref($pdo, req_has('member') ? 'member' : 'member_id', null, "SELECT 1 FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL AND member_kind = 'human'", 'person', $errors, false);
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$raw = trim((string) ($_REQUEST['week'] ?? ''));
$ws = $raw === '' ? (string) one_value($pdo, 'SELECT ct_week_start(current_date) - 7') : week_start_of($pdo, (request_date('week') ?: null) ?? refuse(422, 'The week is a date.'));
$row = null;
foreach (missing_time($pdo, $ws) as $m) {
    if ($m['member_id'] === $member) {
        $row = $m;
    }
}
if ($row === null) {
    refuse(422, 'That person has submitted the ' . strtolower(week_label($ws)) . ' (or is not expected to).');
}
$queued = ct_guard($pdo, static function () use ($pdo, $me, $member, $ws, $row): int {
    $pdo->beginTransaction();
    $n = queue_time_notice($pdo, $member, 'missing_time', $row['timesheet_id'], ['title' => 'Please submit your ' . strtolower(week_label($ws)), 'body' => $row['empty_weekdays'] . ' empty weekday' . ($row['empty_weekdays'] === 1 ? '' : 's') . ', ' . fmt_hours($row['hours_logged']) . ' logged. Open your timesheet: ' . rtrim((string) env('APP_URL', ''), '/') . '/time/?week=' . $ws,
        'dedupe' => 'nudge:' . $member . ':' . $ws]);
    if ($n > 0) {
        time_log($pdo, 'timesheet.nudge', $row['timesheet_id'], null, ['after' => ['member_id' => $member, 'week_start' => $ws, 'empty_weekdays' => $row['empty_weekdays'], 'hours_logged' => $row['hours_logged'], 'nudged_by' => $me]], $row['timesheet_id'] === null ? 'member' : 'timesheet', $row['timesheet_id'] ?? $member);
    }
    $pdo->commit();
    return $n;
});
ct_done($queued > 0 ? 'Nudged ' . $row['member_name'] . ' about the ' . strtolower(week_label($ws)) : $row['member_name'] . ' was already nudged about the ' . strtolower(week_label($ws)), $row['timesheet_id'], ct_land(return_path('/time/missing?week=' . $ws), $queued > 0 ? 'nudged' : 'already'), 'timesheetChanged', ['member_id' => $member, 'week_start' => $ws, 'queued' => $queued]);
