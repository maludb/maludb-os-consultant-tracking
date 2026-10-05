<?php
declare(strict_types=1);
/** Action `activity_type_save` (log `activity_type.save`): the admin. label, key, billable_default, sort_order. */
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/handler.php';
vocabulary_save_handler('activity', 'activity_type', '/settings/activity-types/', 'activity type', static function (PDO $pdo, ?array $cur, array &$errors): array {
    $label = vocab_text('label', $cur, 80, 'label', $errors);
    return ['key' => vocab_key('key', $cur, (string) $label, $errors), 'label' => $label, 'billable_default' => ct_yes('billable_default', $cur['billable_default'] ?? true),
            'sort_order' => ct_int('sort_order', $cur['sort_order'] ?? 0, 0, 1000, 'The order', $errors)];
});
