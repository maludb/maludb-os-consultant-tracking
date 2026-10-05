<?php
declare(strict_types=1);
/**
 * Action `invoice_add_unbilled` (log `invoice.add_unbilled`: number, amount, currency, the counts): invoices.draft AND manage; a draft. Pulls the engagement's approved, billable, unbilled items through its grouping up to `through` (today)
 * into lines with their backing items; the hours with NO rate are skipped and named ("4.0 h on ENG-0042 have no rate — set one and re-approve"). A second call adds nothing: billed is one truth.
 */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
ct_handler_begin();
require_right('invoices.draft');
$pdo = db();
$me = (int) current_member_id();
$inv = invoice_from_request($pdo);
require_draft_on($inv);
$errors = [];
$through = req_date('through', 'The date to pull through', $errors);
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$counts = ct_guard($pdo, static function () use ($pdo, $me, $inv, $through): array {
    $pdo->beginTransaction();
    $counts = add_unbilled($pdo, $inv['invoice_id'], $me, $through);
    $after = find_invoice($pdo, $inv['invoice_id']);
    invoice_log($pdo, 'invoice.add_unbilled', $after, ['before' => ['total' => $inv['total']], 'after' => ['amount' => $after['total'], 'total' => $after['total'], 'currency' => $after['currency'], 'counts' => $counts]]);
    $pdo->commit();
    return $counts;
});
$warn = no_rate_sentence($counts, $inv['engagement_number']);
$n = $counts['time_entries_billed'] + $counts['expense_lines'] + $counts['usage_lines'] + $counts['milestone_lines'];
$said = $n === 0 && $counts['time_lines'] === 0 ? 'Nothing new to add: everything approved is already on an invoice.' : 'Added ' . $counts['time_lines'] . ' time line' . ($counts['time_lines'] === 1 ? '' : 's') . ' (' . $counts['time_entries_billed'] . ' entries), '
    . $counts['expense_lines'] . ' expense' . ($counts['expense_lines'] === 1 ? '' : 's') . ', ' . $counts['usage_lines'] . ' usage charge' . ($counts['usage_lines'] === 1 ? '' : 's') . ' and ' . $counts['milestone_lines'] . ' milestone' . ($counts['milestone_lines'] === 1 ? '' : 's') . '.';
ct_flash($warn === null ? 'success' : 'warning', $said . ($warn === null ? '' : ' ' . $warn));
ct_done($said, $inv['invoice_id'], ct_land(return_path('/invoices/' . $inv['invoice_id'] . '/edit')), 'invoiceChanged', ['invoice_id' => $inv['invoice_id'], 'counts' => $counts, 'warning' => $warn]);
