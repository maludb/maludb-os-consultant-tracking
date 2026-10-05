<?php
declare(strict_types=1);
/** Action `expense_reject` (log `expense.reject`: number, amount, currency, reason; confirm; `other` for agents): expenses.approve + manage; a reason is required (the verb); an approved one only until it is billed or reimbursed (the verb decides). The person is told with it. */
require_once dirname(__DIR__, 2) . '/app/features/expenses/handler.php';
ct_handler_begin();
require_right('expenses.approve');
$pdo = db();
$me = (int) current_member_id();
$x = expense_from_request($pdo);
if (!can_manage_engagement($x['engagement_id'])) {
    refuse(403, 'You do not manage ' . $x['engagement_number'] . '.');
}
$reason = trim((string) req_val('reason'));
if ($reason === '') {
    ct_refuse_fields(['reason' => 'Say what must change (the person reads it).']);
}
$row = ct_guard($pdo, static function () use ($pdo, $me, $x, $reason): array {
    $pdo->beginTransaction();
    $row = reject_expense($pdo, $x['expense_id'], $me, mb_substr($reason, 0, 1000));
    expense_log($pdo, 'expense.reject', $x['expense_id'], $x['engagement_id'], ['before' => ['status' => $x['status']], 'after' => ['status' => 'rejected', 'member_id' => $x['member_id'], 'reason' => mb_substr($reason, 0, 1000)] + expense_money($row)]);
    if ($x['member_id'] !== $me) {
        queue_expense_notice($pdo, $x['member_id'], 'expense_rejected', $x['expense_id'], ['title' => 'Your expense ' . expense_label($row) . ' was sent back', 'body' => mb_substr($reason, 0, 1000)]);
    }
    $pdo->commit();
    return $row;
});
ct_done('Sent back ' . expense_label($row), $x['expense_id'], ct_land(return_path('/expenses/' . $x['expense_id']), 'rejected'), 'expenseChanged', ['expense_id' => $x['expense_id'], 'status' => 'rejected']);
