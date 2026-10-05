<?php
declare(strict_types=1);
/** /credit-notes/{id} — one credit note (screen `credit-note-view`): the lines, the invoices it is applied to, Issue (a draft), Apply (an amount to an open invoice of the client), Unapply, Void (unapplied). billing.write only (Phase 2's gate: a Manager and Finance have no credit-note screens). A credit never refunds. */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
require_right('billing.write');
require_human();
$pdo = db();
$c = find_credit_note($pdo, request_integer('id') ?? refuse(404, 'Credit note not found.')) ?? refuse(404, 'Credit note not found.');
$billing = has_right('billing.write');
$may = ['edit' => $billing && $c['status'] === 'draft', 'issue' => $billing && $c['status'] === 'draft' && (float) $c['total'] > 0, 'delete' => $billing && $c['status'] === 'draft', 'apply' => $billing && $c['status'] === 'issued' && (float) $c['balance'] > 0,
        'unapply' => $billing && $c['status'] === 'issued', 'void' => $billing && $c['status'] === 'issued' && (float) $c['amount_applied'] == 0.0, 'attach' => record_writable($pdo, 'credit_note', $c['credit_note_id'])];
$targets = $may['apply'] ? applicable_invoices($pdo, $c['client_id'], $c['currency']) : [];
log_activity($pdo, 'screen.view', 'credit_note', $c['credit_note_id'], ['screen' => 'credit-note-view', 'client_id' => $c['client_id'], 'invoice_id' => $c['invoice_id'], 'after' => ['number' => $c['number']]]);
if (wants_json()) {
    respond_screen(['credit_note' => present_credit_note($c, true), 'applicable_invoices' => $targets, 'may' => $may]);
}
$notices = ['drafted' => ['success', 'Drafted.'], 'saved' => ['success', 'Saved.'], 'issued' => ['success', 'Issued.'], 'applied' => ['success', 'Applied.'], 'unapplied' => ['info', 'Taken off the invoice.'], 'voided' => ['warning', 'Voided.'], 'attached' => ['success', 'Attached.'], 'file_removed' => ['success', 'The file was removed.']];
render_screen(($c['number'] ?? 'Draft credit note') . ' · ' . $c['client_name'], view('credit-notes/view.php', ['c' => $c, 'may' => $may, 'targets' => $targets, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => ct_page_notice($notices)]),
    ['activeNav' => 'credit-notes', 'screen' => 'credit-note-view', 'entity' => 'credit_note', 'recordId' => (string) $c['credit_note_id']]);
