<?php
declare(strict_types=1);
/** /expenses/new?engagement=&date=&member= and /expenses/{id}/edit (screens `expense-add`, `expense-edit`): expenses.write; the camera first; a manager records for someone with ?member=. A draft or rejected expense only. */
require_once dirname(__DIR__, 2) . '/app/features/expenses/handler.php';
require_right('expenses.write');
require_human();
$pdo = db();
$me = (int) current_member_id();
$id = expense_id_from_request();
$cur = null;
$member = request_integer('member') ?? $me;
if ($id !== null) {
    $cur = find_expense($pdo, $id) ?? refuse(404, 'Expense not found.');
    $member = $cur['member_id'];
    if (!in_array($cur['status'], ['draft', 'rejected'], true)) {
        refuse(422, 'This expense is ' . $cur['status'] . '; a manager sends it back for it to change.');
    }
    if (!may_act_on_expense($cur, $me)) {
        refuse(403, 'You may not change that expense.');
    }
} elseif ($member !== $me && !has_right('expenses.approve')) {
    refuse(403, 'You may not record expenses for that person.');
}
$date = $cur['expense_date'] ?? (request_date('date') ?: date('Y-m-d'));
$engagements = expense_engagements($pdo, $member, $date);
$categories = expense_categories_for_pick($pdo);
$settings = find_settings($pdo);
$whoName = $member === $me ? null : (string) one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :m', ['m' => $member]);
$mayMarkup = is_ct_admin() || (has_right('expenses.approve') && ($cur !== null ? can_manage_engagement($cur['engagement_id']) : true));
$screen = $cur === null ? 'expense-add' : 'expense-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['expense' => $cur === null ? null : present_expense($cur), 'member_id' => $member, 'date' => $date, 'engagements' => $engagements, 'categories' => $categories, 'receipt_threshold' => $settings['receipt_threshold'],
        'default_markup_pct' => $settings['default_markup_pct'], 'paid_with' => PAID_WITH, 'may_set_markup' => $mayMarkup]);
}
render_screen($cur === null ? 'Record an expense' : 'Change an expense', view('expenses/form.php', ['cur' => $cur, 'member' => $member, 'me' => $me, 'who' => $whoName, 'date' => $date, 'engagements' => $engagements, 'categories' => $categories,
    'prefill' => request_integer('engagement'), 'settings' => $settings, 'mayMarkup' => $mayMarkup, 'here' => here_url()]), ['activeNav' => 'expenses', 'screen' => $screen, 'entity' => 'expense', 'recordId' => $id === null ? '' : (string) $id]);
