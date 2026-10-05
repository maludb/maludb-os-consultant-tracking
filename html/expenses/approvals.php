<?php
declare(strict_types=1);
/** /expenses/approvals?engagement=&missing_receipt= — the submitted expenses awaiting me (screen `expense-approvals`): oldest first, the receipt thumbnail beside the amount; Approve and Reject (reason) on each. expenses.approve. */
require_once dirname(__DIR__, 2) . '/app/features/expenses/handler.php';
require_right('expenses.approve');
require_human();
$pdo = db();
$me = (int) current_member_id();
$engagement = request_integer('engagement');
$missing = request_bool('missing_receipt');
$rows = expenses_pending($pdo, $me, ['engagement_id' => $engagement, 'missing_receipt' => $missing]);
log_screen_view($pdo, 'expense-approvals');
if (wants_json()) {
    respond_screen(['engagement_id' => $engagement, 'missing_receipt' => $missing, 'expenses' => array_map('present_expense', $rows)]);
}
$notices = ['approved' => ['success', 'The expense is approved.'], 'rejected' => ['warning', 'The expense was sent back.']];
render_screen('Expense approvals', view('expenses/approvals.php', ['rows' => $rows, 'engagement' => $engagement, 'missing' => $missing, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => ct_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'expense-approvals', 'screen' => 'expense-approvals', 'entity' => 'expense']);
