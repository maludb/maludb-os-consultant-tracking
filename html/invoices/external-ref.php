<?php
declare(strict_types=1);
/** Action `invoice_external_ref_set` (log `invoice.external_ref`: before and after): billing.write. What the ledger calls the invoice, set by hand (slice 8's read sets it too); allowed on any status but a draft. Empty clears it. */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('billing.write');
$pdo = db();
$me = (int) current_member_id();
$inv = invoice_from_request($pdo);
if ($inv['status'] === 'draft') {
    refuse(422, 'A draft has no number the ledger could know; issue it first.');
}
$ref = trim((string) req_val('external_ref'));
if (mb_strlen($ref) > 200) {
    ct_refuse_fields(['external_ref' => 'The reference is at most 200 characters.']);
}
[$before, $after] = ct_guard($pdo, static function () use ($pdo, $me, $inv, $ref): array {
    $pdo->beginTransaction();
    $r = set_external_ref($pdo, $inv['invoice_id'], $ref === '' ? null : $ref, $me);
    invoice_log($pdo, 'invoice.external_ref', $inv, ['before' => ['external_ref' => $r[0]], 'after' => ['external_ref' => $r[1], 'number' => $inv['number']]]);
    $pdo->commit();
    return $r;
});
ct_done('Noted the ledger\'s reference', $inv['invoice_id'], ct_land(return_path('/invoices/' . $inv['invoice_id']), 'ref'), 'invoiceChanged', ['invoice_id' => $inv['invoice_id'], 'external_ref' => $after]);
