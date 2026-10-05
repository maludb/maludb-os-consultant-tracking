<?php
declare(strict_types=1);
/** /credit-notes/new?invoice= (screen `credit-note-add`) and /credit-notes/{id}/edit (screen `credit-note-edit`, the draft's lines): billing.write. A credit against an invoice copies its client, engagement and currency; the lines are priced here; Issue numbers it (CN-00001…). */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
require_right('billing.write');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('credit_note');
$taxRates = tax_rates_for_pick($pdo);
if ($id !== null) {
    $c = find_credit_note($pdo, $id) ?? refuse(404, 'Credit note not found.');
    if ($c['status'] !== 'draft') {
        refuse(422, 'Credit note ' . $c['number'] . ' is ' . $c['status'] . ' and cannot be edited.');
    }
    log_activity($pdo, 'screen.view', 'credit_note', $c['credit_note_id'], ['screen' => 'credit-note-edit', 'client_id' => $c['client_id'], 'invoice_id' => $c['invoice_id']]);
    if (wants_json()) {
        respond_screen(['credit_note' => present_credit_note($c, true), 'tax_rates' => $taxRates, 'line_kinds' => array_keys(CREDIT_LINE_KINDS)]);
    }
    render_screen('Edit the draft credit note', view('credit-notes/form.php', ['c' => $c, 'taxRates' => $taxRates, 'invoice' => null, 'clients' => [], 'here' => here_url(), 'notice' => ct_page_notice(['saved' => ['success', 'Saved.'], 'drafted' => ['success', 'Drafted. Add the lines it credits.'], 'line_saved' => ['success', 'Saved the line.'], 'line_removed' => ['success', 'The line was removed.']])]),
        ['activeNav' => 'credit-notes', 'screen' => 'credit-note-edit', 'entity' => 'credit_note', 'recordId' => (string) $c['credit_note_id']]);
    return;
}
$invoice = null;
if (($invId = request_integer('invoice')) !== null) {
    $invoice = find_invoice($pdo, $invId) ?? refuse(404, 'Invoice not found.');
}
$clients = $pdo->query('SELECT client_id, name FROM mcp_clients WHERE archived_at IS NULL ORDER BY lower(name)')->fetchAll();
log_screen_view($pdo, 'credit-note-add');
if (wants_json()) {
    respond_screen(['invoice' => $invoice === null ? null : ['invoice_id' => $invoice['invoice_id'], 'number' => $invoice['number'], 'client' => $invoice['client_name'], 'currency' => $invoice['currency'], 'balance_due' => $invoice['balance_due']], 'clients' => $clients]);
}
render_screen('Draft a credit note', view('credit-notes/form.php', ['c' => null, 'taxRates' => $taxRates, 'invoice' => $invoice, 'clients' => $clients, 'here' => here_url(), 'notice' => null]), ['activeNav' => 'credit-notes', 'screen' => 'credit-note-add', 'entity' => 'credit_note']);
