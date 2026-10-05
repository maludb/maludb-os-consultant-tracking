<?php
declare(strict_types=1);
/** /engagements/{id} — one engagement with tabs (screen `engagement-view`, param tab): overview (budget bars, alerts, documents, notes), staffing, rates, milestones, time/expenses/usage/invoices (slices 3–6), timeline. */
require_once dirname(__DIR__, 2) . '/app/features/engagements/handler.php';
require_right('engagements.view');
require_human();
$pdo = db();
$e = engagement_from_request($pdo, request_integer('id') ?? request_integer('engagement') ?? refuse(404, 'Engagement not found.'));
$tab = request_string('tab', 'overview');
if (!in_array($tab, ['overview', 'staffing', 'rates', 'milestones', 'time', 'expenses', 'usage', 'invoices', 'timeline'], true)) {
    $tab = 'overview';
}
if ($tab === 'rates') {
    require_right('rates.view');
}
require_once dirname(__DIR__, 2) . '/app/features/invoices/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/invoices/present.php';
$invoices = null;
$wip = null;
if ($tab === 'invoices' && (has_right('invoices.draft') || has_right('billing.write') || has_right('reports.view'))) {            // slice 6: the invoices of this engagement and what is unbilled on it
    $invoices = engagement_invoices($pdo, $e['engagement_id'], 100);
    $wip = unbilled($pdo, null, $e['engagement_id'])[0] ?? null;
}
$attachments = find_attachments($pdo, 'engagement', $e['engagement_id']);
$notes = find_notes($pdo, 'engagement', $e['engagement_id']);
$timeline = $tab === 'timeline' ? engagement_timeline($pdo, $e['engagement_id'], 100) : engagement_timeline($pdo, $e['engagement_id'], 8);
$may = ['manage' => $e['i_manage'], 'rates' => has_right('rates.view'), 'ai' => ai_amount_visible(), 'attach' => record_writable($pdo, 'engagement', $e['engagement_id']), 'admin' => is_ct_admin(),
        'staff' => $e['i_manage'] && has_right('staffing.write'), 'milestones' => $e['i_manage'] && has_right('milestones.write'), 'log' => has_right('time.write') && ($e['i_am_staffed'] || $e['manager_member_id'] === (int) current_member_id()) && $e['status'] === 'active'];
log_screen_view($pdo, match ($tab) { 'staffing' => 'engagement-staffing', 'rates' => 'engagement-rates', 'milestones' => 'engagement-milestones', default => 'engagement-view' });
if (wants_json()) {
    respond_screen(['tab' => $tab, 'engagement' => present_engagement($e, true), 'attachments' => array_map('present_attachment', $attachments), 'notes' => array_map('present_note', $notes),
        'timeline' => array_map('present_activity_row', $timeline), 'may' => $may]
        + ($tab === 'invoices' ? ['invoices' => $invoices === null ? null : array_map('present_invoice', $invoices), 'unbilled' => $wip === null ? null : present_unbilled($wip)] : []));
}
$notices = ['saved' => ['success', 'Saved the engagement.'], 'activated' => ['success', $e['number'] . ' is active.'], 'held' => ['warning', $e['number'] . ' is on hold.'], 'closed' => ['success', $e['number'] . ' is closed.'],
            'reopened' => ['success', $e['number'] . ' is active again.'], 'staffed' => ['success', 'Saved the staffing.'], 'unstaffed' => ['success', 'The staffing is ended.'], 'rate' => ['success', 'Saved the rate.'],
            'milestone' => ['success', 'Saved the milestone.'], 'delivered' => ['success', 'The milestone is delivered.'], 'accepted' => ['success', 'The milestone is accepted.'], 'milestone_cancelled' => ['success', 'The milestone is cancelled.'],
            'milestone_deleted' => ['success', 'The milestone was deleted.'], 'attached' => ['success', 'The file is attached.'], 'file_removed' => ['success', 'The file was removed.'], 'noted' => ['success', 'The note is added.'],
            'note_removed' => ['success', 'The note was removed.'], 'checked' => ['info', 'The budgets were checked.'], 'acknowledged' => ['success', 'The alert is acknowledged.']];
render_screen($e['number'] . ' · ' . $e['name'], view('engagements/view.php', ['e' => $e, 'tab' => $tab, 'invoices' => $invoices, 'wip' => $wip, 'attachments' => $attachments, 'notes' => $notes, 'timeline' => $timeline, 'may' => $may, 'here' => here_url(),
    'me' => (int) current_member_id(), 'notice' => ct_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'engagements', 'screen' => 'engagement-view', 'entity' => 'engagement', 'recordId' => (string) $e['engagement_id']]);
