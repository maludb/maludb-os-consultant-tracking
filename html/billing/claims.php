<?php
declare(strict_types=1);
/** /billing/claims — what clients said on the secure link and Billing has not decided (screen `claims`): the invoice, the date, the method, the reference, the amount, the note; Confirm (an amount) or Dismiss. billing.write. A claim is never money until it is confirmed. */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
require_right('billing.write');
require_human();
$pdo = db();
$status = request_string('status', 'submitted');
if (!in_array($status, ['submitted', 'confirmed', 'dismissed', 'all'], true)) {
    $status = 'submitted';
}
$rows = find_claims($pdo, ['status' => $status]);
log_screen_view($pdo, 'claims');
if (wants_json()) {
    respond_screen(['status' => $status, 'claims' => array_map(static fn (array $k): array => present_claim($k) + ['client' => ['client_id' => $k['client_id'], 'name' => $k['client_name']], 'balance_due' => $k['balance_due'], 'currency' => $k['currency']], $rows)]);
}
$notices = ['confirmed' => ['success', 'Confirmed: the payment is recorded.'], 'dismissed' => ['info', 'The claim was dismissed.']];
render_screen('Payment claims', view('billing/claims.php', ['rows' => $rows, 'status' => $status, 'here' => here_url(), 'notice' => ct_page_notice($notices)]), ['activeNav' => 'claims', 'screen' => 'claims', 'entity' => 'invoice']);
