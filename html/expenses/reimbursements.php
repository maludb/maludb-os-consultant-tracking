<?php
declare(strict_types=1);
/** /expenses/reimbursements?member= — what each person is owed (screen `reimbursements`): per person the approved personal-card expenses not yet reimbursed with the total per currency; Mark reimbursed (one, or all of a person) with a reference. billing.write. */
require_once dirname(__DIR__, 2) . '/app/features/expenses/handler.php';
require_right('billing.write');
require_human();
$pdo = db();
$member = request_integer('member');
$groups = reimbursements_due($pdo, $member);
log_screen_view($pdo, 'reimbursements');
if (wants_json()) {
    respond_screen(['member_id' => $member, 'people' => array_map('present_reimbursement_group', $groups)]);
}
$notices = ['reimbursed' => ['success', 'Marked reimbursed; the person is told.']];
render_screen('Reimbursements', view('expenses/reimbursements.php', ['groups' => $groups, 'member' => $member, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => ct_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'reimbursements', 'screen' => 'reimbursements', 'entity' => 'expense']);
