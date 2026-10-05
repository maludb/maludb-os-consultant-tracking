<?php
declare(strict_types=1);
/** Action `engagement_cancel` (log `engagement.cancel`: before.status, after.status, reason — required): manage. The guard decides the legal moves. */
require_once dirname(__DIR__, 2) . '/app/features/engagements/handler.php';
engagement_move_handler('cancel', true);
