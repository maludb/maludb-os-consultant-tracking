<?php
declare(strict_types=1);
/** Action `cost_rate_save` (by the presence of `cost_rate`; log `cost_rate.save`: member_id and the dates — NEVER the rate): the admin. A new range ends the person's open range the day before. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/write.php';
ct_handler_begin();
require_admin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('cost_rate') ?? request_integer('cost_rate_id');
$cur = $id === null ? null : (cost_rate_state($pdo, $id) ?? refuse(404, 'Cost rate not found.'));
$errors = [];
$member = $cur['member_id'] ?? ct_ref($pdo, req_has('member') ? 'member' : 'member_id', null, "SELECT 1 FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL", 'member', $errors, false);
$cost = $cur['hourly_cost'] ?? null;
if (req_has('hourly_cost') || $cur === null) {
    $cost = parse_money(req_val('hourly_cost'));
    if ($cost === null || (float) $cost < 0) {
        $errors['hourly_cost'] = 'The hourly cost is an amount of zero or more, in the base currency.';
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
$note = req_has('note') ? ((string) req_val('note') === '' ? null : mb_substr((string) req_val('note'), 0, 200)) : ($cur['note'] ?? null);
if ($errors !== []) {
    ct_refuse_fields($errors);
}
[$newId, $ended] = ct_guard($pdo, static function () use ($pdo, $me, $id, $cur, $member, $cost, $from, $to, $note): array {
    $pdo->beginTransaction();
    [$newId, $ended] = save_cost_rate($pdo, $id, $member, $cost, $from, $to, $note, $me);
    $after = ['member_id' => $member, 'valid_from' => $from, 'valid_to' => $to, 'has_note' => $note !== null];
    if ($cur === null) {
        log_activity($pdo, 'cost_rate.save', 'cost_rate', $newId, ['after' => $after + ['ended_range_id' => $ended]]);
    } else {
        $d = ct_diff(['member_id' => $cur['member_id'], 'valid_from' => $cur['valid_from'], 'valid_to' => $cur['valid_to'], 'has_note' => $cur['note'] !== null], $after);
        log_activity($pdo, 'cost_rate.save', 'cost_rate', $newId, ['before' => $d['before'] + ['member_id' => $member], 'after' => $d['after'] + ['member_id' => $member, 'rate_changed' => (string) $cur['hourly_cost'] !== (string) $cost]]);
    }
    $pdo->commit();
    return [$newId, $ended];
});
$name = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :id', ['id' => $member]);
ct_done(($id === null ? 'Set a cost rate for ' : 'Changed a cost rate of ') . $name . ' from ' . format_date($from) . ($ended !== null ? ' (the open range ended)' : ''), $newId, ct_land(return_path('/settings/cost-rates/'), 'saved', 'cost-rate-' . $member), 'costRateChanged', ['member_id' => $member, 'ended_range_id' => $ended]);
