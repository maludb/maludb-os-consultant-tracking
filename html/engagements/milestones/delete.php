<?php
declare(strict_types=1);
/** Action `milestone_delete` (log `milestone.delete`; confirm; `deletion` for agents): milestones.write + manage; a planned one only. */
require_once dirname(__DIR__, 3) . '/app/features/engagements/handler.php';
ct_handler_begin();
require_right('milestones.write');
$pdo = db();
$me = (int) current_member_id();
[$m, $e] = milestone_from_request($pdo);
require_manages($e);
ct_guard($pdo, static function () use ($pdo, $me, $e, $m): void {
    $pdo->beginTransaction();
    delete_milestone($pdo, $m['milestone_id'], $me);
    engagement_log($pdo, $e, 'milestone.delete', ['before' => ['name' => $m['name'], 'amount' => $m['amount'], 'status' => $m['status']]], 'milestone', $m['milestone_id']);
    $pdo->commit();
});
ct_done('Deleted the milestone ' . $m['name'], $m['milestone_id'], ct_land(return_path('/engagements/' . $e['engagement_id'] . '/milestones'), 'milestone_deleted'), 'engagementChanged');
