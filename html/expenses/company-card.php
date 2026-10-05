<?php
declare(strict_types=1);
/** /expenses/company-card?from=&to= — approved company-card spend by category and month (screen `company-card`): the shape the ledger reads (no person named). billing.write or reports.view; this year by default. */
require_once dirname(__DIR__, 2) . '/app/features/expenses/handler.php';
require_any_right('billing.write|reports.view');
require_human();
$pdo = db();
$from = request_date('from');
$to = request_date('to');
if ($from === false || $to === false) {
    refuse(422, 'The period is two dates (2026-01-01).');
}
$from ??= date('Y-01-01');
$to ??= date('Y-m-d');
$rows = company_card_spend($pdo, $from, $to);
log_screen_view($pdo, 'company-card');
if (wants_json()) {
    respond_screen(['from' => $from, 'to' => $to, 'rows' => array_map('present_company_card_row', $rows)]);
}
render_screen('Company card', view('expenses/company-card.php', ['rows' => $rows, 'from' => $from, 'to' => $to, 'here' => here_url()]), ['activeNav' => 'company-card', 'screen' => 'company-card', 'entity' => 'expense']);
