<?php
declare(strict_types=1);
/** Action `recurring_charge_delete` (log `recurring_charge.delete`; confirm; `deletion` for agents): usage.write + manage of the schedule's engagement. Charges it already made stay. */
require_once dirname(__DIR__, 3) . '/app/features/usage/handler.php';
ct_handler_begin();
require_right('usage.write');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('schedule') ?? request_integer('schedule_id') ?? request_integer('id') ?? refuse(422, 'Say which schedule.');
$cur = find_schedule($pdo, $id) ?? refuse(404, 'Schedule not found.');
usage_engagement_gate($pdo, $cur['engagement_id']);
$was = ct_guard($pdo, static function () use ($pdo, $me, $id): array {
    $pdo->beginTransaction();
    $was = delete_schedule($pdo, $id, $me);
    log_activity($pdo, 'recurring_charge.delete', 'recurring_charge', $id, ['engagement_id' => $was['engagement_id'], 'before' => ['engagement_id' => $was['engagement_id'], 'run_count' => $was['run_count']]]);
    $pdo->commit();
    return $was;
});
ct_done('Removed the schedule ' . $was['description'], $id, ct_land(return_path('/settings/schedules/'), 'deleted'), 'usageChanged', ['schedule_id' => $id]);
