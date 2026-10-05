<?php
declare(strict_types=1);

/** The JSON shapes of invoicing (whitelists over the views' rows — never a cost, never the link's token, never a client's address beyond the party card). */
function present_invoice(array $i, bool $full = false): array
{
    $cur = $i['currency'];
    $out = ['invoice_id' => $i['invoice_id'], 'number' => $i['number'], 'status' => $i['status'], 'client' => ['client_id' => $i['client_id'], 'name' => $i['client_name']],
            'engagement' => ['engagement_id' => $i['engagement_id'], 'number' => $i['engagement_number'], 'name' => $i['engagement_name']], 'period_start' => $i['period_start'], 'period_end' => $i['period_end'],
            'issue_date' => $i['issue_date'], 'due_date' => $i['due_date'], 'currency' => $cur, 'subtotal' => $i['subtotal'], 'tax_total' => $i['tax_total'], 'total' => $i['total'], 'amount_paid' => $i['amount_paid'],
            'amount_credited' => $i['amount_credited'], 'balance_due' => $i['balance_due'], 'po_number' => $i['po_number'], 'external_ref' => $i['external_ref'], 'overdue' => $i['overdue'],
            'days_past_due' => $i['overdue'] ? $i['days_past_due'] : null, 'open_claims' => $i['open_claims'], 'issued_at' => json_ts($i['issued_at']), 'sent_at' => json_ts($i['sent_at']), 'viewed_at' => json_ts($i['viewed_at']),
            'paid_at' => json_ts($i['paid_at']), 'last_reminder_at' => json_ts($i['last_reminder_at']), 'reminder_count' => $i['reminder_count']];
    if (!$full) {
        return $out;
    }
    return $out + ['memo' => $i['memo'], 'terms_text' => $i['terms_text'], 'internal_note' => $i['internal_note'], 'nte_override_reason' => $i['nte_override_reason'], 'void_reason' => $i['void_reason'], 'voided_at' => json_ts($i['voided_at']),
        'write_off_reason' => $i['write_off_reason'], 'written_off_at' => json_ts($i['written_off_at']), 'lines' => array_map('present_invoice_line', $i['lines']), 'payments' => array_map('present_payment', $i['payments']),
        'credits' => array_map(static fn (array $c): array => ['credit_note_id' => $c['credit_note_id'], 'number' => $c['number'], 'amount' => $c['amount'], 'currency' => $c['currency']], $i['credits']),
        'claims' => array_map('present_claim', $i['claims']), 'link' => $i['link'] === null ? null : ['state' => $i['link']['state'], 'expires_at' => json_ts($i['link']['expires_at']), 'last_used_at' => json_ts($i['link']['last_used_at']), 'view_count' => $i['link']['view_count']],
        'engagement_kind' => $i['engagement_kind'], 'share_receipts' => $i['share_receipts']];
}

function present_invoice_line(array $l): array
{
    return ['line_id' => $l['line_id'], 'sort_order' => $l['sort_order'], 'kind' => $l['kind'], 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit' => $l['unit'], 'unit_price' => $l['unit_price'],
            'tax_rate_id' => $l['tax_rate_id'], 'tax_rate' => $l['tax_rate'], 'line_subtotal' => $l['line_subtotal'], 'line_tax' => $l['line_tax'], 'line_total' => $l['line_total'], 'group_key' => $l['group_key'],
            'item_count' => $l['item_count'], 'items' => array_map(static function (array $x): array {
                $kind = $x['time_entry_id'] !== null ? 'time' : ($x['expense_id'] !== null ? 'expense' : ($x['usage_charge_id'] !== null ? 'usage' : 'milestone'));
                return ['item_id' => $x['item_id'], 'kind' => $kind, 'amount' => $x['amount'], 'date' => $x['t_date'] ?? $x['e_date'] ?? $x['u_end'] ?? ($x['m_accepted'] === null ? null : substr((string) $x['m_accepted'], 0, 10)),
                        'person' => $x['t_member'], 'hours' => $x['t_hours'], 'note' => $x['t_note'], 'expense' => $x['expense_id'] === null ? null : ['expense_id' => $x['expense_id'], 'number' => $x['e_number'], 'description' => $x['e_description'], 'has_receipt' => $x['e_has_receipt']],
                        'usage' => $x['usage_charge_id'] === null ? null : ['usage_charge_id' => $x['usage_charge_id'], 'number' => $x['u_number'], 'provider' => $x['u_provider'], 'description' => $x['u_description']],
                        'milestone' => $x['milestone_id'] === null ? null : ['milestone_id' => $x['milestone_id'], 'name' => $x['m_name']]];
            }, $l['items'])];
}

/** An unbilled row (ct_unbilled): the engagement's WIP. */
function present_unbilled(array $u): array
{
    return ['engagement' => ['engagement_id' => $u['engagement_id'], 'number' => $u['engagement_number'], 'name' => $u['engagement_name']], 'client' => ['client_id' => $u['client_id'], 'name' => $u['client_name']], 'currency' => $u['currency'],
            'time_hours' => $u['time_hours'], 'time_amount' => $u['time_amount'], 'time_no_rate_hours' => $u['time_no_rate_hours'], 'expense_amount' => $u['expense_amount'], 'usage_amount' => $u['usage_amount'], 'milestone_amount' => $u['milestone_amount'],
            'total_amount' => $u['total_amount'], 'oldest_item' => $u['oldest_item']];
}

function present_unbilled_item(array $u): array
{
    return ['kind' => $u['kind'], 'item_id' => $u['item_id'], 'date' => $u['item_date'], 'person' => $u['member_name'], 'description' => $u['description'], 'quantity' => $u['quantity'], 'amount' => $u['amount'], 'no_rate' => $u['no_rate']];
}

function present_aging(array $a): array
{
    return ['client' => ['client_id' => $a['client_id'], 'name' => $a['client_name']], 'currency' => $a['currency'], 'current' => $a['current_due'], 'd1_30' => $a['d1_30'], 'd31_60' => $a['d31_60'], 'd61_90' => $a['d61_90'], 'd90_plus' => $a['d90_plus'],
            'total' => $a['total'], 'open_invoices' => $a['open_invoices']];
}

/** The home's Billing region as JSON: figures per currency, the drafts, what is due to draft. */
function present_billing_summary(array $b): array
{
    return ['unbilled' => $b['wip'], 'drafts' => array_map(static fn (array $i): array => present_invoice($i), $b['drafts']), 'draft_count' => $b['draft_count'], 'owed' => $b['owed'], 'overdue' => $b['overdue'], 'due_to_draft' => $b['due_to_draft'],
            'due' => array_map(static fn (array $e): array => ['engagement_id' => $e['engagement_id'], 'number' => $e['number'], 'name' => $e['name'], 'next_billing_date' => $e['next_billing_date'], 'unbilled' => $e['wip']['total_amount'], 'currency' => $e['currency']], $b['due']),
            'milestones_waiting' => $b['milestones_waiting'], 'claims_waiting' => $b['claims'], 'aging' => $b['aging']];
}
