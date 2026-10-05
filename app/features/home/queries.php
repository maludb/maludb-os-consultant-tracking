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
    $myWeek = null;
    $timer = null;
    if (has_right('time.write')) {                                                   // slice 3: this week's hours, status and the timer
        require_once dirname(__DIR__) . '/time/queries.php';
        $ws = week_start_of($pdo, date('Y-m-d'));
        $t = find_timesheet($pdo, $memberId, $ws);
        $myWeek = ['week_start' => $ws, 'label' => week_label($ws), 'status' => $t['status'] ?? 'open', 'total_hours' => $t['total_hours'] ?? '0.00', 'billable_hours' => $t['billable_hours'] ?? '0.00', 'timesheet_id' => $t['timesheet_id'] ?? null,
                   'rejection_reason' => $t['rejection_reason'] ?? null, 'last_week' => find_timesheet($pdo, $memberId, (string) one_value($pdo, 'SELECT CAST(:w AS date) - 7', ['w' => $ws]))];
        $timer = my_timer($pdo, $memberId);
    }
    $myExpenses = null;
    if (has_right('expenses.write')) {                                               // slice 4: what awaits a receipt, what is owed
        require_once dirname(__DIR__) . '/expenses/queries.php';
        $myExpenses = my_expenses_summary($pdo, $memberId);
    }
    $approvals = null;
    if (has_right('time.approve') || has_right('expenses.approve') || has_right('usage.approve')) {   // slice 3 fills the weeks; 4 and 5 add theirs
        require_once dirname(__DIR__) . '/time/queries.php';
        require_once dirname(__DIR__) . '/expenses/queries.php';
        require_once dirname(__DIR__) . '/usage/queries.php';
        $approvals = ['weeks' => has_right('time.approve') ? array_slice(timesheets_pending($pdo, $memberId), 0, 8) : [], 'expenses' => has_right('expenses.approve') ? array_slice(expenses_pending($pdo, $memberId), 0, 8) : null,
                      'usage' => has_right('usage.approve') ? usage_pending($pdo, 8) : null];
    }
    return [
        'my_week' => $myWeek, 'timer' => $timer, 'my_expenses' => $myExpenses, 'approvals' => $approvals, 'budget_alerts' => $alerts, 'billing' => null, 'clerk_note' => null,
        'may' => [
            'time' => has_right('time.write'), 'expenses' => has_right('expenses.write'),
            'approve' => has_right('time.approve') || has_right('expenses.approve') || has_right('usage.approve'),
            'manage' => has_right('engagements.manage'), 'billing' => has_right('invoices.draft') || has_right('billing.write') || has_right('reports.view'), 'reports' => has_right('reports.view'),
        ],
    ];
}
