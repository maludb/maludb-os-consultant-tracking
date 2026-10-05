<?php
declare(strict_types=1);
/** /expenses/{id} — one expense (screen `expense-view`): the receipt large, the money, the engagement, the status with dates and reasons, Submit / Approve / Reject / Mark reimbursed by right, documents, notes, the timeline. */
require_once dirname(__DIR__, 2) . '/app/features/expenses/handler.php';
require_any_right('expenses.write|expenses.approve|billing.write|reports.view');
require_human();
$pdo = db();
$me = (int) current_member_id();
$x = find_expense($pdo, expense_id_from_request() ?? refuse(404, 'Expense not found.')) ?? refuse(404, 'Expense not found.');
$manages = can_manage_engagement($x['engagement_id']);
$mine = $x['member_id'] === $me;
$open = in_array($x['status'], ['draft', 'rejected'], true);
$may = ['edit' => $open && has_right('expenses.write') && ($mine || ($manages && has_right('expenses.approve'))), 'submit' => $open && has_right('expenses.write') && ($mine || ($manages && has_right('expenses.approve'))),
        'approve' => $x['status'] === 'submitted' && has_right('expenses.approve') && $manages && (!$mine || is_super_admin()),
        'reject' => (($x['status'] === 'submitted') || ($x['status'] === 'approved' && !$x['billed'] && $x['reimbursed_at'] === null)) && has_right('expenses.approve') && $manages,
        'reimburse' => $x['status'] === 'approved' && $x['reimbursable'] && $x['reimbursed_at'] === null && has_right('billing.write'),
        'attach' => record_writable($pdo, 'expense', $x['expense_id']), 'delete' => $open && ($mine || ($manages && has_right('expenses.approve'))) && has_right('expenses.write'), 'admin' => is_ct_admin(), 'me' => $me];
$timeline = expense_timeline($pdo, $x['expense_id'], 50);
log_activity($pdo, 'screen.view', 'expense', $x['expense_id'], ['screen' => 'expense-view', 'after' => ['number' => $x['number']], 'engagement_id' => $x['engagement_id']]);
if (wants_json()) {
    respond_screen(['expense' => present_expense($x), 'receipts' => array_map('present_attachment', $x['receipts']), 'notes' => array_map('present_note', $x['notes']), 'timeline' => array_map('present_activity_row', $timeline), 'may' => $may]);
}
$notices = ['saved' => ['success', 'Saved.'], 'submitted' => ['success', 'Submitted; the engagement\'s manager is told.'], 'approved' => ['success', 'Approved.'], 'rejected' => ['warning', 'Sent back with your reason.'],
            'reimbursed' => ['success', 'Marked reimbursed.'], 'attached' => ['success', 'Attached.']];
render_screen($x['number'], view('expenses/view.php', ['x' => $x, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'timeline' => $timeline, 'me' => $me, 'notice' => ct_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'expenses', 'screen' => 'expense-view', 'entity' => 'expense', 'recordId' => (string) $x['expense_id']]);
