<?php
declare(strict_types=1);
/** Action `allocation_rule_delete` (log `allocation_rule.delete`; confirm; `deletion` for agents): usage.write + manage of the rule's engagement. Charges the rule already placed stay; the month is allocated again to apply the change. */
require_once dirname(__DIR__, 3) . '/app/features/usage/handler.php';
ct_handler_begin();
require_right('usage.write');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('rule') ?? request_integer('rule_id') ?? request_integer('id') ?? refuse(422, 'Say which rule.');
$cur = find_allocation_rule($pdo, $id) ?? refuse(404, 'Rule not found.');
usage_engagement_gate($pdo, $cur['engagement_id']);
$was = ct_guard($pdo, static function () use ($pdo, $me, $id, $cur): array {
    $pdo->beginTransaction();
    $was = delete_allocation_rule($pdo, $id, $me);
    log_activity($pdo, 'allocation_rule.delete', 'allocation_rule', $id, ['engagement_id' => $was['engagement_id'], 'before' => ['name' => $was['name'], 'engagement_id' => $was['engagement_id'], 'share_pct' => $was['share_pct']]]);
    $pdo->commit();
    return $was;
});
ct_done('Removed the rule ' . $was['name'], $id, ct_land(return_path('/settings/allocation-rules/'), 'deleted'), 'usageChanged', ['rule_id' => $id]);
