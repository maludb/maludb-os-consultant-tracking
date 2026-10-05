<?php
declare(strict_types=1);
/** Action `rate_card_rate_save` (log `rate_card.rate`: role_id, bill_rate before/after): rate_cards.manage. An empty bill_rate clears the role's rate on this card. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/write.php';
ct_handler_begin();
require_right('rate_cards.manage');
$pdo = db();
$me = (int) current_member_id();
$cardId = request_integer('rate_card') ?? request_integer('rate_card_id') ?? refuse(422, 'Say which rate card.');
$card = find_rate_card($pdo, $cardId) ?? refuse(404, 'Rate card not found.');
if ($card['archived_at'] !== null) {
    refuse(422, $card['name'] . ' is archived; restore it first.');
}
$errors = [];
$roleId = ct_ref($pdo, req_has('role') ? 'role' : 'consulting_role_id', null, 'SELECT 1 FROM consulting_roles WHERE id = :id AND archived_at IS NULL', 'consulting role', $errors, false);
$rate = null;
if (req_has('bill_rate') && (string) req_val('bill_rate') !== '') {
    $rate = parse_money(req_val('bill_rate'));
    if ($rate === null || (float) $rate < 0) {
        $errors['bill_rate'] = 'The rate is an amount per hour of zero or more.';
    }
}
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$before = ct_guard($pdo, static function () use ($pdo, $me, $cardId, $roleId, $rate): ?string {
    $pdo->beginTransaction();
    $before = save_rate_card_rate($pdo, $cardId, $roleId, $rate, $me);
    if ($before !== $rate) {
        log_activity($pdo, 'rate_card.rate', 'rate_card', $cardId, ['before' => ['role_id' => $roleId, 'bill_rate' => $before], 'after' => ['role_id' => $roleId, 'bill_rate' => $rate]]);
    }
    $pdo->commit();
    return $before;
});
$title = (string) one_value($pdo, 'SELECT title FROM consulting_roles WHERE id = :id', ['id' => $roleId]);
ct_done(($rate === null ? 'Cleared the rate for ' : 'Set the rate for ') . $title . ' on ' . $card['name'] . ($rate === null ? '' : ' to ' . fmt_money($rate, $card['currency'])), $cardId, ct_land(return_path('/settings/rate-cards/' . $cardId), 'rate', 'rate-row-' . $roleId), 'rateCardChanged', ['role_id' => $roleId, 'bill_rate' => $rate]);
