<?php
declare(strict_types=1);

/** The JSON shapes of payments and claims (whitelists over the views' rows). */
function present_payment(array $p): array
{
    return ['payment_id' => $p['payment_id'], 'invoice_id' => $p['invoice_id'], 'invoice_number' => $p['invoice_number'] ?? null, 'paid_on' => $p['paid_on'], 'amount' => $p['amount'], 'currency' => $p['currency'], 'method' => $p['method'], 'reference' => $p['reference'],
            'source' => $p['source'], 'status' => $p['status'], 'recorded_by' => $p['recorded_by'], 'void_reason' => $p['void_reason'], 'voided_at' => json_ts($p['voided_at'])];
}

function present_claim(array $k): array
{
    return ['claim_id' => $k['claim_id'], 'invoice_id' => $k['invoice_id'], 'invoice_number' => $k['invoice_number'] ?? null, 'claimed_on' => $k['claimed_on'], 'method' => $k['method'], 'reference' => $k['reference'], 'amount' => $k['amount'],
            'note' => $k['note'], 'status' => $k['status'], 'payment_id' => $k['payment_id'], 'submitted_at' => json_ts($k['created_at']), 'decided_at' => json_ts($k['decided_at'])];
}
