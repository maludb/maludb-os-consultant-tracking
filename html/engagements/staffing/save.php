<?php
declare(strict_types=1);
/** Action `engagement_member_save` (by the presence of `staffing`; log `engagement.staff`: member_id, consulting_role_id, valid_from, valid_to, has_override — never the rate): staffing.write + manage. */
require_once dirname(__DIR__, 3) . '/app/features/engagements/handler.php';
ct_handler_begin();
require_right('staffing.write');
$pdo = db();
$me = (int) current_member_id();
$staffingId = request_integer('staffing') ?? request_integer('staffing_id');
$cur = null;
if ($staffingId !== null) {
    $cur = staffing_state($pdo, $staffingId) ?? refuse(404, 'Staffing not found.');
    $_POST['engagement'] = (string) $cur['engagement_id'];
}
$e = engagement_from_request($pdo);
require_manages($e);
if (in_array($e['status'], ['closed', 'cancelled'], true)) {
    refuse(422, $e['number'] . ' is ' . $e['status'] . '; reopen it to staff it.');
}
$errors = [];
$memberField = req_has('member') ? 'member' : 'member_id';
$member = ct_ref($pdo, $memberField, $cur['member_id'] ?? null, "SELECT 1 FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL", 'member', $errors, false);
$roleField = req_has('role') ? 'role' : 'consulting_role_id';
$role = ct_ref($pdo, $roleField, $cur['consulting_role_id'] ?? null, 'SELECT 1 FROM consulting_roles WHERE id = :id AND archived_at IS NULL', 'consulting role', $errors, false);
$from = $cur['valid_from'] ?? date('Y-m-d');
if (req_has('valid_from') && (string) req_val('valid_from') !== '') {
    $d = request_date('valid_from');
    if ($d === false) {
        $errors['valid_from'] = 'The start is a date (2026-01-01).';
    } else {
        $from = $d;
    }
}
$to = $cur['valid_to'] ?? null;
if (req_has('valid_to')) {
    $d = request_date('valid_to');
    if ($d === false) {
        $errors['valid_to'] = 'The end is a date, or blank while staffed.';
    } else {
        $to = $d;
    }
}
if ($errors === [] && $to !== null && $to <= $from) {
    $errors['valid_to'] = 'The end is after the start.';
}
$override = $cur['bill_rate_override'] ?? null;
if (req_has('bill_rate_override')) {
    $override = (string) req_val('bill_rate_override') === '' ? null : parse_money(req_val('bill_rate_override'));
    if ((string) req_val('bill_rate_override') !== '' && ($override === null || (float) $override < 0)) {
        $errors['bill_rate_override'] = 'The override is an amount per hour of zero or more.';
    }
}
$note = req_has('note') ? ((string) req_val('note') === '' ? null : mb_substr((string) req_val('note'), 0, 200)) : ($cur['note'] ?? null);
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$loggable = static fn (array $s): array => ['member_id' => $s['member_id'], 'consulting_role_id' => $s['consulting_role_id'], 'valid_from' => $s['valid_from'], 'valid_to' => $s['valid_to'], 'has_override' => $s['bill_rate_override'] !== null];
$newId = ct_guard($pdo, static function () use ($pdo, $me, $e, $staffingId, $cur, $member, $role, $from, $to, $override, $note, $loggable): int {
    $pdo->beginTransaction();
    $newId = save_staffing($pdo, $staffingId, $e['engagement_id'], $member, $role, $from, $to, $override, $note, $me);
    $after = $loggable(staffing_state($pdo, $newId));
    if ($cur === null) {
        engagement_log($pdo, $e, 'engagement.staff', ['after' => $after], 'staffing', $newId);
    } else {
        $d = ct_diff($loggable($cur), $after);
        if ($d['after'] !== []) {
            engagement_log($pdo, $e, 'engagement.staff', ['before' => $d['before'] + ['member_id' => $member], 'after' => $d['after'] + ['member_id' => $member]], 'staffing', $newId);
        }
    }
    $pdo->commit();
    return $newId;
});
$name = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $member]);
$title = (string) one_value($pdo, 'SELECT title FROM consulting_roles WHERE id = :r', ['r' => $role]);
ct_done(($staffingId === null ? 'Staffed ' : 'Changed the staffing of ') . $name . ' as ' . $title . ' on ' . $e['number'], $newId, ct_land(return_path('/engagements/' . $e['engagement_id'] . '/staffing'), 'staffed', 'staffing-row-' . $newId), 'engagementChanged', ['staffing_id' => $newId, 'engagement_id' => $e['engagement_id']]);
