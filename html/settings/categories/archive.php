<?php
declare(strict_types=1);
/** Action `expense_category_archive` (log `expense_category.archive`): the admin. */
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/handler.php';
vocabulary_archive_handler('category', 'expense_category', '/settings/categories/', 'expense category');
