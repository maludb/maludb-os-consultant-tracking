<?php
declare(strict_types=1);
/** Action `consulting_role_archive` (log `consulting_role.archive`; archived=no restores; delete=yes removes an unused one): the admin. */
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/handler.php';
vocabulary_archive_handler('role', 'consulting_role', '/settings/roles/', 'consulting role');
