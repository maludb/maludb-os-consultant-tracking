<?php
declare(strict_types=1);
/** /expenses/?status=&engagement=&member= — MY EXPENSES as cards (screen `expense-list`): number, date, category, engagement, amount, the receipt / status / billed / reimbursed chips; tabs by status; a manager's ?member=. expenses.write. */
require_once dirname(__DIR__, 2) . '/app/features/expenses/handler.php';
require_right('expenses.write');
require_human();
$pdo = db();
$me = (int) current_member_id();
$member = request_integer('member') ?? $me;
if ($member !== $me && !has_right('expenses.approve') && !db_bool($pdo, 'SELECT ct_sees_all()')) {
    refuse(404, 'Expenses not found.');
}
$who = $member === $me ? null : (string) one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :m', ['m' => $member]);
if ($member !== $me && $who === null) {
    refuse(404, 'Expenses not found.');
}
$status = (string) ($_GET['status'] ?? '');
if ($status !== '' && !isset(EXPENSE_STATUSES[$status])) {
    $status = '';
}
$engagement = request_integer('engagement');
$all = find_expenses($pdo, ['member_id' => $member, 'engagement_id' => $engagement], 300);
$counts = ['' => count($all)] + array_fill_keys(array_keys(EXPENSE_STATUSES), 0);
foreach ($all as $x) {
    $counts[$x['status']]++;
}
$rows = $status === '' ? $all : array_values(array_filter($all, static fn (array $x): bool => $x['status'] === $status));
$due = reimbursements_due($pdo, $member);
$owed = $due === [] ? [] : $due[0]['totals'];
$engName = $engagement === null ? null : (string) one_value($pdo, 'SELECT number FROM mcp_engagements WHERE engagement_id = :e', ['e' => $engagement]);
log_screen_view($pdo, 'expense-list');
if (wants_json()) {
    respond_screen(['member_id' => $member, 'status' => $status, 'counts' => $counts, 'owed' => $owed, 'expenses' => array_map('present_expense', $rows)]);
}
$notices = ['deleted' => ['success', 'The expense was removed.'], 'saved' => ['success', 'Recorded.']];
render_screen($who === null ? 'My expenses' : $who . '\'s expenses', view('expenses/index.php', ['rows' => $rows, 'counts' => $counts, 'status' => $status, 'member' => $member, 'who' => $who, 'owed' => $owed, 'engagement' => $engagement, 'engName' => $engName,
    'here' => here_url(), 'tz' => member_timezone(), 'notice' => ct_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'expenses', 'screen' => 'expense-list', 'entity' => 'expense']);
