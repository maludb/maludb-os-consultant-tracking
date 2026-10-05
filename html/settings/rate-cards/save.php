<?php
declare(strict_types=1);
/** Action `rate_card_save` (by the presence of `rate_card`; log `rate_card.save`: name, currency, effective_from, copy_of): rate_cards.manage. The currency is fixed once an engagement uses the card. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/write.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
ct_handler_begin();
require_right('rate_cards.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('rate_card') ?? request_integer('rate_card_id');
$cur = null;
if ($id !== null) {
    find_rate_card($pdo, $id) ?? refuse(404, 'Rate card not found.');
    $cur = rate_card_state($pdo, $id);
    if ($cur['archived_at'] !== null) {
        refuse(422, $cur['name'] . ' is archived; restore it first.');
    }
}
$errors = [];
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 80) {
    $errors['name'] = 'Give the card a name of up to 80 characters.';
}
$currency = $cur['currency'] ?? null;
if (req_has('currency') || $cur === null) {
    $currency = strtoupper(trim((string) req_val('currency')));
    if ($currency === '' && $cur === null) {
        $currency = find_settings($pdo)['base_currency'];
    }
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $errors['currency'] = 'The currency is a three-letter code (USD).';
    }
}
$from = $cur['effective_from'] ?? date('Y-m-d');
if (req_has('effective_from')) {
    $d = request_date('effective_from');
    if ($d === false) {
        $errors['effective_from'] = 'The effective date is a date (2026-01-01).';
    } elseif ($d !== null) {
        $from = $d;
    }
}
$description = req_has('description') ? ((string) req_val('description') === '' ? null : mb_substr((string) req_val('description'), 0, 500)) : ($cur['description'] ?? null);
$copyOf = $cur === null ? ct_ref($pdo, 'copy_of', null, 'SELECT 1 FROM mcp_rate_cards WHERE rate_card_id = :id', 'card to copy', $errors) : null;
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$f = ['name' => $name, 'currency' => $currency, 'effective_from' => $from, 'description' => $description];
$newId = ct_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f, $copyOf): int {
    $pdo->beginTransaction();
    $newId = save_rate_card($pdo, $id, $f, $copyOf, $me);
    $after = array_intersect_key(rate_card_state($pdo, $newId), array_flip(['name', 'currency', 'effective_from']));
    if ($cur === null) {
        log_activity($pdo, 'rate_card.save', 'rate_card', $newId, ['after' => $after + ['copy_of' => $copyOf, 'rate_count' => (int) one_value($pdo, 'SELECT count(*) FROM rate_card_rates WHERE rate_card_id = :id', ['id' => $newId])]]);
    } else {
        $d = ct_diff(array_intersect_key($cur, array_flip(['name', 'currency', 'effective_from'])), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'rate_card.save', 'rate_card', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
ct_done(($id === null ? 'Added the rate card ' : 'Saved the rate card ') . $f['name'], $newId, ct_land(return_path('/settings/rate-cards/' . $newId), 'saved'), 'rateCardChanged', ['rate_card_id' => $newId]);
