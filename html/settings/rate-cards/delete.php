<?php
declare(strict_types=1);
/** Action `rate_card_delete` (log `rate_card.delete`; confirm; `deletion` for agents): the admin. Only a card no engagement or client uses. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/write.php';
ct_handler_begin();
require_admin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('rate_card') ?? request_integer('rate_card_id') ?? refuse(422, 'Say which rate card.');
$card = find_rate_card($pdo, $id) ?? refuse(404, 'Rate card not found.');
ct_guard($pdo, static function () use ($pdo, $me, $id, $card): void {
    $pdo->beginTransaction();
    delete_rate_card($pdo, $id, $me);
    log_activity($pdo, 'rate_card.delete', 'rate_card', $id, ['before' => ['name' => $card['name'], 'currency' => $card['currency'], 'rate_count' => $card['rate_count']]]);
    $pdo->commit();
});
ct_done('Deleted ' . $card['name'], $id, ct_land(return_path('/settings/rate-cards/'), 'deleted'), 'rateCardChanged');
