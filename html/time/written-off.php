<?php
declare(strict_types=1);
/** /time/written-off?from=&to=&engagement= — the hours written off, with reasons (screen `written-off`). time.approve or reports.view. */
require_once dirname(__DIR__, 2) . '/app/features/time/handler.php';
require_any_right('time.approve|reports.view');
require_human();
$pdo = db();
$filters = ['from' => request_date('from') ?: null, 'to' => request_date('to') ?: null, 'engagement_id' => request_integer('engagement')];
$rows = written_off($pdo, $filters);
log_screen_view($pdo, 'written-off');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'entries' => array_map('present_time_entry', $rows), 'total_hours' => number_format(array_sum(array_map(fn ($e) => (float) $e['hours'], $rows)), 2, '.', '')]);
}
render_screen('Written off', view('time/written-off.php', ['rows' => $rows, 'filters' => $filters, 'here' => here_url(), 'tz' => member_timezone(), 'rates' => has_right('rates.view')]),
    ['activeNav' => 'time-approvals', 'screen' => 'written-off', 'entity' => 'time_entry']);
