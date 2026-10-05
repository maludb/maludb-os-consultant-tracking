<?php
declare(strict_types=1);
/** /usage/{id} — one charge (screen `usage-view`): the money, the source (the import, the schedule, or the statement lines and rules behind a kernel charge with their pieces), documents, notes, Approve / Void, the timeline. usage.write. */
require_once dirname(__DIR__, 2) . '/app/features/usage/handler.php';
require_right('usage.write');
require_human();
$pdo = db();
$u = usage_from_request($pdo);
$manages = can_manage_engagement($u['engagement_id']);
$may = ['edit' => $u['status'] === 'draft' && $manages, 'approve' => $u['status'] === 'draft' && $manages && has_right('usage.approve'), 'void' => $u['status'] !== 'void' && $manages && !$u['billed'],
        'delete' => $u['status'] === 'draft' && $manages && $u['source'] !== 'kernel_ledger', 'attach' => record_writable($pdo, 'usage_charge', $u['usage_charge_id']), 'admin' => is_ct_admin(), 'me' => (int) current_member_id()];
$timeline = usage_timeline($pdo, $u['usage_charge_id'], 50);
log_activity($pdo, 'screen.view', 'usage_charge', $u['usage_charge_id'], ['screen' => 'usage-view', 'after' => ['number' => $u['number']], 'engagement_id' => $u['engagement_id']]);
if (wants_json()) {
    respond_screen(['charge' => present_usage_charge($u), 'import' => $u['import'] === null ? null : ['import_id' => (int) $u['import']['import_id'], 'file_name' => $u['import']['file_name']],
        'statement' => $u['statement'] === null ? null : ['statement_id' => (int) $u['statement']['ai_statement_id'], 'period' => $u['statement']['period']],
        'pieces' => array_map(static fn (array $p): array => ['amount' => $p['amount'], 'provider' => $p['provider'], 'model' => $p['model'], 'agent' => $p['agent'], 'department' => $p['department'], 'application' => $p['application'],
            'rule' => $p['rule_id'] === null ? null : ['rule_id' => (int) $p['rule_id'], 'name' => $p['rule_name'], 'share_pct' => $p['share_pct']]], $u['pieces']),
        'documents' => array_map('present_attachment', $u['documents']), 'notes' => array_map('present_note', $u['notes']), 'timeline' => array_map('present_activity_row', $timeline), 'may' => $may]);
}
$notices = ['saved' => ['success', 'Saved.'], 'approved' => ['success', 'Approved; it is work in progress for billing.'], 'voided' => ['warning', 'Voided.'], 'attached' => ['success', 'Attached.']];
render_screen($u['number'], view('usage/view.php', ['u' => $u, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'timeline' => $timeline, 'notice' => ct_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'usage', 'screen' => 'usage-view', 'entity' => 'usage_charge', 'recordId' => (string) $u['usage_charge_id']]);
