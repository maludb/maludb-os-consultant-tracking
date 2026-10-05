<?php
declare(strict_types=1);

/**
 * The vocabulary (slice 1): consulting roles, activity types, expense categories, usage providers, tax rates — one shape, five tables.
 * Each reads through its mcp_* view, is renamed in place, and is ARCHIVED, never deleted, once a record names it (the in-use count says).
 */

/** kind => [table, view, id column, the "in use" count SQL, the label, the request param] */
const VOCABULARY = [
    'role'     => ['consulting_roles',   'mcp_consulting_roles',   'consulting_role_id', 'SELECT count(*) FROM engagement_members WHERE consulting_role_id = :id', 'consulting role',   'role'],
    'activity' => ['activity_types',     'mcp_activity_types',     'activity_type_id',   'SELECT count(*) FROM time_entries WHERE activity_type_id = :id',          'activity type',     'activity'],
    'category' => ['expense_categories', 'mcp_expense_categories', 'category_id',        'SELECT count(*) FROM expenses WHERE category_id = :id',                   'expense category',  'category'],
    'provider' => ['usage_providers',    'mcp_usage_providers',    'provider_id',        'SELECT count(*) FROM usage_charges WHERE provider_id = :id',              'usage provider',    'provider'],
    'tax_rate' => ['tax_rates',          'mcp_tax_rates',          'tax_rate_id',        'SELECT (SELECT count(*) FROM invoice_lines WHERE tax_rate_id = :id) + (SELECT count(*) FROM credit_note_lines WHERE tax_rate_id = :id)', 'tax rate', 'tax_rate'],
];

/** The rows of a kind (live, or every one), each with in_use. */
function find_vocabulary(PDO $pdo, string $kind, bool $withArchived = false): array
{
    [$table, $view, $idCol, $useSql] = VOCABULARY[$kind];
    $order = $kind === 'tax_rate' ? 'archived_at IS NOT NULL, lower(name)' : 'archived_at IS NOT NULL, sort_order, ' . $idCol;
    $rows = $pdo->query("SELECT * FROM {$view} " . ($withArchived ? '' : 'WHERE archived_at IS NULL ') . 'ORDER BY ' . $order)->fetchAll();
    $use = $pdo->prepare($useSql);
    return array_map(static function (array $r) use ($use, $idCol, $kind): array {
        $r['id'] = (int) $r[$idCol];
        $use->execute(['id' => $r['id']]);
        $r['in_use'] = (int) $use->fetchColumn();
        return vocabulary_cast($kind, $r);
    }, $rows);
}

function vocabulary_cast(string $kind, array $r): array
{
    if (isset($r['sort_order'])) {
        $r['sort_order'] = (int) $r['sort_order'];
    }
    foreach (['billable_default', 'is_default'] as $b) {
        if (array_key_exists($b, $r)) {
            $r[$b] = (bool) $r[$b];
        }
    }
    if ($kind === 'provider' && isset($r['csv_mapping']) && is_string($r['csv_mapping'])) {
        $r['csv_mapping'] = json_decode($r['csv_mapping'], true);
    }
    return $r;
}

/** One row of a kind by id, through the view (null when unseen). */
function find_vocabulary_row(PDO $pdo, string $kind, int $id): ?array
{
    [$table, $view, $idCol, $useSql] = VOCABULARY[$kind];
    $st = $pdo->prepare("SELECT * FROM {$view} WHERE {$idCol} = :id");
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['id'] = $id;
    $use = $pdo->prepare($useSql);
    $use->execute(['id' => $id]);
    $r['in_use'] = (int) $use->fetchColumn();
    return vocabulary_cast($kind, $r);
}

/** The base row's state for the log: the kind's loggable columns. */
function vocabulary_loggable(string $kind, array $r): array
{
    return match ($kind) {
        'role' => ['key' => $r['key'], 'title' => $r['title'], 'description' => $r['description'], 'sort_order' => $r['sort_order']],
        'activity' => ['key' => $r['key'], 'label' => $r['label'], 'billable_default' => $r['billable_default'], 'sort_order' => $r['sort_order']],
        'category' => ['key' => $r['key'], 'label' => $r['label'], 'receipt_required_above' => $r['receipt_required_above'], 'default_markup_pct' => $r['default_markup_pct'], 'billable_default' => $r['billable_default'], 'sort_order' => $r['sort_order']],
        'provider' => ['provider_key' => $r['provider_key'], 'name' => $r['name'], 'has_mapping' => $r['csv_mapping'] !== null, 'sort_order' => $r['sort_order']],
        'tax_rate' => ['name' => $r['name'], 'rate' => $r['rate'], 'is_default' => $r['is_default']],
    };
}

/** The live rows for a select: [{id, label}]. */
function vocabulary_for_pick(PDO $pdo, string $kind): array
{
    return array_map(static fn (array $r): array => ['id' => $r['id'], 'label' => $r['title'] ?? $r['label'] ?? $r['name']], find_vocabulary($pdo, $kind));
}

/** A key from a label when the form gave none: lowercase, underscores. */
function vocabulary_key_from(string $label): string
{
    $k = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $label) ?? '', '_'));
    if ($k === '' || !preg_match('/^[a-z]/', $k)) {
        $k = 'k_' . $k;
    }
    return substr($k, 0, 40);
}
