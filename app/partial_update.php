<?php
declare(strict_types=1);

/**
 * Partial updates for the agents' door (mcp-and-api.md §4): under an action token with
 * `_partial=1`, the fields the caller did not send are filled from the record's BASE row, and only
 * when the caller can see the record through its mcp_* view. A browser can never trigger this.
 */

/** endpoint → [table, the form's id field, read view, the view's id column] */
const PARTIAL_UPDATE_TARGETS = [
    // Each slice adds its own save endpoints here (the manifest's `any field of`): the table, the form's id field, the read view, its id column.
    '/customers/save.php'       => ['customers', 'customer', 'mcp_customers', 'customer_id'],
    '/vendors/save.php'         => ['vendors', 'vendor', 'mcp_vendors', 'vendor_id'],
    '/settings/accounts/save.php' => ['accounts', 'account', 'mcp_accounts', 'account_id'],
    '/settings/items/save.php'  => ['items', 'item', 'mcp_items', 'item_id'],
    '/settings/banks/save.php'  => ['bank_accounts', 'bank_account', 'mcp_bank_accounts', 'bank_account_id'],
];

function partial_update_prefill(PDO $pdo): void
{
    if (empty($GLOBALS['__action_authed']) || ($_POST['_partial'] ?? '') !== '1' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        return;
    }
    unset($_POST['_partial']);
    $target = PARTIAL_UPDATE_TARGETS[(string) parse_url((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH)] ?? null;
    if ($target === null) {
        return;
    }
    [$table, $idField, $view, $viewId] = $target;
    $id = filter_var($_POST[$idField] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id < 1) {
        return;
    }
    $visible = $pdo->prepare("SELECT 1 FROM {$view} WHERE {$viewId} = :id");
    $visible->execute(['id' => $id]);
    if ($visible->fetchColumn() === false) {
        return;
    }
    $pk = 'id';
    $st = $pdo->prepare("SELECT * FROM {$table} WHERE {$pk} = :id");
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return;
    }
    foreach ($row as $column => $value) {
        if ($column === $pk || $value === null || array_key_exists($column, $_POST)) {
            continue;
        }
        $_POST[$column] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
}
