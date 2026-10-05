<?php
declare(strict_types=1);

/** The JSON shapes: an engagement (a whitelist; the view already blanked the card for those without rates.view), its staffing, rates, milestones, budget and alerts. */
function present_engagement(array $e, bool $full = false): array
{
    $out = ['engagement_id' => $e['engagement_id'], 'number' => $e['number'], 'name' => $e['name'], 'client' => ['client_id' => $e['client_id'], 'name' => $e['client_name']], 'kind' => $e['kind'], 'status' => $e['status'],
            'start_date' => $e['start_date'], 'end_date' => $e['end_date'], 'currency' => $e['currency'], 'po_number' => $e['po_number'], 'rate_card_id' => $e['rate_card_id'],
            'nte_amount' => $e['nte_amount'], 'budget_hours' => $e['budget_hours'], 'budget_amount' => $e['budget_amount'], 'ai_budget_amount' => ai_amount_visible() ? $e['ai_budget_amount'] : ($e['ai_budget_amount'] === null ? null : 'set'),
            'expense_policy' => $e['expense_policy'], 'billing_cadence' => $e['billing_cadence'], 'next_billing_date' => $e['next_billing_date'], 'time_grouping' => $e['time_grouping'],
            'share_receipts_with_client' => $e['share_receipts_with_client'], 'department' => $e['department_id'] === null ? null : ['department_id' => $e['department_id'], 'name' => $e['department_name']],
            'manager' => $e['manager_member_id'] === null ? null : ['member_id' => $e['manager_member_id'], 'display_name' => $e['manager_name']], 'projects_ref' => $e['projects_ref'], 'description' => $e['description'],
            'i_manage' => $e['i_manage'], 'i_am_staffed' => $e['i_am_staffed'], 'staffed_count' => $e['staffed_count'], 'open_milestones' => $e['open_milestones'], 'open_alerts' => $e['open_alerts'],
            'activated_at' => json_ts($e['activated_at']), 'closed_at' => json_ts($e['closed_at']), 'created_at' => json_ts($e['created_at']), 'updated_at' => json_ts($e['updated_at'])];
    if ($full) {
        $out['budget'] = present_budget($e['budget'] ?? null);
        $out['staffing'] = array_map('present_staffing', $e['staffing'] ?? []);
        $out['role_rates'] = $e['role_rates'] ?? null;
        $out['milestones'] = array_map('present_milestone', $e['milestones'] ?? []);
        $out['alerts'] = array_map('present_alert', $e['alerts'] ?? []);
    }
    return $out;
}

/** The AI budget's amount is a cost: shown under cost.view or usage.write, else only whether it is set. */
function ai_amount_visible(): bool
{
    return has_right('cost.view') || has_right('usage.write');
}

function present_budget(?array $b): ?array
{
    if ($b === null) {
        return null;
    }
    if (!ai_amount_visible()) {
        $b['ai_budget_amount'] = $b['ai_budget_amount'] === null ? null : 'set';
        $b['ai_cost'] = null;
    }
    return $b;
}

function present_staffing(array $s): array
{
    return ['staffing_id' => $s['staffing_id'], 'member' => ['member_id' => $s['member_id'], 'display_name' => $s['member_name'], 'member_kind' => $s['member_kind']], 'consulting_role_id' => $s['consulting_role_id'], 'role_title' => $s['role_title'],
            'bill_rate_override' => $s['bill_rate_override'], 'valid_from' => $s['valid_from'], 'valid_to' => $s['valid_to'], 'is_current' => $s['is_current'], 'note' => $s['note']];
}

function present_milestone(array $m): array
{
    return ['milestone_id' => $m['milestone_id'], 'engagement_id' => $m['engagement_id'], 'name' => $m['name'], 'description' => $m['description'], 'amount' => $m['amount'], 'due_date' => $m['due_date'], 'status' => $m['status'],
            'sort_order' => $m['sort_order'], 'delivered_at' => json_ts($m['delivered_at']), 'accepted_at' => json_ts($m['accepted_at']), 'accepted_by' => $m['accepted_by'] === null ? null : ['member_id' => $m['accepted_by'], 'display_name' => $m['accepted_by_name']],
            'acceptance_note' => $m['acceptance_note'], 'cancelled_at' => json_ts($m['cancelled_at']), 'cancel_reason' => $m['cancel_reason'], 'billed' => $m['billed']];
}

function present_alert(array $a): array
{
    return ['alert_id' => $a['alert_id'], 'engagement' => ['engagement_id' => $a['engagement_id'], 'number' => $a['engagement_number'], 'name' => $a['engagement_name'] ?? null, 'client_name' => $a['client_name'] ?? null],
            'kind' => $a['kind'], 'threshold_pct' => $a['threshold_pct'], 'value_at' => $a['kind'] === 'ai' && !ai_amount_visible() ? null : $a['value_at'], 'budget_at' => $a['kind'] === 'ai' && !ai_amount_visible() ? null : $a['budget_at'],
            'written_at' => json_ts($a['written_at']), 'acknowledged' => $a['acknowledged_at'] !== null, 'acknowledged_at' => json_ts($a['acknowledged_at'])];
}
