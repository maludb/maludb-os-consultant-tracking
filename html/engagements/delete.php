<?php
declare(strict_types=1);
/** Action `engagement_delete` (log `engagement.delete`; confirm; `deletion` for agents): manage; a draft only (the trigger refuses anything else, shown in words). */
require_once dirname(__DIR__, 2) . '/app/features/engagements/handler.php';
ct_handler_begin();
require_right('engagements.manage');
$pdo = db();
$me = (int) current_member_id();
$e = engagement_from_request($pdo);
require_manages($e);
ct_guard($pdo, static function () use ($pdo, $me, $e): void {
    $pdo->beginTransaction();
    $number = delete_engagement($pdo, $e['engagement_id'], $me);
    engagement_log($pdo, $e, 'engagement.delete', ['before' => ['number' => $number, 'name' => $e['name'], 'client_id' => $e['client_id'], 'status' => $e['status']]]);
    $pdo->commit();
});
ct_done('Deleted the draft ' . $e['number'], $e['engagement_id'], ct_land(return_path('/engagements/'), 'deleted'), 'engagementChanged');
