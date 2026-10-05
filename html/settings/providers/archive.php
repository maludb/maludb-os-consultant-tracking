<?php
declare(strict_types=1);
/** Archive or restore a provider (the manifest has no usage_provider_archive: a provider the kernel names is made on sight; this is the screen's own button — logged `usage_provider.archive`): the admin. */
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/handler.php';
vocabulary_archive_handler('provider', 'usage_provider', '/settings/providers/', 'provider');
