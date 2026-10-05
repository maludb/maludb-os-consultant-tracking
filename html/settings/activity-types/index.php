<?php
declare(strict_types=1);
/** /settings/activity-types/ — the Activity types (screen `activity-type-list`; a table with inline add/edit; archive): settings.manage. Param include_archived. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/present.php';
require_right('settings.manage');
require_human();
$pdo = db();
$kind = 'activity';
$withArchived = request_bool('include_archived');
$rows = find_vocabulary($pdo, $kind, $withArchived);
$editId = request_integer('edit');
$editing = $editId === null ? null : (find_vocabulary_row($pdo, $kind, $editId) ?? refuse(404, 'activity type not found.'));
log_screen_view($pdo, 'activity-type-list');
if (wants_json()) {
    respond_screen(['kind' => $kind, 'rows' => array_map(static fn (array $r): array => present_vocabulary($kind, $r), $rows), 'include_archived' => $withArchived]);
}
$notices = ['saved' => ['success', 'Saved the activity type.'], 'archived' => ['success', 'The activity type was archived.'], 'restored' => ['success', 'The activity type is back.'], 'deleted' => ['success', 'The activity type was deleted.']];
render_screen('Activity types', view('settings/vocabulary.php', ['kind' => $kind, 'rows' => $rows, 'editing' => $editing, 'withArchived' => $withArchived, 'title' => 'Activity types', 'singular' => 'activity type', 'base' => '/settings/activity-types/', 'here' => here_url(),
    'notice' => ct_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'activity-types', 'screen' => 'activity-type-list', 'entity' => 'activity']);
