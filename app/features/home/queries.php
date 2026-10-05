<?php
declare(strict_types=1);
/**
 * The dashboard's regions (screen `dashboard`), as the design §9 names them. Phase 2 fixes the SHAPE; each slice fills its key from its own
 * query functions: my_week + timer (3), my_expenses (4), approvals (3–5), budget_alerts (2), billing (5–7), clerk_note (8). A key is null until
 * its slice ships; the view then shows the empty state naming that slice.
 */
function home_summary(PDO $pdo, int $memberId): array
{
    return [
        'my_week' => null, 'timer' => null, 'my_expenses' => null, 'approvals' => null, 'budget_alerts' => null, 'billing' => null, 'clerk_note' => null,
        'may' => [
            'time' => has_right('time.write'), 'expenses' => has_right('expenses.write'),
            'approve' => has_right('time.approve') || has_right('expenses.approve') || has_right('usage.approve'),
            'manage' => has_right('engagements.manage'), 'billing' => has_right('invoices.draft') || has_right('billing.write') || has_right('reports.view'), 'reports' => has_right('reports.view'),
        ],
    ];
}
