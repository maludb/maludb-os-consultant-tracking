<?php
declare(strict_types=1);
/** Action `timer_start` (log `timer.start`: engagement_id): own. One per person — a running one is stopped into a draft entry first (the verb). */
require_once dirname(__DIR__, 3) . '/app/features/time/handler.php';
ct_handler_begin();
require_right('time.write');
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$engagement = ct_ref($pdo, req_has('engagement') ? 'engagement' : 'engagement_id', null, 'SELECT 1 FROM engagements WHERE id = :id', 'engagement', $errors, false);
$activity = ct_ref($pdo, req_has('activity') ? 'activity' : 'activity_type_id', null, 'SELECT 1 FROM activity_types WHERE id = :id AND archived_at IS NULL', 'activity type', $errors);
$note = (string) req_val('note') === '' ? null : mb_substr((string) req_val('note'), 0, 1000);
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$stopped = my_timer($pdo, $me);
ct_guard($pdo, static function () use ($pdo, $me, $engagement, $activity, $note, $stopped): void {
    $pdo->beginTransaction();
    if ($stopped !== null) {
        $entry = stop_timer($pdo, $me);
        time_log($pdo, 'timer.stop', (int) $entry['timesheet_id'], (int) $entry['engagement_id'], ['after' => ['engagement_id' => (int) $entry['engagement_id'], 'hours' => $entry['hours'], 'entry_id' => (int) $entry['id'], 'then_started' => $engagement]], 'time_entry', (int) $entry['id']);
    }
    start_timer($pdo, $me, $engagement, $activity, $note);
    time_log($pdo, 'timer.start', null, $engagement, ['after' => ['engagement_id' => $engagement, 'activity_type_id' => $activity]], 'engagement', $engagement);
    $pdo->commit();
});
$num = (string) one_value($pdo, 'SELECT number FROM engagements WHERE id = :e', ['e' => $engagement]);
ct_done('Timer running on ' . $num . ($stopped !== null ? ' (the one on ' . $stopped['engagement_number'] . ' stopped into the week)' : ''), $engagement, ct_land(return_path('/time/'), 'timer_started', 'timer-bar'), 'timesheetChanged', ['engagement_id' => $engagement]);
