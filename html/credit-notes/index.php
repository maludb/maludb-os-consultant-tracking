<?php
declare(strict_types=1);
/** /credit-notes/?client=&status= — the credit notes (screen `credit-note-list`): cards with the number, client, the invoice corrected, total, applied and what is left, the status chip. A credit corrects an invoice; it never refunds. billing.write (the views also show a Manager the notes of their engagements, to the tools; the screens are Billing's). */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
require_right('billing.write');
require_human();
$pdo = db();
$status = request_string('status');
if ($status !== '' && !isset(CREDIT_STATUSES[$status])) {
    $status = '';
}
$client = request_integer('client');
$rows = find_credit_notes($pdo, ['client_id' => $client, 'status' => $status], 300);
$clients = $pdo->query('SELECT client_id, name FROM mcp_clients WHERE archived_at IS NULL ORDER BY lower(name)')->fetchAll();
log_screen_view($pdo, 'credit-note-list');
if (wants_json()) {
    respond_screen(['status' => $status, 'client' => $client, 'credit_notes' => array_map('present_credit_note', $rows)]);
}
$notices = ['deleted' => ['success', 'The draft credit note was deleted.']];
render_screen('Credit notes', view('credit-notes/index.php', ['rows' => $rows, 'status' => $status, 'client' => $client, 'clients' => $clients, 'here' => here_url(), 'may' => ['draft' => has_right('billing.write')], 'notice' => ct_page_notice($notices)]),
    ['activeNav' => 'credit-notes', 'screen' => 'credit-note-list', 'entity' => 'credit_note']);
