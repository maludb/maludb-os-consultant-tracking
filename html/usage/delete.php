<?php
declare(strict_types=1);
/** Action `usage_charge_delete` (log `usage_charge.delete`; confirm; `deletion` for agents): usage.write + manage; a draft only (the trigger refuses the rest in its words); a charge the OS statement made is voided, never deleted; its documents and notes go with it. */
require_once dirname(__DIR__, 2) . '/app/features/usage/handler.php';
ct_handler_begin();
require_right('usage.write');
$pdo = db();
$me = (int) current_member_id();
$u = usage_from_request($pdo);
usage_engagement_gate($pdo, $u['engagement_id']);
$was = ct_guard($pdo, static function () use ($pdo, $me, $u): array {
    $pdo->beginTransaction();
    $was = delete_usage_charge($pdo, $u['usage_charge_id'], $me);
    usage_log($pdo, 'usage_charge.delete', $u['usage_charge_id'], $was['engagement_id'], ['before' => ['number' => $was['number'], 'status' => $was['status'], 'amount' => $was['amount'], 'currency' => $was['currency']]]);
    $pdo->commit();
    return $was;
});
ct_done('Removed ' . $was['number'] . ' (' . fmt_money($was['amount'], $was['currency']) . ')', $u['usage_charge_id'], ct_land(return_path('/usage/'), 'deleted'), 'usageChanged', ['number' => $was['number']]);
