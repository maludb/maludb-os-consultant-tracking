<?php
declare(strict_types=1);
/** /clients/ — the clients as cards (screen `client-list`; engagements.view: the view shows each person the clients of their engagements; params q, archived). */
require_once dirname(__DIR__, 2) . '/app/features/clients/handler.php';
require_right('engagements.view');
require_human();
$pdo = db();
$filters = ['q' => mb_substr(request_string('q'), 0, 80), 'archived' => request_bool('archived')];
$rows = find_clients($pdo, $filters);
log_screen_view($pdo, 'client-list');
$may = ['manage' => has_right('clients.manage'), 'billing' => has_right('billing.write') || has_right('reports.view')];
if (wants_json()) {
    respond_screen(['filters' => $filters, 'clients' => array_map('present_client', $rows), 'may' => $may]);
}
$notices = ['archived' => ['success', 'The client was archived.'], 'deleted' => ['success', 'The client was deleted.'], 'restored' => ['success', 'The client is back.']];
render_screen('Clients', view('clients/index.php', ['rows' => $rows, 'filters' => $filters, 'may' => $may, 'here' => here_url(), 'notice' => ct_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'clients', 'screen' => 'client-list', 'entity' => 'client']);
