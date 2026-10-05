<?php
declare(strict_types=1);
/**
 * Action `expense_mark_reimbursed` (log `expense.reimburse` per expense: number, amount, currency, reference; confirm; `other` for agents): billing.write. `expense` (one) or `expenses` (a list), or `member` (every one that person is
 * owed), with one `reference` (the pay run or payment). The verb refuses one that is not approved, was paid with the company card, or is already reimbursed — all or nothing. The person is told with the reference.
 */
require_once dirname(__DIR__, 2) . '/app/features/expenses/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$ids = request_list('expenses') ?? [];
if (expense_id_from_request() !== null) {
    $ids[] = (string) expense_id_from_request();
}
$member = request_integer('member') ?? request_integer('member_id');
if ($ids === [] && $member !== null) {
    foreach (reimbursements_due($pdo, $member) as $g) {
        foreach ($g['expenses'] as $x) {
            $ids[] = (string) $x['expense_id'];
        }
    }
    if ($ids === []) {
        refuse(422, 'Nothing is owed to that person.');
    }
}
if ($ids === []) {
    ct_refuse_fields(['expense' => 'Say which expense (or a person, to pay all they are owed).']);
}
$rows = [];
foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
    $rows[] = find_expense($pdo, $id) ?? refuse(404, 'Expense not found.');
}
$reference = (string) req_val('reference') === '' ? null : mb_substr((string) req_val('reference'), 0, 200);
$n = ct_guard($pdo, static function () use ($pdo, $me, $rows, $reference): int {
    $pdo->beginTransaction();
    $n = mark_reimbursed($pdo, array_column($rows, 'expense_id'), $me, $reference);
    foreach ($rows as $x) {
        expense_log($pdo, 'expense.reimburse', $x['expense_id'], $x['engagement_id'], ['before' => ['reimbursed' => false], 'after' => ['reimbursed' => true, 'member_id' => $x['member_id'], 'reference' => $reference] + expense_money($x)]);
        queue_expense_notice($pdo, $x['member_id'], 'expense_reimbursed', $x['expense_id'], ['title' => 'Your expense ' . expense_label($x) . ' was reimbursed', 'body' => $reference === null ? 'It was marked paid.' : 'Reference: ' . $reference,
            'dedupe' => 'exp-reimbursed:' . $x['expense_id']]);
    }
    $pdo->commit();
    return $n;
});
$first = $rows[0];
ct_done($n === 1 ? 'Marked ' . expense_label($first) . ' reimbursed' : 'Marked ' . $n . ' expenses reimbursed', $first['expense_id'], ct_land(return_path('/expenses/reimbursements'), 'reimbursed'), 'expenseChanged', ['reimbursed' => $n, 'reference' => $reference]);
