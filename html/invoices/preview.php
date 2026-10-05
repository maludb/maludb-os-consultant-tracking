<?php
declare(strict_types=1);
/** /invoices/{id}/preview — the invoice as the client sees it on the link (screen `invoice-preview`): the SAME document partial as /i/{token}, read from the same base-table document after this person's own gate (the view). */
require_once dirname(__DIR__, 2) . '/app/features/invoices/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/public/queries.php';
require_any_right('invoices.draft|billing.write|reports.view');
require_human();
$pdo = db();
$i = find_invoice($pdo, invoice_id_from_request() ?? refuse(404, 'Invoice not found.')) ?? refuse(404, 'Invoice not found.');
$d = public_invoice_document($pdo, $i['invoice_id']) ?? refuse(404, 'Invoice not found.');
log_activity($pdo, 'screen.view', 'invoice', $i['invoice_id'], ['screen' => 'invoice-preview', 'invoice_id' => $i['invoice_id'], 'engagement_id' => $i['engagement_id'], 'client_id' => $i['client_id']]);
if (wants_json()) {
    respond_screen(['invoice_id' => $i['invoice_id'], 'number' => $i['number'], 'status' => $i['status'], 'document' => ['firm' => $d['firm'], 'invoice' => $d['invoice'], 'lines' => array_map(static fn (array $l): array => array_diff_key($l, ['items' => 1]) + ['items' => count($l['items'])], $d['lines']), 'payments' => $d['payments'], 'credits' => $d['credits']]]);
}
render_screen('Preview · ' . ($i['number'] ?? 'draft'), view('invoices/preview.php', ['i' => $i, 'd' => $d, 'here' => here_url()]), ['activeNav' => 'invoices', 'screen' => 'invoice-preview', 'entity' => 'invoice', 'recordId' => (string) $i['invoice_id']]);
