<?php
declare(strict_types=1);
/** Action `client_contact_save` (by the presence of `contact`; log `client_contact.save`: name, role, receives_invoices, is_primary — never the email or phone): clients.manage. */
require_once dirname(__DIR__, 3) . '/app/features/clients/handler.php';
ct_handler_begin();
require_right('clients.manage');
$pdo = db();
$me = (int) current_member_id();
$contactId = request_integer('contact') ?? request_integer('contact_id');
$cur = null;
if ($contactId !== null) {
    $cur = client_contact_state($pdo, $contactId) ?? refuse(404, 'Contact not found.');
    $_POST['client'] = (string) $cur['client_id'];
}
$c = client_from_request($pdo);
$errors = [];
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 120) {
    $errors['name'] = 'Give the contact a name of up to 120 characters.';
}
$email = $cur['email'] ?? null;
if (req_has('email')) {
    $email = (string) req_val('email') === '' ? null : mb_substr((string) req_val('email'), 0, 200);
    if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'The email address does not look right.';
    }
}
$phone = req_has('phone') ? ((string) req_val('phone') === '' ? null : mb_substr((string) req_val('phone'), 0, 40)) : ($cur['phone'] ?? null);
$role = req_has('role') ? ((string) req_val('role') === '' ? null : mb_substr((string) req_val('role'), 0, 80)) : ($cur['role'] ?? null);
$receives = ct_yes('receives_invoices', $cur['receives_invoices'] ?? false);
$primary = ct_yes('is_primary', $cur['is_primary'] ?? false);
if ($receives && $email === null) {
    $errors['email'] = 'A contact who receives invoices needs an email address.';
}
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$f = ['name' => $name, 'email' => $email, 'phone' => $phone, 'role' => $role, 'receives_invoices' => $receives, 'is_primary' => $primary];
$loggable = static fn (array $s): array => ['name' => $s['name'], 'role' => $s['role'], 'receives_invoices' => $s['receives_invoices'], 'is_primary' => $s['is_primary'], 'has_email' => $s['email'] !== null];
$newId = ct_guard($pdo, static function () use ($pdo, $me, $c, $contactId, $cur, $f, $loggable): int {
    $pdo->beginTransaction();
    $newId = save_client_contact($pdo, $contactId, $c['client_id'], $f, $me);
    $after = $loggable(client_contact_state($pdo, $newId));
    if ($cur === null) {
        log_activity($pdo, 'client_contact.save', 'client_contact', $newId, ['after' => $after, 'client_id' => $c['client_id']]);
    } else {
        $d = ct_diff($loggable($cur), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'client_contact.save', 'client_contact', $newId, ['before' => $d['before'], 'after' => $d['after'], 'client_id' => $c['client_id']]);
        }
    }
    $pdo->commit();
    return $newId;
});
ct_done(($contactId === null ? 'Added ' : 'Saved ') . $f['name'] . ' at ' . $c['name'], $newId, ct_land(return_path('/clients/' . $c['client_id']), 'contact', 'client-contacts'), 'clientChanged', ['contact_id' => $newId, 'client_id' => $c['client_id']]);
