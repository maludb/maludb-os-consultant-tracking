<?php
declare(strict_types=1);
/** Action `tax_rate_save` (log `tax_rate.save`): the admin. name, rate (percent), is_default (one at a time). */
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/handler.php';
vocabulary_save_handler('tax_rate', 'tax_rate', '/settings/tax-rates/', 'tax rate', static function (PDO $pdo, ?array $cur, array &$errors): array {
    $rate = vocab_decimal('rate', $cur, 0, 99.9999, 'the rate', $errors, false);
    if ($rate === null && $cur === null) {
        $errors['rate'] = 'The rate is a percentage from 0 to below 100 (8.25).';
    }
    return ['name' => vocab_text('name', $cur, 60, 'name', $errors), 'rate' => $rate ?? '0.0000', 'is_default' => ct_yes('is_default', $cur['is_default'] ?? false)];
});
