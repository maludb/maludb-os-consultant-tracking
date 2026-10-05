<?php
declare(strict_types=1);
/**
 * Action `credit_note_draft` (by `client` or `invoice`; log `credit_note.draft`: invoice_id, currency, reason; a draft is changed with `credit_note`): billing.write. A credit corrects an invoice — the database copies the invoice's client,
 * engagement and currency — or stands for a client in its currency. It never refunds: nothing here pays anyone (D14).
 */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$cnId = request_integer('credit_note') ?? request_integer('credit_note_id');
$cur = $cnId === null ? null : (find_credit_note($pdo, $cnId) ?? refuse(404, 'Credit note not found.'));
$errors = [];
$invoice = null;
$clientId = null;
if ($cur === null) {
    $invId = request_integer('invoice') ?? request_integer('invoice_id');
    if ($invId !== null) {
        $invoice = find_invoice($pdo, $invId) ?? refuse(404, 'Invoice not found.');
        if (!in_array($invoice['status'], OPEN_INVOICE_STATUSES, true) && $invoice['status'] !== 'paid') {
            $errors['invoice'] = 'Invoice ' . ($invoice['number'] ?? '') . ' is ' . $invoice['status'] . '; a credit corrects an issued invoice.';
        }
        $clientId = $invoice['client_id'];
    } else {
        $clientId = ct_ref($pdo, req_has('client') ? 'client' : 'client_id', null, 'SELECT 1 FROM clients WHERE id = :id', 'client', $errors, false);
        if ($clientId === null && !isset($errors['client']) && !isset($errors['client_id'])) {
            $errors['client'] = 'Choose the client, or the invoice it corrects.';
        }
    }
} elseif ($cur['status'] !== 'draft') {
    refuse(422, 'Credit note ' . $cur['number'] . ' is ' . $cur['status'] . ' and cannot change.');
}
$reason = req_has('reason') ? (string) req_val('reason') : null;
if ($reason !== null && mb_strlen($reason) > 1000) {
    $errors['reason'] = 'The reason is at most 1,000 characters.';
}
$date = req_date('issue_date', 'The issue date', $errors);
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$id = ct_guard($pdo, static function () use ($pdo, $me, $cur, $invoice, $clientId, $reason, $date): int {
    $pdo->beginTransaction();
    $id = save_credit_note($pdo, $cur['credit_note_id'] ?? null, ['client_id' => $clientId, 'invoice_id' => $invoice['invoice_id'] ?? null, 'reason' => $reason === '' ? null : $reason, 'issue_date' => $date], $me);
    $c = find_credit_note($pdo, $id);
    credit_log($pdo, $cur === null ? 'credit_note.draft' : 'credit_note.draft', $c, ['after' => credit_money($c) + ['created' => $cur === null]]);
    $pdo->commit();
    return $id;
});
ct_done(($cur === null ? 'Drafted' : 'Saved') . ' a credit note', $id, ct_land(return_path('/credit-notes/' . $id . '/edit'), $cur === null ? 'drafted' : 'saved'), 'creditNoteChanged', ['credit_note_id' => $id, 'status' => 'draft']);
