<?php
declare(strict_types=1);
/** Action `client_restore` (log `client.restore`): clients.manage. */
require_once dirname(__DIR__, 2) . '/app/features/clients/handler.php';
ct_handler_begin();
require_right('clients.manage');
$pdo = db();
$me = (int) current_member_id();
$c = client_from_request($pdo, false);
ct_guard($pdo, static function () use ($pdo, $me, $c): void {
    $pdo->beginTransaction();
    archive_client($pdo, $c['client_id'], false, $me);
    log_activity($pdo, 'client.restore', 'client', $c['client_id'], ['before' => ['archived' => true], 'after' => ['archived' => false, 'name' => $c['name']]]);
    $pdo->commit();
});
ct_done('Restored ' . $c['name'], $c['client_id'], ct_land(return_path('/clients/' . $c['client_id']), 'restored'), 'clientChanged');
