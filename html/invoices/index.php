<?php
declare(strict_types=1);
/** /invoices/?q=&client=&engagement=&status=&overdue= — the invoices (screen `invoice-list`): rows on a desktop, cards on a phone; the number, client, engagement, issue and due, total, paid, balance, the status / overdue / claims chips; a totals footer per currency. invoices.draft, reports.view or billing.write; the view decides which. */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
require_any_right('invoices.draft|billing.write|reports.view');
require_human();
$pdo = db();
$status = request_string('status');
if ($status !== '' && !isset(INVOICE_STATUSES[$status]) && $status !== 'open') {
    $status = '';
}
$filters = ['q' => request_string('q'), 'client_id' => request_integer('client'), 'engagement_id' => request_integer('engagement'), 'status' => $status === '' || $status === 'open' ? [] : [$status], 'open' => $status === 'open', 'overdue' => request_bool('overdue')];
$rows = find_invoices($pdo, $filters, 300);
$totals = invoice_totals($pdo, $filters);
$counts = [];
foreach (['' => [], 'open' => ['open' => true], 'draft' => ['status' => ['draft']], 'paid' => ['status' => ['paid']], 'void' => ['status' => ['void', 'written_off']]] as $k => $f) {
    $counts[$k] = count_invoices($pdo, $f + ['client_id' => $filters['client_id'], 'engagement_id' => $filters['engagement_id'], 'q' => $filters['q']]);
}
$counts['overdue'] = count_invoices($pdo, ['overdue' => true, 'client_id' => $filters['client_id'], 'engagement_id' => $filters['engagement_id'], 'q' => $filters['q']]);
$clients = $pdo->query('SELECT client_id, name FROM mcp_clients WHERE archived_at IS NULL ORDER BY lower(name)')->fetchAll();
$engagements = $pdo->query('SELECT engagement_id, number, name FROM mcp_engagements ORDER BY number')->fetchAll();
log_screen_view($pdo, 'invoice-list');
if (wants_json()) {
    respond_screen(['filters' => ['q' => $filters['q'], 'client' => $filters['client_id'], 'engagement' => $filters['engagement_id'], 'status' => $status, 'overdue' => $filters['overdue']], 'counts' => $counts, 'totals' => $totals, 'invoices' => array_map('present_invoice', $rows),
        'may' => ['draft' => has_right('invoices.draft'), 'billing' => has_right('billing.write')]]);
}
$notices = ['deleted' => ['success', 'The draft was deleted.']];
render_screen('Invoices', view('invoices/index.php', ['rows' => $rows, 'totals' => $totals, 'counts' => $counts, 'status' => $status, 'filters' => $filters, 'clients' => $clients, 'engagements' => $engagements, 'here' => here_url(),
    'may' => ['draft' => has_right('invoices.draft')], 'notice' => ct_page_notice($notices)]), ['activeNav' => 'invoices', 'screen' => 'invoice-list', 'entity' => 'invoice']);
