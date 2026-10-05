<?php
declare(strict_types=1);
/** Action `usage_provider_save` (log `usage_provider.save`: never the mapping's content, only that one exists): the admin. name, provider_key (the kernel's statement key), csv_mapping (JSON), sort_order. */
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/handler.php';
vocabulary_save_handler('provider', 'usage_provider', '/settings/providers/', 'provider', static function (PDO $pdo, ?array $cur, array &$errors): array {
    $name = vocab_text('name', $cur, 80, 'name', $errors);
    $mapping = $cur['csv_mapping'] ?? null;
    if (req_has('csv_mapping')) {
        $raw = trim((string) req_val('csv_mapping'));
        if ($raw === '') {
            $mapping = null;
        } else {
            $mapping = json_decode($raw, true);
            if (!is_array($mapping)) {
                $errors['csv_mapping'] = 'The CSV mapping is a JSON object (column names to fields).';
            }
        }
    }
    return ['provider_key' => vocab_key('provider_key', $cur, (string) $name, $errors), 'name' => $name, 'csv_mapping' => $mapping, 'sort_order' => ct_int('sort_order', $cur['sort_order'] ?? 50, 0, 1000, 'The order', $errors)];
});
