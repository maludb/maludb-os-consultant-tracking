<?php
declare(strict_types=1);
/** Action `tax_rate_archive` (log `tax_rate.archive`; the default cannot be archived): the admin. */
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/handler.php';
vocabulary_archive_handler('tax_rate', 'tax_rate', '/settings/tax-rates/', 'tax rate');
