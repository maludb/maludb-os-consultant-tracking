<?php
declare(strict_types=1);
/** Action `timer_stop` (log `timer.stop`: hours, entry id): own. The running timer becomes a draft entry rounded UP to the increment (the verb). */
require_once dirname(__DIR__, 3) . '/app/features/time/handler.php';
ct_handler_begin();
require_right('time.write');
$pdo = db();
$me = (int) current_member_id();
$entry = ct_guard($pdo, static function () use ($pdo, $me): array {
    $pdo->beginTransaction();
    $entry = stop_timer($pdo, $me);
    time_log($pdo, 'timer.stop', (int) $entry['timesheet_id'], (int) $entry['engagement_id'], ['after' => ['engagement_id' => (int) $entry['engagement_id'], 'hours' => $entry['hours'], 'entry_id' => (int) $entry['id']]], 'time_entry', (int) $entry['id']);
    $pdo->commit();
    return $entry;
});
$ws = week_start_of($pdo, $entry['entry_date']);
$num = (string) one_value($pdo, 'SELECT number FROM engagements WHERE id = :e', ['e' => (int) $entry['engagement_id']]);
ct_done('Stopped: ' . fmt_hours($entry['hours']) . ' on ' . $num, (int) $entry['id'], ct_land(return_path('/time/?week=' . $ws), 'timer_stopped', 'time-entry-row-' . (int) $entry['id']), 'timesheetChanged', ['entry_id' => (int) $entry['id'], 'hours' => $entry['hours'], 'timesheet_id' => (int) $entry['timesheet_id']]);
