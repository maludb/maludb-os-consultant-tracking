<?php
declare(strict_types=1);
/** /payments/?client=&from=&to= — the payments recorded (screen `payment-list`) with their source (manual, a client's claim, the ledger) and Void. A payment is a record of STATUS; nothing here pays anyone. billing.write. */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
require_right('billing.write');
require_human();
$pdo = db();
$filters = ['client_id' => request_integer('client'), 'from' => request_date('from') ?: null, 'to' => request_date('to') ?: null, 'status' => request_string('status')];
$rows = find_payments($pdo, $filters, 300);
$totals = payment_totals($rows);
$clients = $pdo->query('SELECT client_id, name FROM mcp_clients WHERE archived_at IS NULL ORDER BY lower(name)')->fetchAll();
log_screen_view($pdo, 'payment-list');
if (wants_json()) {
    respond_screen(['filters' => ['client' => $filters['client_id'], 'from' => $filters['from'], 'to' => $filters['to']], 'totals' => $totals, 'payments' => array_map(static fn (array $p): array => present_payment($p) + ['client' => ['client_id' => $p['client_id'], 'name' => $p['client_name']]], $rows)]);
}
render_screen('Payments', view('payments/index.php', ['rows' => $rows, 'totals' => $totals, 'filters' => $filters, 'clients' => $clients, 'here' => here_url(), 'notice' => ct_page_notice([])]), ['activeNav' => 'payments', 'screen' => 'payment-list', 'entity' => 'payment']);
