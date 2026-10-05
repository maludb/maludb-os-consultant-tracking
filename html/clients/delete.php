<?php
declare(strict_types=1);
/** Action `client_delete` (log `client.delete`; confirm; `deletion` for agents): the admin. Only a client no engagement ever named. */
require_once dirname(__DIR__, 2) . '/app/features/clients/handler.php';
ct_handler_begin();
require_admin();
$pdo = db();
$me = (int) current_member_id();
$c = client_from_request($pdo, false);
ct_guard($pdo, static function () use ($pdo, $me, $c): void {
    $pdo->beginTransaction();
    $name = delete_client($pdo, $c['client_id'], $me);
    log_activity($pdo, 'client.delete', 'client', $c['client_id'], ['before' => ['name' => $name, 'code' => $c['code']]]);
    $pdo->commit();
});
ct_done('Deleted ' . $c['name'], $c['client_id'], ct_land(return_path('/clients/'), 'deleted'), 'clientChanged');
