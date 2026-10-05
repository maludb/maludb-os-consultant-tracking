<?php
declare(strict_types=1);
/** /settings/tax-rates/ — the Tax rates (screen `tax-rate-list`; a table with inline add/edit; archive): settings.manage. Param include_archived. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/present.php';
require_right('settings.manage');
require_human();
$pdo = db();
$kind = 'tax_rate';
$withArchived = request_bool('include_archived');
$rows = find_vocabulary($pdo, $kind, $withArchived);
$editId = request_integer('edit');
$editing = $editId === null ? null : (find_vocabulary_row($pdo, $kind, $editId) ?? refuse(404, 'tax rate not found.'));
log_screen_view($pdo, 'tax-rate-list');
if (wants_json()) {
    respond_screen(['kind' => $kind, 'rows' => array_map(static fn (array $r): array => present_vocabulary($kind, $r), $rows), 'include_archived' => $withArchived]);
}
$notices = ['saved' => ['success', 'Saved the tax rate.'], 'archived' => ['success', 'The tax rate was archived.'], 'restored' => ['success', 'The tax rate is back.'], 'deleted' => ['success', 'The tax rate was deleted.']];
render_screen('Tax rates', view('settings/vocabulary.php', ['kind' => $kind, 'rows' => $rows, 'editing' => $editing, 'withArchived' => $withArchived, 'title' => 'Tax rates', 'singular' => 'tax rate', 'base' => '/settings/tax-rates/', 'here' => here_url(),
    'notice' => ct_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'tax-rates', 'screen' => 'tax-rate-list', 'entity' => 'tax_rate']);
