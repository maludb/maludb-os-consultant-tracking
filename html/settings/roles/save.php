<?php
declare(strict_types=1);
/** Action `consulting_role_save` (log `consulting_role.save`): the admin. title, key (from the title when blank), description, sort_order. */
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/handler.php';
vocabulary_save_handler('role', 'consulting_role', '/settings/roles/', 'consulting role', static function (PDO $pdo, ?array $cur, array &$errors): array {
    $title = vocab_text('title', $cur, 80, 'title', $errors);
    return ['key' => vocab_key('key', $cur, (string) $title, $errors), 'title' => $title, 'description' => vocab_text('description', $cur, 500, 'description', $errors, false),
            'sort_order' => ct_int('sort_order', $cur['sort_order'] ?? 0, 0, 1000, 'The order', $errors)];
});
