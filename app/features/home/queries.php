<?php
declare(strict_types=1);
/**
 * The dashboard's regions (screen `dashboard`), as the design §9 names them. Phase 2 fixes the SHAPE; each slice fills its key from its own
 * query functions: my_week + timer (3), my_expenses (4), approvals (3–5), budget_alerts (2), billing (5–7), clerk_note (8). A key is null until
 * its slice ships; the view then shows the empty state naming that slice.
 */
function home_summary(PDO $pdo, int $memberId): array
{
    $alerts = null;
    if (has_right('engagements.manage') || has_right('reports.view')) {           // slice 2: the open alerts and the engagements ending within 30 days, of what the caller may see
        require_once dirname(__DIR__) . '/engagements/queries.php';
        $alerts = ['open' => array_slice(find_budget_alerts($pdo, null, true), 0, 8), 'ending' => find_engagements($pdo, ['ending_within_days' => 30], 8),
                   'active' => count_engagements($pdo, ['status' => ['active']])];
    }
    return [
        'my_week' => null, 'timer' => null, 'my_expenses' => null, 'approvals' => null, 'budget_alerts' => $alerts, 'billing' => null, 'clerk_note' => null,
        'may' => [
            'time' => has_right('time.write'), 'expenses' => has_right('expenses.write'),
            'approve' => has_right('time.approve') || has_right('expenses.approve') || has_right('usage.approve'),
            'manage' => has_right('engagements.manage'), 'billing' => has_right('invoices.draft') || has_right('billing.write') || has_right('reports.view'), 'reports' => has_right('reports.view'),
        ],
    ];
}
