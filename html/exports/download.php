<?php
declare(strict_types=1);
/**
 * Action `export_download` (log `export.download`: the export, the format, from, to, the ROW COUNT — never a row): exports.download. GET (a download link) or POST (the kernel's actions server, which carries the CSRF the action token stands in for).
 * `export` ∈ invoices, credit_notes, expenses, reimbursements, wip, time, usage; `format` ∈ csv, json (csv by default); a period `from`/`to` (last month by default, at most 24 months) — reimbursements and WIP take only `to`, the "as of" day.
 * A browser receives the file; a JSON caller (an agent) receives {ok, export, format, rows, document | csv}. The same functions the six shares answer: one truth, two doors.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/queries.php';
require_login();
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($post) {
    verify_csrf();
}
require_right('exports.download');
$pdo = db();
$in = $post ? $_POST : $_GET;
$export = export_from_request((string) ($in['export'] ?? ''));
$format = (string) ($in['format'] ?? 'csv');
if (!isset(EXPORT_FORMATS[$format])) {
    refuse(422, 'The format is csv or json.');
}
$period = export_period($export, $in);
try {
    $doc = export_document($pdo, $export, $period['from'], $period['to'], ['engagement' => request_integer('engagement'), 'client' => request_integer('client'), 'member' => request_integer('member')]);
} catch (ShareRefused $e) {
    refuse(403, $e->getMessage());
} catch (DomainException $e) {
    refuse(422, $e->getMessage());
}
$rows = count($doc['rows']);
log_activity($pdo, 'export.download', 'export', null, ['screen' => 'exports', 'after' => ['export' => $export, 'format' => $format, 'from' => $period['from'], 'to' => $period['to'], 'rows' => $rows]]);
$name = export_filename($export, $period['from'], $period['to'], $format);
if (wants_json()) {
    emit_action_status(true, ['did' => 'Prepared ' . EXPORTS[$export]['title'] . ' (' . $rows . ' row' . ($rows === 1 ? '' : 's') . ')', 'export' => $export, 'format' => $format, 'rows' => $rows]);
    respond_saved(['did' => 'Prepared ' . EXPORTS[$export]['title'] . ' (' . $rows . ' row' . ($rows === 1 ? '' : 's') . ')', 'export' => $export, 'format' => $format, 'rows' => $rows, 'filename' => $name]
        + ($format === 'json' ? ['document' => $doc] : ['csv' => document_csv($doc)]));
}
header('Content-Type: ' . ($format === 'csv' ? 'text/csv; charset=utf-8' : 'application/json; charset=utf-8'));
header('Content-Disposition: attachment; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
echo $format === 'csv' ? document_csv($doc) : json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
