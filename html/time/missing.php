<?php
declare(strict_types=1);
/** /time/missing?week= — who has not submitted a week and which weekdays are empty (screen `missing-time`): Nudge per person. time.approve or reports.view. */
require_once dirname(__DIR__, 2) . '/app/features/time/handler.php';
require_any_right('time.approve|reports.view');
require_human();
$pdo = db();
$raw = trim((string) ($_GET['week'] ?? ''));
$ws = $raw === '' ? (string) one_value($pdo, 'SELECT ct_week_start(current_date) - 7') : week_start_of($pdo, (request_date('week') ?: null) ?? refuse(422, 'The week is a date.'));
$rows = missing_time($pdo, $ws);
$nudged = array_map('intval', $pdo->query("SELECT member_id FROM notification_outbox WHERE kind = 'missing_time' AND dedupe_key LIKE 'nudge:%:" . $ws . ":%'")->fetchAll(PDO::FETCH_COLUMN));
foreach ($rows as &$r) {
    $r['nudged'] = in_array($r['member_id'], $nudged, true);
}
unset($r);
$may = ['nudge' => has_right('time.approve') || has_right('billing.write')];
log_screen_view($pdo, 'missing-time');
if (wants_json()) {
    respond_screen(['week_start' => $ws, 'label' => week_label($ws), 'people' => $rows, 'may' => $may]);
}
$notices = ['nudged' => ['success', 'The nudge is queued.'], 'already' => ['info', 'That person was already nudged this week.']];
render_screen('Missing time', view('time/missing.php', ['rows' => $rows, 'ws' => $ws, 'may' => $may, 'here' => here_url(), 'notice' => ct_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'missing-time', 'screen' => 'missing-time', 'entity' => 'timesheet']);
