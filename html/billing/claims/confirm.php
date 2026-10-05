<?php
declare(strict_types=1);
/**
 * Action `payment_claim_confirm` (log `payment_claim.confirm`: invoice_number, amount, currency, payment_id; confirm; `money_out` for agents): billing.write. A client's claim becomes a payment (source claim) for `amount` — else the claim's,
 * else the balance. The database derives partially paid / paid and refuses an overpayment in words.
 */
require_once dirname(__DIR__, 3) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$k = find_claim($pdo, claim_id_from_request() ?? refuse(422, 'Say which claim.')) ?? refuse(404, 'Claim not found.');
$inv = find_invoice($pdo, $k['invoice_id']) ?? refuse(404, 'Invoice not found.');
$errors = [];
$amount = req_money('amount', 'The amount', $errors, false, true);
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$out = ct_guard($pdo, static function () use ($pdo, $me, $k, $inv, $amount): array {
    $pdo->beginTransaction();
    $p = confirm_claim($pdo, $k['claim_id'], $me, $amount);
    $after = find_invoice($pdo, $inv['invoice_id']);
    invoice_log($pdo, 'payment_claim.confirm', $after, ['before' => ['status' => 'submitted'], 'after' => ['status' => 'confirmed', 'claim_id' => $k['claim_id'], 'invoice_number' => $inv['number'], 'amount' => $p['amount'], 'currency' => trim((string) $p['currency']),
        'payment_id' => (int) $p['id'], 'method' => $p['method'], 'reference' => $p['reference'], 'invoice_status' => $after['status']]], 'payment_claim', $k['claim_id']);
    if ($after['status'] === 'paid' && $inv['status'] !== 'paid') {
        notify_paid($pdo, $after, $me);
    }
    $pdo->commit();
    return [$p, $after];
});
[$p, $after] = $out;
ct_done('Confirmed ' . fmt_money($p['amount'], trim((string) $p['currency'])) . ' on ' . $inv['number'] . ($after['status'] === 'paid' ? '; it is paid' : ''), $k['claim_id'], ct_land(return_path('/billing/claims'), 'confirmed'), 'invoiceChanged',
    ['claim_id' => $k['claim_id'], 'invoice_id' => $inv['invoice_id'], 'payment_id' => (int) $p['id'], 'status' => $after['status'], 'amount' => $p['amount'], 'balance_due' => $after['balance_due']]);
