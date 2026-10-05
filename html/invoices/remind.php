<?php
declare(strict_types=1);
/**
 * Action `invoice_remind` (log `invoice.remind`: number, amount — the balance —, currency, recipients, reminder_count; confirm; `external_send` for agents): billing.write; an open invoice that is past due. A courtesy mail is queued to the same
 * recipients with a fresh secure link; `last_reminder_at` and `reminder_count` are stamped. The worker (slice 8) sends.
 */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$inv = invoice_from_request($pdo);
if (!in_array($inv['status'], OPEN_INVOICE_STATUSES, true)) {
    refuse(422, 'Invoice ' . ($inv['number'] ?? '') . ' is ' . $inv['status'] . '; only an open invoice is reminded.');
}
if (!$inv['overdue']) {
    refuse(422, 'Invoice ' . $inv['number'] . ' is not past due (due ' . format_date($inv['due_date']) . ').');
}
$to = req_has('to_email') ? (string) req_val('to_email') : null;
$message = req_has('message') ? (string) req_val('message') : null;
if ($message !== null && mb_strlen($message) > 2000) {
    ct_refuse_fields(['message' => 'The message is at most 2,000 characters.']);
}
$r = ct_guard($pdo, static function () use ($pdo, $me, $inv, $to, $message): array {
    $pdo->beginTransaction();
    $r = send_invoice($pdo, $inv['invoice_id'], $me, $to, $message, 'reminder');
    invoice_log($pdo, 'invoice.remind', $inv, ['after' => ['number' => $inv['number'], 'amount' => $inv['balance_due'], 'currency' => $inv['currency'], 'recipients' => $r['recipients'], 'queued' => $r['queued'], 'reminder_count' => (int) $r['invoice']['reminder_count'],
        'days_past_due' => $inv['days_past_due']]]);
    $pdo->commit();
    return $r;
});
ct_done('Reminded about ' . invoice_label($inv), $inv['invoice_id'], ct_land(return_path('/invoices/' . $inv['invoice_id']), 'reminded'), 'invoiceChanged',
    ['invoice_id' => $inv['invoice_id'], 'recipients' => $r['recipients'], 'queued' => $r['queued'], 'reminder_count' => (int) $r['invoice']['reminder_count']]);
