<?php
declare(strict_types=1);
/**
 * POST /i/{token}/claim — "I paid this on … by …" (NOT an action; no agent reaches it). No session — the token is the authority — but the POST is CSRF-protected exactly like a login form's (the anonymous session's token), rate-limited
 * (five a link an hour) and logged `payment_claim.submit` with `actor_client_id` and `source = portal`; the client's note is cut at 200 characters. Never money until Billing confirms; billing is told (`claim_submitted`).
 */
require_once dirname(__DIR__, 2) . '/app/features/public/secure.php';
$pdo = db();
$token = door_token();
if ($token === '' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    door_refuse();
}
verify_csrf();
$errors = [];
$date = request_date('claimed_on');
if ($date === null || $date === false || $date > date('Y-m-d')) {
    $errors[] = 'Tell us the day you paid (not in the future).';
}
$method = (string) ($_POST['method'] ?? '');
if (!isset(PAYMENT_METHODS[$method])) {
    $errors[] = 'Choose how you paid.';
}
$amount = null;
if (trim((string) ($_POST['amount'] ?? '')) !== '') {
    $amount = parse_money((string) $_POST['amount']);
    if ($amount === null || (float) $amount <= 0) {
        $errors[] = 'The amount is a number with at most two decimals, or leave it empty.';
    }
}
$ref = trim((string) ($_POST['reference'] ?? ''));
$note = trim((string) ($_POST['note'] ?? ''));
if (mb_strlen($ref) > 120) {
    $errors[] = 'The reference is at most 120 characters.';
}
$fields = ['claimed_on' => $date ?: date('Y-m-d'), 'method' => $method ?: 'transfer', 'reference' => $ref === '' ? null : $ref, 'amount' => $amount, 'note' => $note === '' ? null : mb_substr($note, 0, 200)];
$show = static function (string $message) use ($pdo, $token, $fields): never {
    $s = secure_invoice($pdo, $token) ?? door_refuse();
    http_response_code(422);
    echo view('public/invoice.php', ['d' => $s['document'], 'token' => $token, 'thanks' => false, 'claimError' => $message, 'claimForm' => ['claimed_on' => $_POST['claimed_on'] ?? '', 'method' => $_POST['method'] ?? '', 'reference' => $_POST['reference'] ?? '', 'amount' => $_POST['amount'] ?? '', 'note' => $_POST['note'] ?? '']]);
    exit;
};
if ($errors !== []) {
    $show($errors[0]);
}
try {
    $pdo->beginTransaction();
    [$claimId, $invoiceId] = submit_claim($pdo, $token, $fields, client_ip());
    $inv = $pdo->prepare('SELECT number, currency FROM invoices WHERE id = :i');
    $inv->execute(['i' => $invoiceId]);
    $i = $inv->fetch();
    door_log($pdo, 'payment_claim.submit', $invoiceId, ['claim_id' => $claimId, 'invoice_number' => $i['number'], 'amount' => $fields['amount'], 'currency' => trim((string) $i['currency']), 'method' => $fields['method'], 'reference' => $fields['reference']], 'payment_claim', $claimId);
    notify_billing($pdo, 'claim_submitted', 'payment_claim', $claimId, ['title' => 'A client says they paid ' . $i['number'], 'body' => 'Confirm or dismiss it: ' . rtrim((string) env('APP_URL', ''), '/') . '/billing/claims', 'dedupe' => 'claim:' . $claimId]);
    $pdo->commit();
} catch (DomainException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e->getMessage() === 'gone') {
        door_refuse();
    }
    if ($e->getMessage() === 'rate') {
        http_response_code(429);
        header('Retry-After: 3600');
        echo view('shared/message.php', ['title' => 'Slow down', 'message' => 'We have your notes of payment already. Please try again in an hour, or contact the firm.']);
        exit;
    }
    $show($e->getMessage());
}
header('Location: /i/' . $token . '?thanks=1', true, 303);
exit;
