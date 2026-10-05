<?php
declare(strict_types=1);
/** Action `client_contact_remove` (log `client_contact.remove`; confirm): clients.manage. */
require_once dirname(__DIR__, 3) . '/app/features/clients/handler.php';
ct_handler_begin();
require_right('clients.manage');
$pdo = db();
$me = (int) current_member_id();
$contactId = request_integer('contact') ?? request_integer('contact_id') ?? refuse(422, 'Say which contact.');
$cur = client_contact_state($pdo, $contactId) ?? refuse(404, 'Contact not found.');
$c = find_client($pdo, $cur['client_id']) ?? refuse(404, 'Contact not found.');
$was = ct_guard($pdo, static function () use ($pdo, $me, $contactId, $c): array {
    $pdo->beginTransaction();
    $was = remove_client_contact($pdo, $contactId, $me);
    log_activity($pdo, 'client_contact.remove', 'client_contact', $contactId, ['before' => ['name' => $was['name'], 'role' => $was['role'], 'receives_invoices' => $was['receives_invoices'], 'is_primary' => $was['is_primary']], 'client_id' => $c['client_id']]);
    $pdo->commit();
    return $was;
});
ct_done('Removed ' . $was['name'] . ' from ' . $c['name'], $contactId, ct_land(return_path('/clients/' . $c['client_id']), 'contact_removed', 'client-contacts'), 'clientChanged', ['client_id' => $c['client_id']]);
