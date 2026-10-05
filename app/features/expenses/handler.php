<?php
declare(strict_types=1);

/** The prelude of an expense handler: the feature's files; the expense a request names (seen → else 404); the gates; the log with the engagement's key on every row. */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once dirname(__DIR__) . '/documents/write.php';
require_once dirname(__DIR__) . '/settings/queries.php';
require_once dirname(__DIR__) . '/activity/queries.php';
require_once dirname(__DIR__) . '/engagements/queries.php';

/** The expense the request names, through the view: 404 when unseen. */
function expense_from_request(PDO $pdo): array
{
    $id = expense_id_from_request() ?? refuse(422, 'Say which expense.');
    return find_expense($pdo, $id) ?? refuse(404, 'Expense not found.');
}

/** May the caller act on this expense as its owner would: their own, or the manager of its engagement (expenses.approve). */
function may_act_on_expense(array $x, int $me): bool
{
    return $x['member_id'] === $me || (has_right('expenses.approve') && can_manage_engagement($x['engagement_id']));
}

/** log_activity with the expense as the entity and the engagement's key. */
function expense_log(PDO $pdo, string $action, int $expenseId, ?int $engagementId, array $opts = []): void
{
    log_activity($pdo, $action, 'expense', $expenseId, $opts + ['engagement_id' => $engagementId]);
}

/** The money of an expense row, for a log payload: number, amount, currency, billable_amount (never anything of cost — an expense has none). */
function expense_money(array $x): array
{
    return ['number' => $x['number'], 'amount' => $x['amount'], 'currency' => trim((string) $x['currency']), 'billable_amount' => $x['billable_amount']];
}

/** "EXP-000001 (120.00 USD)" for a sentence. */
function expense_label(array $x): string
{
    return $x['number'] . ' (' . fmt_money($x['amount'], trim((string) $x['currency'])) . ')';
}

/** The link to an expense's page, for a notice body. */
function expense_link(int $id): string
{
    return rtrim((string) env('APP_URL', ''), '/') . '/expenses/' . $id;
}

/** Submit inside the caller's transaction: the verb, the log and the managers' notice. Answers the verb's row. */
function do_submit_expense(PDO $pdo, array $x, int $me, ?array $before = null): array
{
    $row = submit_expense($pdo, $x['expense_id'], $me);
    expense_log($pdo, 'expense.submit', $x['expense_id'], $x['engagement_id'], ['before' => ['status' => $before['status'] ?? $x['status']], 'after' => ['status' => 'submitted', 'member_id' => $x['member_id'], 'submitted_by' => $me] + expense_money($row)]);
    $name = (string) one_value($pdo, 'SELECT display_name FROM members WHERE id = :m', ['m' => $x['member_id']]);
    foreach (expense_managers($pdo, $x['expense_id'], $x['member_id']) as $mgr) {
        queue_expense_notice($pdo, $mgr, 'expense_submitted', $x['expense_id'], ['title' => $name . ' submitted ' . expense_label($row), 'body' => 'Open it to approve or reject: ' . expense_link($x['expense_id']),
            'dedupe' => 'exp-submit:' . $x['expense_id'] . ':' . substr((string) $row['submitted_at'], 0, 19)]);
    }
    return $row;
}
