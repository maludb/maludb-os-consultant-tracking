<?php
declare(strict_types=1);
/** Action `budget_alert_write` (log `budget_alert.write` once per alert made): manage or billing. `engagement` checks one; empty checks every active one the caller manages. */
require_once dirname(__DIR__, 3) . '/app/features/engagements/handler.php';
ct_handler_begin();
require_any_right('engagements.manage|billing.write');
$pdo = db();
$me = (int) current_member_id();
$one = request_integer('engagement') ?? request_integer('engagement_id');
$e = $one === null ? null : engagement_from_request($pdo, $one);
if ($e !== null) {
    require_manages($e);
}
$made = ct_guard($pdo, static function () use ($pdo, $one): array {
    $pdo->beginTransaction();
    $before = array_map('intval', $pdo->query('SELECT id FROM budget_alerts')->fetchAll(PDO::FETCH_COLUMN));
    $made = check_budget_alerts($pdo, $one);
    $new = $pdo->prepare('SELECT b.id, b.engagement_id, b.kind, b.threshold_pct, b.value_at, b.budget_at, e.client_id, e.department_id FROM budget_alerts b JOIN engagements e ON e.id = b.engagement_id WHERE NOT (b.id = ANY (CAST(:before AS bigint[])))');
    $new->execute(['before' => pg_array_literal($before)]);
    foreach ($new->fetchAll() as $a) {
        log_activity($pdo, 'budget_alert.write', 'budget_alert', (int) $a['id'], ['after' => ['engagement_id' => (int) $a['engagement_id'], 'kind' => $a['kind'], 'threshold_pct' => (int) $a['threshold_pct'], 'value_at' => $a['kind'] === 'ai' ? null : $a['value_at'], 'budget_at' => $a['kind'] === 'ai' ? null : $a['budget_at']],
            'engagement_id' => (int) $a['engagement_id'], 'client_id' => (int) $a['client_id'], 'department_id' => $a['department_id'] === null ? null : (int) $a['department_id']]);
    }
    $pdo->commit();
    return $made;
});
$total = array_sum($made);
ct_done('Checked ' . count($made) . ' engagement' . (count($made) === 1 ? '' : 's') . ': ' . $total . ' new alert' . ($total === 1 ? '' : 's'), $one, ct_land(return_path($one === null ? '/usage/budgets' : '/engagements/' . $one), 'checked'), 'engagementChanged', ['new_alerts' => $total, 'checked' => count($made)]);
