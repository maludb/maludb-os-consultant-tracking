<?php
declare(strict_types=1);

/** The JSON shape of a credit note (a whitelist over the view's rows). */
function present_credit_note(array $c, bool $full = false): array
{
    $out = ['credit_note_id' => $c['credit_note_id'], 'number' => $c['number'], 'status' => $c['status'], 'client' => ['client_id' => $c['client_id'], 'name' => $c['client_name']], 'invoice_id' => $c['invoice_id'], 'invoice_number' => $c['invoice_number'],
            'issue_date' => $c['issue_date'], 'currency' => $c['currency'], 'subtotal' => $c['subtotal'], 'tax_total' => $c['tax_total'], 'total' => $c['total'], 'amount_applied' => $c['amount_applied'], 'balance' => $c['balance'], 'reason' => $c['reason'],
            'issued_at' => json_ts($c['issued_at']), 'voided_at' => json_ts($c['voided_at']), 'void_reason' => $c['void_reason']];
    if (!$full) {
        return $out;
    }
    return $out + ['lines' => array_map(static fn (array $l): array => ['line_id' => $l['line_id'], 'sort_order' => $l['sort_order'], 'kind' => $l['kind'], 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'],
        'tax_rate' => $l['tax_rate'], 'line_subtotal' => $l['line_subtotal'], 'line_tax' => $l['line_tax'], 'line_total' => $l['line_total']], $c['lines']),
        'applications' => array_map(static fn (array $a): array => ['invoice_id' => $a['invoice_id'], 'invoice_number' => $a['invoice_number'], 'amount' => $a['amount']], $c['applications'])];
}
