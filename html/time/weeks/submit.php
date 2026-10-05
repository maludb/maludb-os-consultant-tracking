<?php
declare(strict_types=1);
/** Action `timesheet_submit` (log `timesheet.submit`: week_start, member_id, hours, entries; confirm): own or manage. `week` (a Monday or an id) + `member`; this week by default. The managers of the engagements in it are told. */
require_once dirname(__DIR__, 3) . '/app/features/time/handler.php';
ct_handler_begin();
require_right('time.write');
$pdo = db();
$me = (int) current_member_id();
[$t, $member, $ws] = week_from_request($pdo, $me);
if ($member !== $me && !may_act_for($pdo, $me, $member)) {
    refuse(403, 'You may not submit that person\'s week.');
}
if ($t === null) {                                               // an empty week (a week off) may be submitted: the row is made first
    if ($ws > date('Y-m-d')) {
        refuse(422, 'The week of ' . format_date($ws) . ' has not started.');
    }
    $pdo->prepare('SELECT ct_timesheet_for(:m, :d)')->execute(['m' => $member, 'd' => $ws]);
    $t = find_timesheet($pdo, $member, $ws) ?? refuse(404, 'Week not found.');
}
$row = ct_guard($pdo, static function () use ($pdo, $me, $member, $t): array {
    $pdo->beginTransaction();
    $row = submit_timesheet($pdo, $t['timesheet_id'], $me);
    $entries = (int) one_value($pdo, 'SELECT count(*) FROM time_entries WHERE timesheet_id = :t', ['t' => $t['timesheet_id']]);
    time_log($pdo, 'timesheet.submit', $t['timesheet_id'], null, ['before' => ['status' => $t['status']], 'after' => ['status' => 'submitted', 'week_start' => $t['week_start'], 'member_id' => $member, 'hours' => $row['total_hours'], 'entries' => $entries, 'submitted_by' => $me]]);
    $name = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $member]);
    foreach (week_managers($pdo, $t['timesheet_id'], $member) as $mgr) {
        queue_time_notice($pdo, $mgr, 'timesheet_submitted', $t['timesheet_id'], ['title' => $name . ' submitted the ' . strtolower(week_label($t['week_start'])) . ' (' . fmt_hours($row['total_hours']) . ')', 'body' => 'Open it to approve or reject: ' . rtrim((string) env('APP_URL', ''), '/') . '/time/weeks/' . $t['timesheet_id'], 'dedupe' => 'ts-submit:' . $t['timesheet_id'] . ':' . substr((string) $row['submitted_at'], 0, 19)]);
    }
    $pdo->commit();
    return $row;
});
ct_done('Submitted the ' . strtolower(week_label($t['week_start'])) . ($member !== $me ? ' for ' . one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $member]) : '') . ' (' . fmt_hours($row['total_hours']) . ')', $t['timesheet_id'], ct_land(return_path('/time/?week=' . $t['week_start'] . ($member !== $me ? '&member=' . $member : '')), 'submitted'), 'timesheetChanged', ['timesheet_id' => $t['timesheet_id'], 'status' => 'submitted']);
