<?php
declare(strict_types=1);
/**
 * Action `usage_charge_approve` (log `usage_charge.approve`: number, amount = the billable amount, currency, cost_amount; confirm; `other` for agents): usage.approve + manage of the engagement. The verb refuses a charge that is not a draft in its
 * words. Then the AI budget check on the engagement (a new `ai` alert tells the engagement's manager).
 */
require_once dirname(__DIR__, 2) . '/app/features/usage/handler.php';
ct_handler_begin();
require_right('usage.approve');
$pdo = db();
$me = (int) current_member_id();
$u = usage_from_request($pdo);
if (!can_manage_engagement($u['engagement_id'])) {
    refuse(403, 'You do not manage ' . $u['engagement_number'] . '.');
}
$out = ct_guard($pdo, static function () use ($pdo, $me, $u): array {
    $pdo->beginTransaction();
    $before = count_ai_alerts($pdo, $u['engagement_id']);
    $row = approve_usage_charge($pdo, $u['usage_charge_id'], $me);
    $full = ['number' => $row['number'], 'billable_amount' => $row['billable_amount'], 'currency' => $row['currency'], 'cost_amount' => $row['cost_amount']];
    usage_log($pdo, 'usage_charge.approve', $u['usage_charge_id'], $u['engagement_id'], ['before' => ['status' => 'draft'], 'after' => ['status' => 'approved'] + usage_money($full)]);
    check_budget_alerts($pdo, $u['engagement_id']);
    $alerts = count_ai_alerts($pdo, $u['engagement_id']) - $before;
    if ($alerts > 0) {
        $mgr = usage_manager_of($pdo, $u['engagement_id'], $me);
        if ($mgr !== null) {
            queue_usage_notice($pdo, $mgr, 'budget_alert', 'engagement', $u['engagement_id'], ['title' => $u['engagement_number'] . ' has used ' . (string) one_value($pdo, 'SELECT ai_budget_threshold_pct FROM ct_settings WHERE id = 1') . '% of its AI budget',
                'body' => 'Approving ' . $row['number'] . ' crossed the threshold. See the engagement: ' . rtrim((string) env('APP_URL', ''), '/') . '/engagements/' . $u['engagement_id'], 'dedupe' => 'ai-alert:' . $u['engagement_id'] . ':' . date('Ymd')]);
        }
    }
    $pdo->commit();
    return [$row, $alerts];
});
[$row, $alerts] = $out;
ct_done('Approved ' . $row['number'] . ' (' . fmt_money($row['billable_amount'], trim((string) $row['currency'])) . ')', $u['usage_charge_id'], ct_land(return_path('/usage/' . $u['usage_charge_id']), 'approved'), 'usageChanged',
    ['usage_charge_id' => $u['usage_charge_id'], 'status' => 'approved', 'billable_amount' => $row['billable_amount'], 'new_ai_alerts' => $alerts]);
