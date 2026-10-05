<?php
declare(strict_types=1);
/**
 * Action `expense_approve` (log `expense.approve`: number, amount, currency, billable_amount; confirm; `other` for agents): expenses.approve + manage of the engagement. The verb refuses one's own, a status that is not
 * submitted, an engagement not managed — in its words. Then the budget check on the engagement and the person's notice.
 */
require_once dirname(__DIR__, 2) . '/app/features/expenses/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/engagements/write.php';
ct_handler_begin();
require_right('expenses.approve');
$pdo = db();
$me = (int) current_member_id();
$x = expense_from_request($pdo);
if (!can_manage_engagement($x['engagement_id'])) {
    refuse(403, 'You do not manage ' . $x['engagement_number'] . '.');
}
$row = ct_guard($pdo, static function () use ($pdo, $me, $x): array {
    $pdo->beginTransaction();
    $row = approve_expense($pdo, $x['expense_id'], $me);
    expense_log($pdo, 'expense.approve', $x['expense_id'], $x['engagement_id'], ['before' => ['status' => 'submitted'], 'after' => ['status' => 'approved', 'member_id' => $x['member_id']] + expense_money($row)]);
    check_budget_alerts($pdo, $x['engagement_id']);
    if ($x['member_id'] !== $me) {
        queue_expense_notice($pdo, $x['member_id'], 'expense_approved', $x['expense_id'], ['title' => 'Your expense ' . expense_label($row) . ' was approved', 'body' => $row['reimbursable'] ? 'It will be reimbursed.' : 'Paid with the company card; nothing to reimburse.',
            'dedupe' => 'exp-approved:' . $x['expense_id'] . ':' . substr((string) $row['approved_at'], 0, 19)]);
    }
    $pdo->commit();
    return $row;
});
ct_done('Approved ' . expense_label($row), $x['expense_id'], ct_land(return_path('/expenses/' . $x['expense_id']), 'approved'), 'expenseChanged', ['expense_id' => $x['expense_id'], 'status' => 'approved', 'billable_amount' => $row['billable_amount']]);
