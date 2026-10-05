<?php
declare(strict_types=1);
/** Action `budget_alert_acknowledge` (log `budget_alert.acknowledge`): manage (of the alert's engagement). */
require_once dirname(__DIR__, 3) . '/app/features/engagements/handler.php';
ct_handler_begin();
require_right('engagements.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('alert') ?? request_integer('alert_id') ?? refuse(422, 'Say which alert.');
$a = $pdo->prepare('SELECT * FROM mcp_budget_alerts WHERE alert_id = :id');
$a->execute(['id' => $id]);
$a = $a->fetch() ?: refuse(404, 'Alert not found.');
$e = engagement_from_request($pdo, (int) $a['engagement_id']);
require_manages($e);
ct_guard($pdo, static function () use ($pdo, $me, $e, $id): void {
    $pdo->beginTransaction();
    $was = acknowledge_alert($pdo, $id, $me);
    engagement_log($pdo, $e, 'budget_alert.acknowledge', ['after' => ['kind' => $was['kind'], 'threshold_pct' => $was['threshold_pct']]], 'budget_alert', $id);
    $pdo->commit();
});
ct_done('Acknowledged the ' . $a['kind'] . ' alert at ' . (int) $a['threshold_pct'] . '% on ' . $e['number'], $id, ct_land(return_path('/usage/budgets'), 'acknowledged'), 'engagementChanged', ['engagement_id' => $e['engagement_id']]);
