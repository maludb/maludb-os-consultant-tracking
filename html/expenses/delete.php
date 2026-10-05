<?php
declare(strict_types=1);
/** Action `expense_delete` (log `expense.delete`; confirm; `deletion` for agents): own or manage; a draft or rejected expense (the trigger refuses the rest); its receipts and notes go with it. */
require_once dirname(__DIR__, 2) . '/app/features/expenses/handler.php';
ct_handler_begin();
require_right('expenses.write');
$pdo = db();
$me = (int) current_member_id();
$x = expense_from_request($pdo);
if (!may_act_on_expense($x, $me)) {
    refuse(403, 'You may not remove that expense.');
}
$was = ct_guard($pdo, static function () use ($pdo, $me, $x): array {
    $pdo->beginTransaction();
    $was = delete_expense($pdo, $x['expense_id'], $me);
    expense_log($pdo, 'expense.delete', $x['expense_id'], $was['engagement_id'], ['before' => ['status' => $was['status'], 'number' => $was['number'], 'amount' => $was['amount'], 'currency' => trim((string) $was['currency']), 'member_id' => $was['member_id']]]);
    $pdo->commit();
    return $was;
});
ct_done('Removed ' . $was['number'] . ' (' . fmt_money($was['amount'], trim((string) $was['currency'])) . ')', $x['expense_id'], ct_land(return_path('/expenses/'), 'deleted'), 'expenseChanged', ['number' => $was['number']]);
