<?php
declare(strict_types=1);
/** /reports/ar-aging?as_of= — who owes us (screen `ar-aging`): `ct_ar_aging()` per client in the five buckets (current, 1–30, 31–60, 61–90, 90+) and a CSV (`?format=csv`). billing.write or reports.view; the function answers nothing to anyone else. The other reports are slice 7's. */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
require_right('receivables.view');
require_human();
$pdo = db();
$asOf = request_date('as_of') ?: null;
$rows = ar_aging($pdo, $asOf);
$totals = [];
foreach ($rows as $a) {
    $t = &$totals[$a['currency']];
    $t ??= ['current_due' => '0.00', 'd1_30' => '0.00', 'd31_60' => '0.00', 'd61_90' => '0.00', 'd90_plus' => '0.00', 'total' => '0.00', 'open_invoices' => 0];
    foreach (['current_due', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'total'] as $k) { $t[$k] = money_add($t[$k], (string) $a[$k]); }
    $t['open_invoices'] += $a['open_invoices'];
    unset($t);
}
if (request_string('format') === 'csv') {
    log_activity($pdo, 'export.download', 'report', null, ['after' => ['report' => 'ar-aging', 'as_of' => $asOf ?? date('Y-m-d'), 'rows' => count($rows)]]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ar-aging-' . ($asOf ?? date('Y-m-d')) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Client', 'Currency', 'Current', '1-30', '31-60', '61-90', '90+', 'Total', 'Open invoices'], ',', '"', '');
    foreach ($rows as $a) {
        fputcsv($out, [$a['client_name'], $a['currency'], $a['current_due'], $a['d1_30'], $a['d31_60'], $a['d61_90'], $a['d90_plus'], $a['total'], $a['open_invoices']], ',', '"', '');
    }
    fclose($out);
    exit;
}
log_screen_view($pdo, 'ar-aging');
if (wants_json()) {
    respond_screen(['as_of' => $asOf ?? date('Y-m-d'), 'rows' => array_map('present_aging', $rows), 'totals' => $totals]);
}
render_screen('Who owes us', view('reports/ar-aging.php', ['rows' => $rows, 'totals' => $totals, 'asOf' => $asOf, 'here' => here_url()]), ['activeNav' => 'ar-aging', 'screen' => 'ar-aging', 'entity' => 'invoice']);
