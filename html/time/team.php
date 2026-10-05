<?php
declare(strict_types=1);
/** /time/team?week=&department= — everyone's hours per day for a week (screen `team-time`): people down, days across, missing weekdays marked. time.approve. */
require_once dirname(__DIR__, 2) . '/app/features/time/handler.php';
require_right('time.approve');
require_human();
$pdo = db();
$raw = trim((string) ($_GET['week'] ?? ''));
$ws = week_start_of($pdo, $raw === '' ? date('Y-m-d') : ((request_date('week') ?: null) ?? refuse(422, 'The week is a date.')));
$dept = request_integer('department');
$rows = team_time($pdo, $ws, $dept);
log_screen_view($pdo, 'team-time');
if (wants_json()) {
    respond_screen(['week_start' => $ws, 'label' => week_label($ws), 'department_id' => $dept, 'days' => week_days($ws), 'people' => $rows]);
}
render_screen('Team time', view('time/team.php', ['rows' => $rows, 'ws' => $ws, 'days' => week_days($ws), 'dept' => $dept, 'departments' => find_live_departments($pdo), 'here' => here_url()]),
    ['activeNav' => 'team-time', 'screen' => 'team-time', 'entity' => 'timesheet']);
