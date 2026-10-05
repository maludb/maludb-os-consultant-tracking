<?php
declare(strict_types=1);
/** /settings/roles/ — the Consulting roles (screen `role-list`; a table with inline add/edit; archive): settings.manage. Param include_archived. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/present.php';
require_right('settings.manage');
require_human();
$pdo = db();
$kind = 'role';
$withArchived = request_bool('include_archived');
$rows = find_vocabulary($pdo, $kind, $withArchived);
$editId = request_integer('edit');
$editing = $editId === null ? null : (find_vocabulary_row($pdo, $kind, $editId) ?? refuse(404, 'consulting role not found.'));
log_screen_view($pdo, 'role-list');
if (wants_json()) {
    respond_screen(['kind' => $kind, 'rows' => array_map(static fn (array $r): array => present_vocabulary($kind, $r), $rows), 'include_archived' => $withArchived]);
}
$notices = ['saved' => ['success', 'Saved the consulting role.'], 'archived' => ['success', 'The consulting role was archived.'], 'restored' => ['success', 'The consulting role is back.'], 'deleted' => ['success', 'The consulting role was deleted.']];
render_screen('Consulting roles', view('settings/vocabulary.php', ['kind' => $kind, 'rows' => $rows, 'editing' => $editing, 'withArchived' => $withArchived, 'title' => 'Consulting roles', 'singular' => 'consulting role', 'base' => '/settings/roles/', 'here' => here_url(),
    'notice' => ct_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'roles', 'screen' => 'role-list', 'entity' => 'role']);
