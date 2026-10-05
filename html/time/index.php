<?php
declare(strict_types=1);
/** /time/?week=&member= — MY WEEK (screen `timesheet`): the day list on a phone, the grid on a desktop, the timer bar, Submit / Reopen; a manager's view of someone's week with ?member=. time.write (a Finance reader has none). */
require_once dirname(__DIR__, 2) . '/app/features/time/handler.php';
require_right('time.write');
require_human();
$pdo = db();
$me = (int) current_member_id();
[$t, $member, $ws] = week_from_request($pdo, $me);
$entries = $t === null ? [] : timesheet_entries($pdo, $t['timesheet_id']);
$shape = week_shape($entries, $ws);
$timer = $member === $me ? my_timer($pdo, $me) : null;
$settings = find_settings($pdo);
$loggable = $member === $me ? loggable_engagements($pdo, $me, date('Y-m-d')) : [];
$status = $t['status'] ?? 'open';
$may = ['edit' => $member === $me || may_act_for($pdo, $me, $member), 'submit' => in_array($status, ['open', 'rejected'], true) && ($member === $me || has_right('time.approve')) && $ws <= date('Y-m-d'),
        'reopen' => $status === 'submitted' && $t !== null && $t['submitted_entries'] > 0 && !array_filter($entries, fn ($e) => $e['status'] === 'approved'), 'rates' => has_right('rates.view'), 'approve' => has_right('time.approve') && $member !== $me];
$who = $member === $me ? null : (string) one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :m', ['m' => $member]);
if ($member !== $me && $who === null) {
    refuse(404, 'Week not found.');
}
log_activity($pdo, 'screen.view', 'timesheet', $t['timesheet_id'] ?? null, ['screen' => 'timesheet', 'after' => ['week_start' => $ws, 'member_id' => $member], 'timesheet_id' => $t['timesheet_id'] ?? null]);
if (wants_json()) {
    respond_screen(['week_start' => $ws, 'label' => week_label($ws), 'member_id' => $member, 'timesheet' => $t === null ? null : present_timesheet($t), 'status' => $status, 'days' => array_values(array_map(static fn (array $d): array => ['date' => $d['day']['date'], 'total' => number_format($d['total'], 2, '.', ''), 'entries' => array_map('present_time_entry', $d['entries'])], $shape['days'])),
        'rows' => $shape['rows'], 'timer' => present_timer($timer), 'loggable' => $loggable, 'increment' => $settings['hours_increment'], 'may' => $may]);
}
$notices = ['saved' => ['success', 'Logged.'], 'deleted' => ['success', 'The entry was removed.'], 'submitted' => ['success', 'The week is submitted.'], 'reopened' => ['info', 'The week is open again.'],
            'timer_started' => ['info', 'The timer is running.'], 'timer_stopped' => ['success', 'The timer is stopped; its entry is in the week.']];
render_screen($member === $me ? 'My timesheet' : $who . '\'s week', view('time/index.php', ['t' => $t, 'ws' => $ws, 'member' => $member, 'who' => $who, 'shape' => $shape, 'entries' => $entries, 'timer' => $timer, 'loggable' => $loggable,
    'settings' => $settings, 'status' => $status, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'activities' => activity_types_for_pick($pdo), 'notice' => ct_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'timesheet', 'screen' => 'timesheet', 'entity' => 'timesheet', 'recordId' => $t === null ? '' : (string) $t['timesheet_id']]);
