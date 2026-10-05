<?php
declare(strict_types=1);
/** /settings/connections — what sibling applications read of ours and what we read of theirs (screen `connections`; read-only, settings.manage). Approving a connection is the super-admin's, in the kernel. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/agents.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/present.php';
require_right('settings.manage');
require_human();
$pdo = db();
$c = connections_summary($pdo);
log_screen_view($pdo, 'connections');
if (wants_json()) {
    respond_screen(['shares' => array_map('present_share', $c['shares']), 'reads' => array_map('present_read', $c['reads']), 'os_url' => os_pages_url()]);
}
render_screen('Connections', view('settings/connections.php', ['c' => $c, 'os' => os_pages_url(), 'tz' => member_timezone()]), ['activeNav' => 'connections', 'screen' => 'connections', 'entity' => 'connection']);
