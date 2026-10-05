<?php
declare(strict_types=1);
/** /engagements/ — the engagements as cards with budget bars, by status (screen `engagement-list`; engagements.view: the view shows each person theirs; filters q, client, kind, status, department, manager, mine). */
require_once dirname(__DIR__, 2) . '/app/features/engagements/handler.php';
require_right('engagements.view');
require_human();
$pdo = db();
$statusParam = request_string('status');
$filters = ['q' => mb_substr(request_string('q'), 0, 80), 'client_id' => request_integer('client'), 'kind' => isset(ENGAGEMENT_KINDS[request_string('kind')]) ? request_string('kind') : '',
            'status' => $statusParam === '' ? ['draft', 'active', 'on_hold', 'closed'] : ($statusParam === 'all' ? ['all'] : array_values(array_intersect(explode(',', $statusParam), array_keys(ENGAGEMENT_STATUSES)))),
            'department_id' => request_integer('department'), 'manager' => request_integer('manager'), 'mine' => request_bool('mine')];
$rows = find_engagements($pdo, $filters, 200);
foreach ($rows as &$e) {
    $e['budget'] = engagement_budget($pdo, $e['engagement_id']);
}
unset($e);
$may = ['manage' => has_right('engagements.manage'), 'rates' => has_right('rates.view')];
log_screen_view($pdo, 'engagement-list');
if (wants_json()) {
    respond_screen(['filters' => $filters + ['status_param' => $statusParam], 'engagements' => array_map(static fn (array $e): array => present_engagement($e) + ['budget' => present_budget($e['budget'])], $rows), 'may' => $may]);
}
$notices = ['deleted' => ['success', 'The draft was deleted.'], 'cancelled' => ['success', 'The engagement was cancelled.']];
render_screen('Engagements', view('engagements/index.php', ['rows' => $rows, 'filters' => $filters, 'statusParam' => $statusParam, 'may' => $may, 'here' => here_url(),
    'clients' => $pdo->query('SELECT client_id, name FROM mcp_clients WHERE archived_at IS NULL ORDER BY lower(name)')->fetchAll(),   // the filter: the clients the caller sees 'departments' => find_live_departments($pdo),
    'notice' => ct_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'engagements', 'screen' => 'engagement-list', 'entity' => 'engagement']);
