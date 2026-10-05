<?php
declare(strict_types=1);
/** /clients/new and /clients/{id}/edit (screens `client-add`, `client-edit`; clients.manage). */
require_once dirname(__DIR__, 2) . '/app/features/clients/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_right('clients.manage');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('client');
$cur = null;
if ($id !== null) {
    $cur = find_client($pdo, $id) ?? refuse(404, 'Client not found.');
    if ($cur['archived_at'] !== null) {
        refuse(422, $cur['name'] . ' is archived; restore it first.');
    }
}
$screen = $cur === null ? 'client-add' : 'client-edit';
$settings = find_settings($pdo);
$cards = rate_cards_for_pick($pdo);
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['client' => $cur === null ? null : present_client($cur), 'rate_cards' => $cards, 'defaults' => ['currency' => $settings['base_currency'], 'terms_days' => $settings['default_terms_days']]]);
}
render_screen($cur === null ? 'Add a client' : 'Change ' . $cur['name'], view('clients/form.php', ['cur' => $cur, 'cards' => $cards, 'settings' => $settings, 'here' => here_url()]),
    ['activeNav' => 'clients', 'screen' => $screen, 'entity' => 'client', 'recordId' => $id === null ? '' : (string) $id]);
