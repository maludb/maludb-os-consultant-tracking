<?php
declare(strict_types=1);
/** Action `client_archive` (log `client.archive`; confirm): clients.manage. Refused in words while an active engagement exists. */
require_once dirname(__DIR__, 2) . '/app/features/clients/handler.php';
ct_handler_begin();
require_right('clients.manage');
$pdo = db();
$me = (int) current_member_id();
$c = client_from_request($pdo, false);
ct_guard($pdo, static function () use ($pdo, $me, $c): void {
    $pdo->beginTransaction();
    archive_client($pdo, $c['client_id'], true, $me);
    log_activity($pdo, 'client.archive', 'client', $c['client_id'], ['before' => ['archived' => false], 'after' => ['archived' => true, 'name' => $c['name']]]);
    $pdo->commit();
});
ct_done('Archived ' . $c['name'], $c['client_id'], ct_land(return_path('/clients/'), 'archived'), 'clientChanged');
