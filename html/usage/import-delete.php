<?php
declare(strict_types=1);
/** Action `usage_import_delete` (log `usage_import.delete`: provider, file_name, removed; confirm; `deletion` for agents): usage.write + manage of the engagements its charges are on; removes the import's still-draft charges (an approved one stays with the import row it came from). */
require_once dirname(__DIR__, 2) . '/app/features/usage/handler.php';
ct_handler_begin();
require_right('usage.write');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('import') ?? request_integer('import_id') ?? request_integer('id') ?? refuse(422, 'Say which import.');
$imp = find_usage_import($pdo, $id) ?? refuse(404, 'Import not found.');
$engs = array_map('intval', $pdo->query('SELECT DISTINCT engagement_id FROM usage_charges WHERE import_id = ' . $id)->fetchAll(PDO::FETCH_COLUMN));
foreach ($engs as $e) {
    usage_engagement_gate($pdo, $e);
}
$n = ct_guard($pdo, static function () use ($pdo, $me, $id, $imp, $engs): int {
    $pdo->beginTransaction();
    $n = delete_usage_import($pdo, $id, $me);
    log_activity($pdo, 'usage_import.delete', 'usage_import', $id, ['engagement_id' => count($engs) === 1 ? $engs[0] : null, 'before' => ['provider_id' => $imp['provider_id'], 'file_name' => $imp['file_name'], 'inserted' => $imp['inserted']], 'after' => ['removed' => $n]]);
    $pdo->commit();
    return $n;
});
ct_done('Removed ' . $n . ' draft charge' . ($n === 1 ? '' : 's') . ' of ' . $imp['file_name'], $id, ct_land(return_path('/usage/'), 'import_deleted'), 'usageChanged', ['import_id' => $id, 'removed' => $n]);
