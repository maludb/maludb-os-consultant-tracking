<?php
declare(strict_types=1);
/** Action `activity_type_archive` (log `activity_type.archive`): the admin. */
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/handler.php';
vocabulary_archive_handler('activity', 'activity_type', '/settings/activity-types/', 'activity type');
