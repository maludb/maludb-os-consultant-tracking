<?php
declare(strict_types=1);
/** /time/entries/new?engagement=&date=&member= and /time/entries/{id}/edit (screens `time-entry-add`, `time-entry-edit`): time.write; a manager logs for someone with ?member=. */
require_once dirname(__DIR__, 3) . '/app/features/time/handler.php';
require_right('time.write');
require_human();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('id') ?? request_integer('entry');
$cur = null;
$member = request_integer('member') ?? $me;
if ($id !== null) {
    $cur = find_time_entry($pdo, $id) ?? refuse(404, 'Time entry not found.');
    $member = $cur['member_id'];
    if (!in_array($cur['status'], ['draft', 'rejected'], true)) {
        refuse(422, 'This entry is ' . $cur['status'] . '; a manager rejects the week for it to change.');
    }
}
if (!may_act_for($pdo, $me, $member)) {
    refuse(403, 'You may not log time for that person.');
}
$date = $cur['entry_date'] ?? (request_date('date') ?: date('Y-m-d'));
$ws = week_start_of($pdo, $date);
$loggable = loggable_engagements($pdo, $member, $date);
$settings = find_settings($pdo);
$screen = $cur === null ? 'time-entry-add' : 'time-entry-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['entry' => $cur === null ? null : present_time_entry($cur), 'member_id' => $member, 'date' => $date, 'week_start' => $ws, 'engagements' => $loggable, 'activities' => activity_types_for_pick($pdo), 'increment' => $settings['hours_increment'], 'max_hours_per_day' => $settings['max_hours_per_day'], 'chips' => HOUR_CHIPS]);
}
render_screen($cur === null ? 'Log time' : 'Change an entry', view('time/form.php', ['cur' => $cur, 'member' => $member, 'me' => $me, 'date' => $date, 'ws' => $ws, 'days' => week_days($ws), 'loggable' => $loggable, 'prefill' => request_integer('engagement'),
    'activities' => activity_types_for_pick($pdo), 'settings' => $settings, 'here' => here_url()]), ['activeNav' => 'timesheet', 'screen' => $screen, 'entity' => 'time_entry', 'recordId' => $id === null ? '' : (string) $id]);
