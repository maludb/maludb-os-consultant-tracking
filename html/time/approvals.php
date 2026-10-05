<?php
declare(strict_types=1);
/** /time/approvals?member= — the submitted weeks awaiting me (screen `time-approvals`): per person a card; Approve (what I manage) and Reject on each. time.approve. */
require_once dirname(__DIR__, 2) . '/app/features/time/handler.php';
require_right('time.approve');
require_human();
$pdo = db();
$me = (int) current_member_id();
$member = request_integer('member');
$rows = timesheets_pending($pdo, $me, $member);
log_screen_view($pdo, 'time-approvals');
if (wants_json()) {
    respond_screen(['weeks' => array_map('present_pending_week', $rows), 'member_id' => $member]);
}
$notices = ['approved' => ['success', 'The week is approved.'], 'partly' => ['info', 'Approved what you manage; the rest awaits its manager.'], 'rejected' => ['warning', 'The week was sent back.']];
render_screen('Time approvals', view('time/approvals.php', ['rows' => $rows, 'member' => $member, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => ct_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'time-approvals', 'screen' => 'time-approvals', 'entity' => 'timesheet']);
