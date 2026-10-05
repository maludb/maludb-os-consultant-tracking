<?php
declare(strict_types=1);
/**
 * /exports — the downloads (screen `exports`): the seven documents the accounting interface knows — invoices issued, credit notes, expenses by category, reimbursements, WIP, time detail, usage detail — each as CSV or JSON for a period
 * (an "as of" date for reimbursements and WIP). The very functions the shares answer; nothing here computes a figure. exports.download (Billing, Finance, the admin).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/present.php';
require_login();
require_right('exports.download');
require_human();
$pdo = db();
$p = export_period('invoices', $_GET);
$asOf = export_period('wip', $_GET)['to'];
log_screen_view($pdo, 'exports');
if (wants_json()) {
    respond_screen(['period' => $p, 'as_of' => $asOf, 'exports' => present_exports()]);
}
render_screen('Exports', view('exports/index.php', ['p' => $p, 'asOf' => $asOf]), ['activeNav' => 'exports', 'screen' => 'exports', 'entity' => 'export']);
