<?php
declare(strict_types=1);
/**
 * /reports/{report}?from=&to=&engagement=&client=&member=&department=&by= — one report (screen `report`): the filter row, the function's rows as a table with its totals, a CSV.
 * `report` ∈ utilization, realization, wip, revenue, margin, budget-burn, ai-passthrough, expenses, missing-time (the router puts it in ?report=; anything else is 404). Each report's right is its function's rule;
 * the page never shows a figure the function did not answer, and the cost columns come only when it returned them.
 */
require_once dirname(__DIR__, 2) . '/app/features/reports/handler.php';
$key = report_from_request();
require_report($key);
$pdo = db();
$def = report_defs()[$key];
$f = report_filters($key, $_GET);
$report = report_run($pdo, $key, $f);
$scope = $key === 'margin' ? report_scope_label($pdo) : null;
$extra = ($scope !== null ? ['scope' => $scope] : []) + ($key === 'missing-time' ? ['week_start' => $report['week_start']] : []) + ['may' => ['csv' => has_right('exports.download')]];
report_screen_view($pdo, $key, $f);
if (wants_json()) {
    respond_screen(present_report($key, $f, $report, $extra));
}
render_screen($def['title'], view('reports/report.php', ['key' => $key, 'def' => $def, 'f' => $f, 'report' => $report, 'scope' => $scope, 'pick' => report_picklists($pdo), 'here' => here_url(),
    'may' => $extra['may'], 'weekStart' => $report['week_start'] ?? null]), ['activeNav' => 'reports', 'screen' => 'report', 'entity' => 'report']);
