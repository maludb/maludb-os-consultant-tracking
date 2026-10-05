<?php
declare(strict_types=1);
/**
 * /reports/csv?report=&…the report's filters — a report's CSV (GET, a download): the same rows the page shows (report_csv() over the same query function), behind exports.download AND the report's own right.
 * It logs `export.download` with the report, the filters and the row count — never the rows. A cost the function did not answer the caller is not in the file.
 */
require_once dirname(__DIR__, 2) . '/app/features/reports/handler.php';
require_login();
$key = report_from_request();
require_right('exports.download');
require_report($key);
$pdo = db();
$f = report_filters($key, $_GET);
$report = report_run($pdo, $key, $f);
$count = report_row_count($report);
log_activity($pdo, 'export.download', 'report', null, ['screen' => 'report', 'after' => ['report' => $key, 'rows' => $count] + report_log_filters($f)]);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . report_filename($key, $f) . '"');
header('X-Content-Type-Options: nosniff');
echo report_csv($report);
exit;
