<?php
declare(strict_types=1);
/** Action `engagement_member_end` (log `engagement.unstaff`; confirm): manage. valid_to defaults to today; new time from that date is refused (slice 3), the entries stay. */
require_once dirname(__DIR__, 3) . '/app/features/engagements/handler.php';
ct_handler_begin();
require_right('staffing.write');
$pdo = db();
$me = (int) current_member_id();
$staffingId = request_integer('staffing') ?? request_integer('staffing_id') ?? refuse(422, 'Say which staffing.');
$cur = staffing_state($pdo, $staffingId) ?? refuse(404, 'Staffing not found.');
$e = engagement_from_request($pdo, $cur['engagement_id']);
require_manages($e);
$to = date('Y-m-d');
if (req_has('valid_to') && (string) req_val('valid_to') !== '') {
    $d = request_date('valid_to');
    if ($d === false) {
        ct_refuse_fields(['valid_to' => 'The end is a date (2026-06-30).']);
    }
    $to = $d;
}
ct_guard($pdo, static function () use ($pdo, $me, $e, $staffingId, $cur, $to): void {
    $pdo->beginTransaction();
    end_staffing($pdo, $staffingId, $to, $me);
    engagement_log($pdo, $e, 'engagement.unstaff', ['before' => ['member_id' => $cur['member_id'], 'valid_to' => $cur['valid_to']], 'after' => ['member_id' => $cur['member_id'], 'valid_to' => $to]], 'staffing', $staffingId);
    $pdo->commit();
});
$name = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $cur['member_id']]);
ct_done('Ended ' . $name . '\'s staffing on ' . $e['number'] . ' as of ' . format_date($to), $staffingId, ct_land(return_path('/engagements/' . $e['engagement_id'] . '/staffing'), 'unstaffed', 'staffing-row-' . $staffingId), 'engagementChanged', ['engagement_id' => $e['engagement_id']]);
