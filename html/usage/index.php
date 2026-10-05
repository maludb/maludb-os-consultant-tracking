<?php
declare(strict_types=1);
/** /usage/?engagement=&from=&to=&kind=&source=&status=&import=&statement= — the charges (screen `usage-list`): rows on a desktop, cards on a phone, the totals per currency, the filters. usage.write; the view shows each person their engagements' charges and their cost. */
require_once dirname(__DIR__, 2) . '/app/features/usage/handler.php';
require_right('usage.write');
require_human();
$pdo = db();
$from = request_date('from') ?: null;
$to = request_date('to') ?: null;
$filters = ['engagement_id' => request_integer('engagement'), 'from' => $from, 'to' => $to, 'kind' => request_string('kind'), 'source' => request_string('source'), 'status' => request_string('status'),
            'import_id' => request_integer('import'), 'statement_id' => request_integer('statement'), 'q' => request_string('q')];
$rows = find_usage_charges($pdo, $filters, 300);
$totals = usage_totals($pdo, $filters);
$engagements = $pdo->query('SELECT engagement_id, number, name FROM mcp_engagements ORDER BY number')->fetchAll();
$import = $filters['import_id'] === null ? null : find_usage_import($pdo, $filters['import_id']);
log_screen_view($pdo, 'usage-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'charges' => array_map('present_usage_charge', $rows), 'totals' => array_map('present_usage_totals', $totals), 'import' => $import === null ? null : present_usage_import($import + ['provider_name' => $import['provider_name']])]);
}
$notices = ['saved' => ['success', 'Saved.'], 'deleted' => ['success', 'The charge was removed.'], 'imported' => ['success', 'Imported; the new charges are drafts for the engagement\'s manager to approve.'],
            'import_deleted' => ['success', 'The import\'s draft charges were removed.']];
render_screen('AI usage and hosting', view('usage/index.php', ['rows' => $rows, 'totals' => $totals, 'filters' => $filters, 'engagements' => $engagements, 'import' => $import, 'here' => here_url(), 'tz' => member_timezone(),
    'notice' => ct_notice($_GET['notice'] ?? null, $notices), 'may' => ['approve' => has_right('usage.approve')]]), ['activeNav' => 'usage', 'screen' => 'usage-list', 'entity' => 'usage_charge']);
