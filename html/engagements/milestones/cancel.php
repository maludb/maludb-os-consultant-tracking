<?php
declare(strict_types=1);
/** Action `milestone_cancel` (log `milestone.cancel`; a reason required; confirm): milestones.write + manage. */
require_once dirname(__DIR__, 3) . '/app/features/engagements/handler.php';
ct_handler_begin();
require_right('milestones.write');
$pdo = db();
$me = (int) current_member_id();
[$m, $e] = milestone_from_request($pdo);
require_manages($e);
$reason = trim((string) req_val('reason'));
if ($reason === '') {
    ct_refuse_fields(['reason' => 'Say why the milestone is cancelled.']);
}
$moved = ct_guard($pdo, static function () use ($pdo, $me, $e, $m, $reason): array {
    $pdo->beginTransaction();
    $moved = move_milestone($pdo, $m['milestone_id'], 'cancelled', ['cancel_reason' => mb_substr($reason, 0, 500)], $me);
    engagement_log($pdo, $e, 'milestone.cancel', ['before' => ['status' => $moved[0]], 'after' => ['status' => 'cancelled', 'name' => $m['name'], 'reason' => mb_substr($reason, 0, 500)]], 'milestone', $m['milestone_id']);
    $pdo->commit();
    return $moved;
});
ct_done('Cancelled ' . $m['name'] . ' on ' . $e['number'], $m['milestone_id'], ct_land(return_path('/engagements/' . $e['engagement_id'] . '/milestones'), 'milestone_cancelled', 'milestone-card-' . $m['milestone_id']), 'engagementChanged', ['status' => 'cancelled']);
