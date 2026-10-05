<?php
declare(strict_types=1);

/** The JSON shapes of expenses (whitelists over the view's rows — never a raw row). */
function present_expense(array $x): array
{
    return ['expense_id' => $x['expense_id'], 'number' => $x['number'], 'member' => ['member_id' => $x['member_id'], 'display_name' => $x['member_name']],
            'engagement' => ['engagement_id' => $x['engagement_id'], 'number' => $x['engagement_number']], 'expense_date' => $x['expense_date'],
            'category' => ['category_id' => $x['category_id'], 'label' => $x['category_label']], 'vendor_name' => $x['vendor_name'], 'description' => $x['description'],
            'amount' => $x['amount'], 'tax_amount' => $x['tax_amount'], 'currency' => $x['currency'], 'paid_with' => $x['paid_with'], 'reimbursable' => $x['reimbursable'],
            'billable' => $x['billable'], 'markup_pct' => $x['markup_pct'], 'billable_amount' => $x['billable_amount'], 'status' => $x['status'],
            'submitted_at' => json_ts($x['submitted_at']), 'approved_at' => json_ts($x['approved_at']), 'approved_by' => $x['approved_by'], 'rejected_at' => json_ts($x['rejected_at']),
            'rejected_by' => $x['rejected_by'], 'rejection_reason' => $x['rejection_reason'], 'reimbursed_at' => json_ts($x['reimbursed_at']), 'reimbursed_ref' => $x['reimbursed_ref'],
            'has_receipt' => $x['has_receipt'], 'receipt_threshold' => $x['receipt_threshold'], 'receipt_needed' => $x['receipt_needed'], 'billed' => $x['billed'],
            'receipt' => $x['receipt_id'] === null ? null : ['attachment_id' => $x['receipt_id'], 'mime_type' => $x['receipt_mime'], 'url' => '/attachments/' . $x['receipt_id']]];
}

function present_reimbursement_group(array $g): array
{
    return ['member' => ['member_id' => $g['member_id'], 'display_name' => $g['member_name']], 'totals' => $g['totals'], 'expenses' => array_map('present_expense', $g['expenses'])];
}

function present_company_card_row(array $r): array
{
    return ['month' => $r['month'], 'category' => ['category_id' => $r['category_id'], 'label' => $r['category']], 'currency' => $r['currency'], 'amount' => $r['amount'], 'tax_amount' => $r['tax_amount'], 'count' => $r['count']];
}
