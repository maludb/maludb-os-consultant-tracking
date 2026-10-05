<?php
declare(strict_types=1);
/** Action `usage_charge_void` (log `usage_charge.void`: number, amount, currency, reason; confirm): usage.write + manage; a reason is required; an unbilled charge only (the verb says so); a charge the OS statement made returns its pieces to the month's remainder. */
require_once dirname(__DIR__, 2) . '/app/features/usage/handler.php';
ct_handler_begin();
require_right('usage.write');
$pdo = db();
$me = (int) current_member_id();
$u = usage_from_request($pdo);
usage_engagement_gate($pdo, $u['engagement_id']);
$reason = mb_substr((string) req_val('reason'), 0, 500);
if ($reason === '') {
    ct_refuse_fields(['reason' => 'Say why it is void (a reason is kept on the record).']);
}
$row = ct_guard($pdo, static function () use ($pdo, $me, $u, $reason): array {
    $pdo->beginTransaction();
    $row = void_usage_charge($pdo, $u['usage_charge_id'], $me, $reason);
    usage_log($pdo, 'usage_charge.void', $u['usage_charge_id'], $u['engagement_id'], ['before' => ['status' => $u['status']], 'after' => ['status' => 'void', 'reason' => $reason] + usage_money($row)]);
    $pdo->commit();
    return $row;
});
ct_done('Voided ' . $row['number'], $u['usage_charge_id'], ct_land(return_path('/usage/' . $u['usage_charge_id']), 'voided'), 'usageChanged', ['usage_charge_id' => $u['usage_charge_id'], 'status' => 'void']);
