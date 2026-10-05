<?php
declare(strict_types=1);
/** /billing/unbilled?as_of=&client= — what is approved and not yet billed (screen `unbilled`): a card per engagement — hours and amount, the hours with NO rate (a warning), expenses, usage, milestones, the total, the oldest item — and Draft invoice (→ /invoices/new?engagement=&to=). invoices.draft, billing or reports.view; ct_unbilled() answers what the caller may see. */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
require_any_right('invoices.draft|billing.write|reports.view');
require_human();
$pdo = db();
$asOf = request_date('as_of') ?: null;
$client = request_integer('client');
$rows = unbilled($pdo, $asOf, null, $client);
$totals = [];
foreach ($rows as $u) {
    $totals[$u['currency']] = money_add($totals[$u['currency']] ?? '0', (string) $u['total_amount']);
}
$clients = $pdo->query('SELECT client_id, name FROM mcp_clients WHERE archived_at IS NULL ORDER BY lower(name)')->fetchAll();
log_screen_view($pdo, 'unbilled');
if (wants_json()) {
    respond_screen(['as_of' => $asOf, 'totals' => $totals, 'unbilled' => array_map('present_unbilled', $rows), 'may' => ['draft' => has_right('invoices.draft')]]);
}
render_screen('Unbilled work', view('billing/unbilled.php', ['rows' => $rows, 'totals' => $totals, 'asOf' => $asOf, 'client' => $client, 'clients' => $clients, 'here' => here_url(), 'may' => ['draft' => has_right('invoices.draft')]]), ['activeNav' => 'unbilled', 'screen' => 'unbilled', 'entity' => 'engagement']);
