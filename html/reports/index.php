<?php
declare(strict_types=1);
/** /reports/ — the reports as cards (screen `reports`): each one the caller may open, with a line on what it answers; the aging (slice 6) is listed for those who see receivables. Nothing here reads a figure. reports.view or reports.view_own. */
require_once dirname(__DIR__, 2) . '/app/features/reports/handler.php';
require_any_right('reports.view|reports.view_own');
require_human();
$pdo = db();
$cards = reports_for($pdo, (int) current_member_id());
log_screen_view($pdo, 'reports');
if (wants_json()) {
    respond_screen(['reports' => $cards, 'may' => ['export' => has_right('exports.download')]]);
}
render_screen('Reports', view('reports/index.php', ['cards' => $cards, 'here' => here_url()]), ['activeNav' => 'reports', 'screen' => 'reports', 'entity' => 'report']);
