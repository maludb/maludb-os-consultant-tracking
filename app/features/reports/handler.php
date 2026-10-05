<?php
declare(strict_types=1);

/**
 * The prelude of a report page (slice 7): the feature's files, and the report a request names — from the allow-list, else 404 —
 * behind its right in the right's own words. Nothing in a report writes.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';

/** The report key the request names (the router puts it in ?report=): in the allow-list, else 404. */
function report_from_request(): string
{
    $key = (string) ($_GET['report'] ?? '');
    return isset(report_defs()[$key]) ? $key : refuse(404, 'There is no such report.');
}

/** The gate of one report: its right, else 403 in that right's words. A margin page with nothing to cover says so. */
function require_report(string $key): void
{
    require_login();
    $right = report_defs()[$key]['right'];
    if (!nav_has_right($right)) {
        if ($key === 'margin') {
            require_right('cost.view');
            refuse(403, 'You manage no engagement, so there is no margin to show you.');
        }
        require_right(explode('|', $right)[0]);
    }
    require_human();
}
