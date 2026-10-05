<?php
declare(strict_types=1);
/** Action `engagement_role_rate_save` (log `engagement.role_rate`: role_id, before/after rate; `other` for agents): manage. An empty bill_rate removes the override. */
require_once dirname(__DIR__, 3) . '/app/features/engagements/handler.php';
ct_handler_begin();
require_right('engagements.manage');
$pdo = db();
$me = (int) current_member_id();
$e = engagement_from_request($pdo);
require_manages($e);
$errors = [];
$role = ct_ref($pdo, req_has('role') ? 'role' : 'consulting_role_id', null, 'SELECT 1 FROM consulting_roles WHERE id = :id AND archived_at IS NULL', 'consulting role', $errors, false);
$rate = null;
if (req_has('bill_rate') && (string) req_val('bill_rate') !== '') {
    $rate = parse_money(req_val('bill_rate'));
    if ($rate === null || (float) $rate < 0) {
        $errors['bill_rate'] = 'The rate is an amount per hour of zero or more.';
    }
}
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$before = ct_guard($pdo, static function () use ($pdo, $me, $e, $role, $rate): ?string {
    $pdo->beginTransaction();
    $before = save_role_rate($pdo, $e['engagement_id'], $role, $rate, $me);
    if ($before !== $rate) {
        engagement_log($pdo, $e, 'engagement.role_rate', ['before' => ['role_id' => $role, 'bill_rate' => $before], 'after' => ['role_id' => $role, 'bill_rate' => $rate]]);
    }
    $pdo->commit();
    return $before;
});
$title = (string) one_value($pdo, 'SELECT title FROM consulting_roles WHERE id = :r', ['r' => $role]);
ct_done(($rate === null ? 'Removed the override for ' : 'Set the rate for ') . $title . ' on ' . $e['number'] . ($rate === null ? '' : ' to ' . fmt_money($rate, $e['currency'])), $e['engagement_id'], ct_land(return_path('/engagements/' . $e['engagement_id'] . '/rates'), 'rate', 'rate-row-' . $role), 'engagementChanged', ['role_id' => $role, 'bill_rate' => $rate]);
