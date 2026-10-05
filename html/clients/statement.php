<?php
declare(strict_types=1);
/** /clients/{id}/statement?from=&to= — the client's statement (screen `client-statement`): invoices, credit notes, payments and write-offs in date order with the running balance per currency (`ct_client_statement()`); Send queues it for the client's invoice contacts. billing.write or reports.view. */
require_once dirname(__DIR__, 2) . '/app/features/clients/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
require_right('receivables.view');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('client') ?? refuse(404, 'Client not found.');
$c = find_client($pdo, $id) ?? refuse(404, 'Client not found.');
$from = request_date('from') ?: null;
$to = request_date('to') ?: null;
$rows = client_statement($pdo, $id, $from, $to);
$closing = [];
foreach ($rows as $r) {
    $closing[$r['currency']] = $r['running_balance'];
}
log_activity($pdo, 'screen.view', 'client', $id, ['screen' => 'client-statement', 'client_id' => $id]);
if (wants_json()) {
    respond_screen(['client' => present_client($c), 'from' => $from, 'to' => $to, 'closing' => $closing, 'lines' => array_map(static fn (array $r): array => ['date' => $r['entry_date'], 'kind' => $r['kind'], 'number' => $r['number'], 'description' => $r['description'], 'currency' => $r['currency'],
        'charge' => $r['charge'], 'credit' => $r['credit'], 'running_balance' => $r['running_balance']], $rows), 'may' => ['send' => has_right('billing.write')]]);
}
$notices = ['sent' => ['success', 'Queued for the client\'s invoice contacts.']];
render_screen('Statement · ' . $c['name'], view('clients/statement.php', ['c' => $c, 'rows' => $rows, 'closing' => $closing, 'from' => $from, 'to' => $to, 'here' => here_url(), 'may' => ['send' => has_right('billing.write')], 'notice' => ct_page_notice($notices)]),
    ['activeNav' => 'clients', 'screen' => 'client-statement', 'entity' => 'client', 'recordId' => (string) $id]);
