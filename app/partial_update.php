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
    '/clients/save.php'              => ['clients', 'client', 'mcp_clients', 'client_id'],
    '/engagements/save.php'          => ['engagements', 'engagement', 'mcp_engagements', 'engagement_id'],
    '/engagements/staffing/save.php' => ['engagement_members', 'staffing', 'mcp_engagement_members', 'staffing_id'],
    '/engagements/milestones/save.php' => ['milestones', 'milestone', 'mcp_milestones', 'milestone_id'],
    '/time/entries/save.php'         => ['time_entries', 'entry', 'mcp_time_entries', 'entry_id'],
    '/clients/contacts/save.php'     => ['client_contacts', 'contact', 'mcp_client_contacts', 'contact_id'],
    '/settings/rate-cards/save.php'  => ['rate_cards', 'rate_card', 'mcp_rate_cards', 'rate_card_id'],
    '/settings/roles/save.php'       => ['consulting_roles', 'role', 'mcp_consulting_roles', 'consulting_role_id'],
    '/settings/activity-types/save.php' => ['activity_types', 'activity', 'mcp_activity_types', 'activity_type_id'],
    '/settings/categories/save.php'  => ['expense_categories', 'category', 'mcp_expense_categories', 'category_id'],
    '/settings/providers/save.php'   => ['usage_providers', 'provider', 'mcp_usage_providers', 'provider_id'],
    '/settings/tax-rates/save.php'   => ['tax_rates', 'tax_rate', 'mcp_tax_rates', 'tax_rate_id'],
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
