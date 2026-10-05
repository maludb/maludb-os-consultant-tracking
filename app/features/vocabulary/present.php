<?php
declare(strict_types=1);

/** The JSON of a vocabulary row by kind (a whitelist). */
function present_vocabulary(string $kind, array $r): array
{
    $base = ['id' => $r['id'], 'archived' => $r['archived_at'] !== null, 'in_use' => $r['in_use']];
    return $base + match ($kind) {
        'role' => ['consulting_role_id' => $r['id'], 'key' => $r['key'], 'title' => $r['title'], 'description' => $r['description'], 'sort_order' => $r['sort_order']],
        'activity' => ['activity_type_id' => $r['id'], 'key' => $r['key'], 'label' => $r['label'], 'billable_default' => $r['billable_default'], 'sort_order' => $r['sort_order']],
        'category' => ['category_id' => $r['id'], 'key' => $r['key'], 'label' => $r['label'], 'receipt_required_above' => $r['receipt_required_above'], 'default_markup_pct' => $r['default_markup_pct'], 'billable_default' => $r['billable_default'], 'sort_order' => $r['sort_order']],
        'provider' => ['provider_id' => $r['id'], 'provider_key' => $r['provider_key'], 'name' => $r['name'], 'csv_mapping' => $r['csv_mapping'], 'sort_order' => $r['sort_order']],
        'tax_rate' => ['tax_rate_id' => $r['id'], 'name' => $r['name'], 'rate' => $r['rate'], 'is_default' => $r['is_default']],
    };
}
