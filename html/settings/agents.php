<?php
declare(strict_types=1);
/** Placeholder: screen `agents` is built by slice 9 (home, admin and tokens). The registry builder reads a controller that calls render_nav_stub() as unbuilt. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('agents', 'slice 9 (home, admin and tokens)', 'settings.manage');
