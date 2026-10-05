<?php
declare(strict_types=1);
/** Action `time_entry_write_off` (log `time_entry.write_off`: hours, reason, bill value, currency; confirm; `money_out` for agents): manage; an approved, unbilled entry with a reason (the verb decides). */
require_once dirname(__DIR__, 3) . '/app/features/time/handler.php';
ct_handler_begin();
require_right('time.approve');
$pdo = db();
$me = (int) current_member_id();
$e = entry_from_request($pdo);
if (!can_manage_engagement($e['engagement_id'])) {
    refuse(403, 'You do not manage ' . $e['engagement_number'] . '.');
}
$reason = trim((string) req_val('reason'));
if ($reason === '') {
    ct_refuse_fields(['reason' => 'A write-off needs a reason.']);
}
$row = ct_guard($pdo, static function () use ($pdo, $me, $e, $reason): array {
    $pdo->beginTransaction();
    $row = write_off_entry($pdo, $e['entry_id'], $me, mb_substr($reason, 0, 500));
    time_log($pdo, 'time_entry.write_off', (int) $row['timesheet_id'], (int) $row['engagement_id'], ['before' => ['status' => 'approved'], 'after' => ['status' => 'written_off', 'hours' => $row['hours'], 'reason' => mb_substr($reason, 0, 500),
        'bill_value' => $row['bill_rate'] === null ? null : number_format((float) $row['hours'] * (float) $row['bill_rate'], 2, '.', ''), 'currency' => $row['currency'], 'member_id' => (int) $row['member_id']]], 'time_entry', $e['entry_id']);
    $pdo->commit();
    return $row;
});
ct_done('Wrote off ' . fmt_hours($row['hours']) . ' on ' . $e['engagement_number'], $e['entry_id'], ct_land(return_path('/time/weeks/' . (int) $row['timesheet_id']), 'written_off', 'time-entry-row-' . $e['entry_id']), 'timesheetChanged', ['timesheet_id' => (int) $row['timesheet_id']]);
