<?php
declare(strict_types=1);
/** /time/weeks/{id} — one week as its manager sees it (screen `timesheet-view`): the entries by day with the rate that will apply and the bill value (rates), the cost after approval (cost); Approve / Reject / Reopen; write-off; the timeline. */
require_once dirname(__DIR__, 3) . '/app/features/time/handler.php';
require_any_right('time.write|reports.view');
require_human();
$pdo = db();
$me = (int) current_member_id();
$t = find_timesheet_by_id($pdo, request_integer('id') ?? request_integer('timesheet') ?? refuse(404, 'Week not found.')) ?? refuse(404, 'Week not found.');
$entries = timesheet_entries($pdo, $t['timesheet_id']);
$rates = has_right('rates.view');
foreach ($entries as &$e) {                                       // the rate that WILL apply (not yet approved): resolved now, for those who see rates
    $e['will_apply'] = $rates && $e['status'] !== 'approved' && $e['status'] !== 'written_off' && $e['billable'] ? resolve_rate($pdo, $e['engagement_id'], $e['member_id'], $e['entry_date']) : null;
    $e['i_manage'] = can_manage_engagement($e['engagement_id']);
}
unset($e);
$shape = week_shape($entries, $t['week_start']);
$approved = array_filter($entries, fn ($e) => $e['status'] === 'approved');
$mine = array_filter($entries, fn ($e) => $e['status'] === 'submitted' && $e['i_manage']);
$others = max(0, $t['submitted_entries'] - count($mine));            // submitted entries on engagements the caller does not manage (some the view may not even show)
$may = ['approve' => has_right('time.approve') && $t['member_id'] !== $me && $t['status'] === 'submitted' && $mine !== [], 'reject' => has_right('time.approve') && $t['member_id'] !== $me && $t['status'] === 'submitted',
        'reopen' => $t['status'] === 'submitted' && $approved === [] && ($t['member_id'] === $me || has_right('time.approve')), 'write_off' => has_right('time.approve'), 'rates' => $rates, 'cost' => has_right('cost.view'),
        'own' => $t['member_id'] === $me, 'log' => may_act_for($pdo, $me, $t['member_id'])];
$timeline = week_timeline($pdo, $t['timesheet_id'], 50);
log_activity($pdo, 'screen.view', 'timesheet', $t['timesheet_id'], ['screen' => 'timesheet-view', 'after' => ['week_start' => $t['week_start'], 'member_id' => $t['member_id']], 'timesheet_id' => $t['timesheet_id']]);
if (wants_json()) {
    respond_screen(['timesheet' => present_timesheet($t), 'days' => array_values(array_map(static fn (array $d): array => ['date' => $d['day']['date'], 'total' => number_format($d['total'], 2, '.', ''), 'entries' => array_map(static fn (array $e): array => present_time_entry($e) + ['will_apply' => $e['will_apply'], 'i_manage' => $e['i_manage']], $d['entries'])], $shape['days'])),
        'rows' => $shape['rows'], 'others_submitted' => $others, 'timeline' => array_map('present_activity_row', $timeline), 'may' => $may]);
}
$notices = ['approved' => ['success', 'The week is approved.'], 'partly' => ['info', 'Approved what you manage; the rest awaits its manager.'], 'rejected' => ['warning', 'The week was sent back with your reason.'], 'reopened' => ['info', 'The week is open again.'],
            'written_off' => ['success', 'The entry is written off.']];
render_screen($t['member_name'] . ' · ' . week_label($t['week_start']), view('time/week.php', ['t' => $t, 'shape' => $shape, 'entries' => $entries, 'others' => $others, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'timeline' => $timeline, 'me' => $me,
    'notice' => ct_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => has_right('time.approve') ? 'time-approvals' : 'timesheet', 'screen' => 'timesheet-view', 'entity' => 'timesheet', 'recordId' => (string) $t['timesheet_id']]);
