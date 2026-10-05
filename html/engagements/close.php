<?php
declare(strict_types=1);
/** Action `engagement_close` (log `engagement.close`: before.status, after.status, reason): manage. The guard decides the legal moves. */
require_once dirname(__DIR__, 2) . '/app/features/engagements/handler.php';
engagement_move_handler('close', false);
