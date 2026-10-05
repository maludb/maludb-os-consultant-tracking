<?php
declare(strict_types=1);
/** Action `capacity_save` (by the presence of `capacity`; log `capacity.save`: member_id, hours_per_week, dates): the admin. A new range ends the open one the day before. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/write.php';
ct_handler_begin();
require_admin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('capacity') ?? request_integer('capacity_id');
$cur = $id === null ? null : (capacity_state($pdo, $id) ?? refuse(404, 'Capacity not found.'));
$errors = [];
$member = $cur['member_id'] ?? ct_ref($pdo, req_has('member') ? 'member' : 'member_id', null, "SELECT 1 FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL", 'member', $errors, false);
$hours = $cur['hours_per_week'] ?? null;
if (req_has('hours_per_week') || $cur === null) {
    $raw = str_replace(',', '', (string) req_val('hours_per_week'));
    if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 168) {
        $errors['hours_per_week'] = 'Hours a week is a number from 0 to 168.';
    } else {
        $hours = number_format((float) $raw, 2, '.', '');
    }
}
$from = $cur['valid_from'] ?? null;
if (req_has('valid_from') || $cur === null) {
    $d = request_date('valid_from');
    if ($d === false || $d === null) {
        $errors['valid_from'] = 'The start is a date (2026-01-01).';
    } else {
        $from = $d;
    }
}
$to = $cur['valid_to'] ?? null;
if (req_has('valid_to')) {
    $d = request_date('valid_to');
    if ($d === false) {
        $errors['valid_to'] = 'The end is a date, or blank for an open range.';
    } else {
        $to = $d;
    }
}
if ($errors === [] && $to !== null && $to <= $from) {
    $errors['valid_to'] = 'The end is after the start.';
}
if ($errors !== []) {
    ct_refuse_fields($errors);
}
[$newId, $ended] = ct_guard($pdo, static function () use ($pdo, $me, $id, $cur, $member, $hours, $from, $to): array {
    $pdo->beginTransaction();
    [$newId, $ended] = save_capacity($pdo, $id, $member, $hours, $from, $to, $me);
    $after = ['member_id' => $member, 'hours_per_week' => $hours, 'valid_from' => $from, 'valid_to' => $to];
    if ($cur === null) {
        log_activity($pdo, 'capacity.save', 'capacity', $newId, ['after' => $after + ['ended_range_id' => $ended]]);
    } else {
        $d = ct_diff(['member_id' => $cur['member_id'], 'hours_per_week' => number_format((float) $cur['hours_per_week'], 2, '.', ''), 'valid_from' => $cur['valid_from'], 'valid_to' => $cur['valid_to']], $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'capacity.save', 'capacity', $newId, ['before' => $d['before'] + ['member_id' => $member], 'after' => $d['after'] + ['member_id' => $member]]);
        }
    }
    $pdo->commit();
    return [$newId, $ended];
});
$name = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :id', ['id' => $member]);
ct_done(($id === null ? 'Set ' : 'Changed ') . $name . '\'s capacity to ' . days_label($hours) . ' h a week from ' . format_date($from), $newId, ct_land(return_path('/settings/capacity/'), 'saved', 'capacity-' . $member), 'capacityChanged', ['member_id' => $member, 'ended_range_id' => $ended]);
