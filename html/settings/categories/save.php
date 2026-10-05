<?php
declare(strict_types=1);
/** Action `expense_category_save` (log `expense_category.save`): the admin. label, key, receipt_required_above (blank = the settings'), default_markup_pct (blank = the engagement's), billable_default, sort_order. */
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/handler.php';
vocabulary_save_handler('category', 'expense_category', '/settings/categories/', 'expense category', static function (PDO $pdo, ?array $cur, array &$errors): array {
    $label = vocab_text('label', $cur, 80, 'label', $errors);
    return ['key' => vocab_key('key', $cur, (string) $label, $errors), 'label' => $label,
            'receipt_required_above' => vocab_decimal('receipt_required_above', $cur, 0, 1000000000, 'the receipt threshold', $errors),
            'default_markup_pct' => vocab_decimal('default_markup_pct', $cur, 0, 500, 'the markup', $errors),
            'billable_default' => ct_yes('billable_default', $cur['billable_default'] ?? true), 'sort_order' => ct_int('sort_order', $cur['sort_order'] ?? 0, 0, 1000, 'The order', $errors)];
});
