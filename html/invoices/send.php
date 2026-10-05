<?php
declare(strict_types=1);
/**
 * Action `invoice_send` (log `invoice.send`: number, amount, currency, recipients — a count, never an address —, queued; confirm; `external_send` for agents): billing.write; an issued invoice. Marks it sent through the verb and queues one mail per
 * recipient — the contacts who receive invoices, else the client's email, else `to_email` — with a FRESH secure link in the body (the raw token exists only at mint time; the previous link dies). The worker (slice 8) sends. Nothing talks to MaluMail here.
 */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$inv = invoice_from_request($pdo);
$to = req_has('to_email') ? (string) req_val('to_email') : null;
$message = req_has('message') ? (string) req_val('message') : null;
if ($message !== null && mb_strlen($message) > 2000) {
    ct_refuse_fields(['message' => 'The message is at most 2,000 characters.']);
}
$r = ct_guard($pdo, static function () use ($pdo, $me, $inv, $to, $message): array {
    $pdo->beginTransaction();
    $r = send_invoice($pdo, $inv['invoice_id'], $me, $to, $message, 'invoice');
    invoice_log($pdo, 'invoice.send', $inv, ['before' => ['status' => $inv['status']], 'after' => ['status' => $r['invoice']['status'], 'number' => $inv['number'], 'amount' => $inv['total'], 'currency' => $inv['currency'], 'recipients' => $r['recipients'], 'queued' => $r['queued']]]);
    $pdo->commit();
    return $r;
});
ct_done('Sent ' . invoice_label($inv) . ' to ' . $r['recipients'] . ' recipient' . ($r['recipients'] === 1 ? '' : 's'), $inv['invoice_id'], ct_land(return_path('/invoices/' . $inv['invoice_id']), 'sent'), 'invoiceChanged',
    ['invoice_id' => $inv['invoice_id'], 'status' => $r['invoice']['status'], 'recipients' => $r['recipients'], 'queued' => $r['queued']]);
