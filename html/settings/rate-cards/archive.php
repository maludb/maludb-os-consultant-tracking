<?php
declare(strict_types=1);
/** Action `rate_card_archive` (archived=no restores; log `rate_card.archive`): rate_cards.manage. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/write.php';
ct_handler_begin();
require_right('rate_cards.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('rate_card') ?? request_integer('rate_card_id') ?? refuse(422, 'Say which rate card.');
$card = find_rate_card($pdo, $id) ?? refuse(404, 'Rate card not found.');
$archive = ct_yes('archived', true);
ct_guard($pdo, static function () use ($pdo, $me, $id, $card, $archive): void {
    $pdo->beginTransaction();
    archive_rate_card($pdo, $id, $archive, $me);
    log_activity($pdo, 'rate_card.archive', 'rate_card', $id, ['before' => ['archived' => !$archive], 'after' => ['archived' => $archive, 'name' => $card['name']]]);
    $pdo->commit();
});
ct_done(($archive ? 'Archived ' : 'Restored ') . $card['name'], $id, ct_land(return_path($archive ? '/settings/rate-cards/' : '/settings/rate-cards/' . $id), $archive ? 'archived' : 'restored'), 'rateCardChanged');
