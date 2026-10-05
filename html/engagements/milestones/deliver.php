<?php
declare(strict_types=1);
/** Action `milestone_deliver` (log `milestone.deliver`: before/after status, note): milestones.write + manage. */
require_once dirname(__DIR__, 3) . '/app/features/engagements/handler.php';
ct_handler_begin();
require_right('milestones.write');
$pdo = db();
$me = (int) current_member_id();
[$m, $e] = milestone_from_request($pdo);
require_manages($e);
$note = mb_substr((string) req_val('note'), 0, 500);
$moved = ct_guard($pdo, static function () use ($pdo, $me, $e, $m, $note): array {
    $pdo->beginTransaction();
    $moved = move_milestone($pdo, $m['milestone_id'], 'delivered', [], $me);
    engagement_log($pdo, $e, 'milestone.deliver', ['before' => ['status' => $moved[0]], 'after' => ['status' => 'delivered', 'name' => $m['name'], 'note' => $note === '' ? null : $note]], 'milestone', $m['milestone_id']);
    $pdo->commit();
    return $moved;
});
ct_done('Delivered ' . $m['name'] . ' on ' . $e['number'], $m['milestone_id'], ct_land(return_path('/engagements/' . $e['engagement_id'] . '/milestones'), 'delivered', 'milestone-card-' . $m['milestone_id']), 'engagementChanged', ['status' => 'delivered']);
