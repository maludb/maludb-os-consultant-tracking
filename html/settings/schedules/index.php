<?php
declare(strict_types=1);
/** /settings/schedules/?engagement= — the hosting schedules (screen `schedule-list`): engagement, description, amount per period, cadence, next date, last run, active; Run now when due. usage.write. */
require_once dirname(__DIR__, 3) . '/app/features/usage/handler.php';
require_right('usage.write');
require_human();
$pdo = db();
$engagement = request_integer('engagement');
$rows = find_schedules($pdo, ['engagement_id' => $engagement]);
log_screen_view($pdo, 'schedule-list');
if (wants_json()) {
    respond_screen(['schedules' => array_map('present_schedule', $rows)]);
}
$notices = ['saved' => ['success', 'The schedule was saved.'], 'deleted' => ['success', 'The schedule was removed.'], 'ran' => ['success', 'Ran: a draft charge was made for the period it covers.']];
render_screen('Hosting schedules', view('settings/schedules/index.php', ['rows' => $rows, 'engagement' => $engagement, 'here' => here_url(), 'notice' => ct_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'schedules', 'screen' => 'schedule-list', 'entity' => 'recurring_charge']);
