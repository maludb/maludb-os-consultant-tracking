<?php
declare(strict_types=1);
/**
 * Action `time_entry_save` (by the presence of `entry`; log `time_entry.save`: entry_date, engagement_id, hours, billable, activity_type_id, logged_by — never the internal note):
 * time.write; for another member → time.approve and manage of the engagement. The database refuses in words: the staffing, the increment, the day's cap, a submitted or approved week.
 */
require_once dirname(__DIR__, 3) . '/app/features/time/handler.php';
ct_handler_begin();
require_right('time.write');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('entry') ?? request_integer('entry_id');
$cur = null;
if ($id !== null) {
    $cur = find_time_entry($pdo, $id) ?? refuse(404, 'Time entry not found.');
}
$member = $cur['member_id'] ?? (request_integer('member') ?? request_integer('member_id') ?? $me);
$errors = [];
$engField = req_has('engagement') ? 'engagement' : 'engagement_id';
$engagement = ct_ref($pdo, $engField, $cur['engagement_id'] ?? null, 'SELECT 1 FROM engagements WHERE id = :id', 'engagement', $errors, false);
if ($member !== $me && !may_act_for($pdo, $me, $member, $engagement)) {
    refuse(403, 'You may not log time for that person on that engagement.');
}
$date = $cur['entry_date'] ?? null;
if (req_has('date') || req_has('entry_date') || $cur === null) {
    $d = request_date(req_has('date') ? 'date' : 'entry_date');
    if ($d === false || $d === null) {
        $errors['date'] = 'The date is a day (2026-03-02).';
    } else {
        $date = $d;
    }
}
$hours = $cur['hours'] ?? null;
if (req_has('hours') || $cur === null) {
    $raw = str_replace(',', '.', trim((string) req_val('hours')));
    if (!is_numeric($raw) || (float) $raw <= 0 || (float) $raw > 24) {
        $errors['hours'] = 'Hours are a number above zero, up to 24.';
    } else {
        $hours = number_format((float) $raw, 2, '.', '');
    }
}
$activityField = req_has('activity') ? 'activity' : 'activity_type_id';
$activity = ct_ref($pdo, $activityField, $cur['activity_type_id'] ?? null, 'SELECT 1 FROM activity_types WHERE id = :id AND archived_at IS NULL', 'activity type', $errors);
$billable = $cur['billable'] ?? null;
if (req_has('billable')) {
    $billable = ct_yes('billable');
} elseif ($billable === null) {
    $billable = $activity === null ? true : (bool) one_value($pdo, 'SELECT billable_default FROM activity_types WHERE id = :id', ['id' => $activity]);
}
$note = req_has('note') ? ((string) req_val('note') === '' ? null : mb_substr((string) req_val('note'), 0, 1000)) : ($cur['note'] ?? null);
$internal = req_has('internal_note') ? ((string) req_val('internal_note') === '' ? null : mb_substr((string) req_val('internal_note'), 0, 1000)) : ($cur['internal_note'] ?? null);
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$f = ['engagement_id' => $engagement, 'entry_date' => $date, 'hours' => $hours, 'activity_type_id' => $activity, 'billable' => $billable, 'note' => $note, 'internal_note' => $internal];
$loggable = static fn (array $e): array => ['entry_date' => $e['entry_date'], 'engagement_id' => $e['engagement_id'], 'hours' => $e['hours'], 'billable' => $e['billable'], 'activity_type_id' => $e['activity_type_id'], 'has_note' => $e['note'] !== null];
[$newId, $tsId] = ct_guard($pdo, static function () use ($pdo, $me, $member, $id, $cur, $f, $loggable): array {
    $pdo->beginTransaction();
    $newId = save_time_entry($pdo, $id, $f, $member, $me);
    $row = $pdo->prepare('SELECT timesheet_id, entry_date, engagement_id, hours, billable, activity_type_id, note FROM time_entries WHERE id = :id');
    $row->execute(['id' => $newId]);
    $after = $row->fetch();
    $after['billable'] = (bool) $after['billable'];
    $after['engagement_id'] = (int) $after['engagement_id'];
    $after['activity_type_id'] = $after['activity_type_id'] === null ? null : (int) $after['activity_type_id'];
    $tsId = (int) $after['timesheet_id'];
    if ($cur === null) {
        time_log($pdo, 'time_entry.save', $tsId, $after['engagement_id'], ['after' => $loggable($after) + ['member_id' => $member, 'logged_by' => $me]], 'time_entry', $newId);
    } else {
        $d = ct_diff($loggable($cur), $loggable($after));
        if ($d['after'] !== []) {
            time_log($pdo, 'time_entry.save', $tsId, $after['engagement_id'], ['before' => $d['before'], 'after' => $d['after'] + ['member_id' => $member, 'logged_by' => $me]], 'time_entry', $newId);
        }
    }
    $pdo->commit();
    return [$newId, $tsId];
});
$ws = week_start_of($pdo, $f['entry_date']);
$num = (string) one_value($pdo, 'SELECT number FROM engagements WHERE id = :e', ['e' => $f['engagement_id']]);
$land = ct_land(return_path('/time/?week=' . $ws . ($member !== $me ? '&member=' . $member : '')), 'saved', 'time-entry-row-' . $newId);
if (ct_yes('add_another', false)) {
    $land = '/time/entries/new?date=' . $f['entry_date'] . ($member !== $me ? '&member=' . $member : '') . '&notice=saved';
}
ct_done(($id === null ? 'Logged ' : 'Changed to ') . fmt_hours($f['hours']) . ' on ' . $num . ' for ' . format_date($f['entry_date']), $newId, $land, 'timesheetChanged', ['entry_id' => $newId, 'timesheet_id' => $tsId, 'week_start' => $ws]);
