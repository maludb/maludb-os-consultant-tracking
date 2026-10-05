<?php
declare(strict_types=1);
/** Action `cost_rate_end` (log `cost_rate.end`: member_id and the dates): the admin. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/write.php';
ct_handler_begin();
require_admin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('cost_rate') ?? request_integer('cost_rate_id') ?? refuse(422, 'Say which range.');
$cur = cost_rate_state($pdo, $id) ?? refuse(404, 'Cost rate not found.');
$to = request_date('valid_to');
if ($to === false || $to === null) {
    ct_refuse_fields(['valid_to' => 'The end is a date (2026-06-30).']);
}
ct_guard($pdo, static function () use ($pdo, $me, $id, $cur, $to): void {
    $pdo->beginTransaction();
    end_cost_rate($pdo, $id, $to, $me);
    log_activity($pdo, 'cost_rate.end', 'cost_rate', $id, ['before' => ['member_id' => $cur['member_id'], 'valid_from' => $cur['valid_from'], 'valid_to' => $cur['valid_to']], 'after' => ['member_id' => $cur['member_id'], 'valid_from' => $cur['valid_from'], 'valid_to' => $to]]);
    $pdo->commit();
});
$name = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :id', ['id' => $cur['member_id']]);
ct_done('Ended ' . $name . '\'s cost rate on ' . format_date($to), $id, ct_land(return_path('/settings/cost-rates/'), 'ended', 'cost-rate-' . $cur['member_id']), 'costRateChanged', ['member_id' => $cur['member_id']]);
