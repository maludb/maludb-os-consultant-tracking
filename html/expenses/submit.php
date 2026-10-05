<?php
declare(strict_types=1);
/** Action `expense_submit` (log `expense.submit`: number, amount, currency, billable_amount): own or manage. The database refuses a missing receipt at or above the threshold in its own words; the engagement's manager is told. */
require_once dirname(__DIR__, 2) . '/app/features/expenses/handler.php';
ct_handler_begin();
require_right('expenses.write');
$pdo = db();
$me = (int) current_member_id();
$x = expense_from_request($pdo);
if (!may_act_on_expense($x, $me)) {
    refuse(403, 'You may not submit that expense.');
}
$row = ct_guard($pdo, static function () use ($pdo, $me, $x): array {
    $pdo->beginTransaction();
    $row = do_submit_expense($pdo, $x, $me);
    $pdo->commit();
    return $row;
});
ct_done('Submitted ' . expense_label($row), $x['expense_id'], ct_land(return_path('/expenses/' . $x['expense_id']), 'submitted'), 'expenseChanged', ['expense_id' => $x['expense_id'], 'status' => 'submitted']);
