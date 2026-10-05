<?php
declare(strict_types=1);
/** /usage/budgets — every active engagement's four budgets as bars with the pace, and the unacknowledged alerts (screen `budget-alerts`; engagements.manage or reports.view). */
require_once dirname(__DIR__, 3) . '/app/features/engagements/handler.php';
require_any_right('engagements.manage|reports.view');
require_human();
$pdo = db();
$rows = find_active_budgets($pdo);
$alerts = find_budget_alerts($pdo, null, true);
$may = ['check' => has_right('engagements.manage') || has_right('billing.write'), 'acknowledge' => has_right('engagements.manage'), 'ai' => ai_amount_visible()];
log_screen_view($pdo, 'budget-alerts');
if (wants_json()) {
    respond_screen(['engagements' => array_map(static fn (array $e): array => present_engagement($e) + ['budget' => present_budget($e['budget'])], $rows), 'alerts' => array_map('present_alert', $alerts), 'may' => $may]);
}
$notices = ['checked' => ['info', 'The budgets were checked.'], 'acknowledged' => ['success', 'The alert is acknowledged.']];
render_screen('Budgets', view('usage/budgets.php', ['rows' => $rows, 'alerts' => $alerts, 'may' => $may, 'here' => here_url(), 'tz' => member_timezone(), 'notice' => ct_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'budgets', 'screen' => 'budget-alerts', 'entity' => 'engagement']);
