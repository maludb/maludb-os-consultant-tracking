<?php
declare(strict_types=1);
/**
 * /invoices/{id} — one invoice (screen `invoice-view`): the head, the lines with their backing items expandable, the totals, payments, credits and claims, the link's state (live / rotated / expires — never the token) with Rotate, the client's preview,
 * the actions by status (Edit and Delete for a draft; Issue, Send, Remind, Record payment, Credit, Void, Write off, External ref for Billing), documents, notes, the timeline. A Manager reads the invoices of their engagements and may draft, never issue.
 */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
require_any_right('invoices.draft|billing.write|reports.view');
require_human();
$pdo = db();
$i = find_invoice($pdo, invoice_id_from_request() ?? refuse(404, 'Invoice not found.')) ?? refuse(404, 'Invoice not found.');
$billing = has_right('billing.write');
$open = in_array($i['status'], OPEN_INVOICE_STATUSES, true);
$drafter = has_right('invoices.draft') && can_manage_engagement($i['engagement_id']);
$may = ['edit' => $i['status'] === 'draft' && $drafter, 'delete' => $i['status'] === 'draft' && $drafter, 'issue' => $i['status'] === 'draft' && $billing, 'send' => $open && $billing, 'remind' => $open && $i['overdue'] && $billing,
        'pay' => $open && $billing, 'credit' => $billing && $i['status'] !== 'draft' && $i['status'] !== 'void', 'void' => $billing && $i['status'] !== 'draft' && !in_array($i['status'], ['void', 'written_off'], true),
        'write_off' => $open && $billing && (float) $i['balance_due'] > 0, 'external_ref' => $billing && $i['status'] !== 'draft', 'rotate' => $billing && $i['status'] !== 'draft', 'attach' => record_writable($pdo, 'invoice', $i['invoice_id']),
        'admin' => is_ct_admin(), 'billing' => $billing, 'me' => (int) current_member_id()];
$timeline = invoice_timeline($pdo, $i['invoice_id'], 60);
$notes = $i['notes'];
$engagement = $pdo->prepare('SELECT nte_amount, kind FROM mcp_engagements WHERE engagement_id = :e');
$engagement->execute(['e' => $i['engagement_id']]);
$eng = $engagement->fetch() ?: [];
$nteSentence = null;
if ($i['status'] === 'draft' && ($eng['kind'] ?? '') === 'time_and_materials' && ($eng['nte_amount'] ?? null) !== null) {
    $issued = (string) one_value($pdo, "SELECT COALESCE(sum(total), 0) FROM mcp_invoices WHERE engagement_id = :e AND status NOT IN ('draft', 'void')", ['e' => $i['engagement_id']]);
    if ((float) money_add($issued, (string) $i['total']) > (float) $eng['nte_amount']) {
        $nteSentence = 'With this draft the engagement would be invoiced ' . fmt_money(money_add($issued, (string) $i['total']), $i['currency']) . ' against its not-to-exceed of ' . fmt_money($eng['nte_amount'], $i['currency']) . '. Billing may issue it with a reason.';
    }
}
log_activity($pdo, 'screen.view', 'invoice', $i['invoice_id'], ['screen' => 'invoice-view', 'invoice_id' => $i['invoice_id'], 'engagement_id' => $i['engagement_id'], 'client_id' => $i['client_id'], 'after' => ['number' => $i['number']]]);
if (wants_json()) {
    respond_screen(['invoice' => present_invoice($i, true), 'attachments' => array_map('present_attachment', $i['attachments']), 'notes' => array_map('present_note', $notes), 'timeline' => array_map('present_activity_row', $timeline), 'may' => $may, 'nte_warning' => $nteSentence]);
}
$notices = ['drafted' => ['success', 'Drafted the invoice.'], 'saved' => ['success', 'Saved.'], 'issued' => ['success', 'Issued, and its secure link is made. Send it to the client when you are ready.'], 'sent' => ['success', 'Queued for the client with a fresh secure link.'],
    'reminded' => ['success', 'A reminder is queued.'], 'voided' => ['warning', 'Voided; its items are unbilled again.'], 'written_off' => ['warning', 'The balance is written off.'], 'payment' => ['success', 'Recorded the payment.'], 'payment_voided' => ['warning', 'Voided the payment.'],
    'ref' => ['success', 'Noted the ledger\'s reference.'], 'attached' => ['success', 'Attached.'], 'file_removed' => ['success', 'The file was removed.'], 'noted' => ['success', 'The note is added.'], 'note_removed' => ['success', 'The note was removed.']];
$title = $i['number'] ?? 'Draft invoice';
render_screen($title . ' · ' . $i['client_name'], view('invoices/view.php', ['i' => $i, 'may' => $may, 'timeline' => $timeline, 'notes' => $notes, 'nteSentence' => $nteSentence, 'here' => here_url(), 'tz' => member_timezone(), 'me' => $may['me'],
    'notice' => ct_page_notice($notices)]), ['activeNav' => 'invoices', 'screen' => 'invoice-view', 'entity' => 'invoice', 'recordId' => (string) $i['invoice_id']]);
