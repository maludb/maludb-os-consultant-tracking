<?php
declare(strict_types=1);
/** /settings/categories/ — the Expense categories (screen `category-list`; a table with inline add/edit; archive): settings.manage. Param include_archived. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/present.php';
require_right('settings.manage');
require_human();
$pdo = db();
$kind = 'category';
$withArchived = request_bool('include_archived');
$rows = find_vocabulary($pdo, $kind, $withArchived);
$editId = request_integer('edit');
$editing = $editId === null ? null : (find_vocabulary_row($pdo, $kind, $editId) ?? refuse(404, 'expense category not found.'));
log_screen_view($pdo, 'category-list');
if (wants_json()) {
    respond_screen(['kind' => $kind, 'rows' => array_map(static fn (array $r): array => present_vocabulary($kind, $r), $rows), 'include_archived' => $withArchived]);
}
$notices = ['saved' => ['success', 'Saved the expense category.'], 'archived' => ['success', 'The expense category was archived.'], 'restored' => ['success', 'The expense category is back.'], 'deleted' => ['success', 'The expense category was deleted.']];
render_screen('Expense categories', view('settings/vocabulary.php', ['kind' => $kind, 'rows' => $rows, 'editing' => $editing, 'withArchived' => $withArchived, 'title' => 'Expense categories', 'singular' => 'expense category', 'base' => '/settings/categories/', 'here' => here_url(),
    'notice' => ct_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'categories', 'screen' => 'category-list', 'entity' => 'category']);
