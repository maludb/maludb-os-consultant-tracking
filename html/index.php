<?php
declare(strict_types=1);
/** / — home (screen `dashboard`): my week and timer, my expenses; a manager's approvals and budget alerts; billing's figures and the Clerk's note. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/home/queries.php';
require_once dirname(__DIR__) . '/app/features/engagements/queries.php';
require_once dirname(__DIR__) . '/app/features/engagements/present.php';
require_once dirname(__DIR__) . '/app/features/time/queries.php';
require_once dirname(__DIR__) . '/app/features/time/present.php';
require_once dirname(__DIR__) . '/app/features/expenses/present.php';
require_once dirname(__DIR__) . '/app/features/invoices/present.php';
require_once dirname(__DIR__) . '/app/features/usage/present.php';
require_once dirname(__DIR__) . '/app/features/settings/present.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
log_screen_view($pdo, 'dashboard');
$s = home_summary($pdo, $me);
if (wants_json()) {
    respond_screen(['may' => $s['may'], 'my_week' => $s['my_week'] === null ? null : ['week_start' => $s['my_week']['week_start'], 'label' => $s['my_week']['label'], 'status' => $s['my_week']['status'], 'total_hours' => $s['my_week']['total_hours'], 'billable_hours' => $s['my_week']['billable_hours'], 'timesheet_id' => $s['my_week']['timesheet_id'], 'rejection_reason' => $s['my_week']['rejection_reason'], 'last_week_status' => $s['my_week']['last_week']['status'] ?? null],
        'timer' => present_timer($s['timer']), 'my_expenses' => $s['my_expenses'] === null ? null : ['counts' => $s['my_expenses']['counts'], 'needs_receipt' => $s['my_expenses']['needs_receipt'], 'owed' => $s['my_expenses']['owed'], 'owed_count' => $s['my_expenses']['owed_count'], 'latest' => array_map('present_expense', $s['my_expenses']['latest'])], 'approvals' => $s['approvals'] === null ? null : ['weeks' => array_map('present_pending_week', $s['approvals']['weeks']), 'expenses' => $s['approvals']['expenses'] === null ? null : array_map('present_expense', $s['approvals']['expenses']), 'usage' => $s['approvals']['usage'] === null ? null : array_map('present_usage_charge', $s['approvals']['usage']), 'counts' => $s['approvals']['counts']],
        'budget_alerts' => $s['budget_alerts'] === null ? null : ['open' => array_map('present_alert', $s['budget_alerts']['open']), 'ending' => array_map('present_engagement', $s['budget_alerts']['ending']), 'active' => $s['budget_alerts']['active']], 'billing' => $s['billing'] === null ? null : present_billing_summary($s['billing']), 'clerk_note' => present_clerk_note($s['clerk_note']), 'bell' => array_map('present_notification', $s['bell'])]);
}
render_screen('Home', view('home/dashboard.php', ['s' => $s, 'tz' => member_timezone()]), ['activeNav' => 'home', 'screen' => 'dashboard']);
