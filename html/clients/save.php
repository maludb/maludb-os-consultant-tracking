<?php
declare(strict_types=1);
/**
 * Actions `client_save` / `client_update` (by the presence of `client`; log `client.create` / `client.update` — changed fields; never email, phone, address or tax id): clients.manage.
 * The code is uppercased; the currency defaults to the base currency and terms to the settings' when left empty on a create; a field left out stays.
 */
require_once dirname(__DIR__, 2) . '/app/features/clients/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
ct_handler_begin();
require_right('clients.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('client') ?? request_integer('client_id');
$cur = null;
if ($id !== null) {
    find_client($pdo, $id) ?? refuse(404, 'Client not found.');
    $cur = client_state($pdo, $id);
    if ($cur['archived_at'] !== null) {
        refuse(422, $cur['name'] . ' is archived; restore it first.');
    }
}
$settings = find_settings($pdo);
$errors = [];
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 120) {
    $errors['name'] = 'Give the client a name of up to 120 characters.';
}
$text = static fn (string $k, int $max) => req_has($k) ? ((string) req_val($k) === '' ? null : mb_substr((string) req_val($k), 0, $max)) : ($cur[$k] ?? null);
$legal = $text('legal_name', 200);
$phone = $text('phone', 40);
$address = $text('billing_address', 500);
$taxId = $text('tax_id', 60);
$notes = $text('notes', 4000);
$code = $cur['code'] ?? null;
if (req_has('code')) {
    $code = strtoupper(trim((string) req_val('code')));
    if ($code === '') {
        $code = null;
    } elseif (!preg_match('/^[A-Z0-9]{2,8}$/', $code)) {
        $errors['code'] = 'The code is 2 to 8 letters or digits (ACME).';
    }
}
$email = $cur['email'] ?? null;
if (req_has('email')) {
    $email = (string) req_val('email') === '' ? null : mb_substr((string) req_val('email'), 0, 200);
    if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'The email address does not look right.';
    }
}
$terms = ct_int('terms_days', $cur['terms_days'] ?? null, 0, 365, 'Terms', $errors, true);
if ($cur === null && !req_has('terms_days')) {
    $terms = null;                                         // null = the settings' default, shown as such
}
$currency = $cur['currency'] ?? null;
if (req_has('currency')) {
    $currency = strtoupper(trim((string) req_val('currency')));
    if ($currency === '') {
        $currency = null;
    } elseif (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $errors['currency'] = 'The currency is a three-letter code (USD).';
    }
}
$po = ct_yes('po_required', $cur['po_required'] ?? false);
$cardField = req_has('rate_card') ? 'rate_card' : 'default_rate_card_id';
$card = ct_ref($pdo, $cardField, $cur['default_rate_card_id'] ?? null, 'SELECT 1 FROM rate_cards WHERE id = :id AND archived_at IS NULL', 'rate card', $errors);
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$f = ['name' => $name, 'legal_name' => $legal, 'code' => $code, 'email' => $email, 'phone' => $phone, 'billing_address' => $address, 'tax_id' => $taxId, 'terms_days' => $terms,
      'currency' => $currency, 'po_required' => $po, 'default_rate_card_id' => $card, 'notes' => $notes];
$newId = ct_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_client($pdo, $id, $f, $me);
    $after = client_loggable(client_state($pdo, $newId));
    if ($cur === null) {
        log_activity($pdo, 'client.create', 'client', $newId, ['after' => $after]);
    } else {
        $d = ct_diff(client_loggable($cur), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'client.update', 'client', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
ct_done(($id === null ? 'Added ' : 'Saved ') . $f['name'], $newId, ct_land(return_path('/clients/' . $newId), 'saved'), 'clientChanged', ['client_id' => $newId]);
