<?php
declare(strict_types=1);
/** /engagements/{id}/rates — the engagement's rates tab at its own address (screen `engagement-rates`): the view with tab=rates. */
require_once dirname(__DIR__, 2) . '/app/features/engagements/handler.php';
$_GET['tab'] = 'rates';
$_REQUEST['tab'] = 'rates';
require __DIR__ . '/view.php';
