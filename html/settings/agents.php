<?php
declare(strict_types=1);
/** /settings/agents — the agents that work here and what they last did (screen `agents`; read-only, settings.manage). Hiring, grants and duties are the kernel's. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/agents.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/present.php';
require_right('settings.manage');
require_human();
$pdo = db();
$rows = agents_here($pdo);
log_screen_view($pdo, 'agents');
if (wants_json()) {
    respond_screen(['agents' => array_map('present_agent', $rows), 'os_url' => os_pages_url()]);
}
render_screen('Agents', view('settings/agents.php', ['rows' => $rows, 'os' => os_pages_url(), 'tz' => member_timezone()]), ['activeNav' => 'agents', 'screen' => 'agents', 'entity' => 'agent']);
