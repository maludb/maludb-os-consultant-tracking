<?php
declare(strict_types=1);
/** /clients/{id}/statement — the client's statement for a period (screen `client-statement`). Slice 6 builds it over ct_client_statement(); the shape stands now. */
require_once dirname(__DIR__, 2) . '/app/features/clients/handler.php';
require_any_right('billing.write|reports.view');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('client') ?? refuse(404, 'Client not found.');
$c = find_client($pdo, $id) ?? refuse(404, 'Client not found.');
log_screen_view($pdo, 'client-statement');
if (wants_json()) {
    respond_screen(['client' => present_client($c), 'from' => request_string('from'), 'to' => request_string('to'), 'lines' => [], 'built' => false]);
}
render_screen('Statement · ' . $c['name'], view('shared/header.php', ['id' => 'client-statement', 'title' => 'Statement', 'crumbs' => [['Home', '/'], ['Clients', '/clients/'], [$c['name'], '/clients/' . $id], ['Statement', null]], 'back' => back_link() ?? ['/clients/' . $id, $c['name']]])
    . '<div class="main-content" id="client-statement-content"><div class="card"><div class="card-body text-muted" id="client-statement-coming">The statement — invoices, credit notes, payments and write-offs with the running balance — is built by slice 6 (billing).</div></div></div>',
    ['activeNav' => 'clients', 'screen' => 'client-statement', 'entity' => 'client', 'recordId' => (string) $id]);
