<?php
declare(strict_types=1);
/** /engagements/{id}/staffing — the engagement's staffing tab at its own address (screen `engagement-staffing`): the view with tab=staffing. */
require_once dirname(__DIR__, 2) . '/app/features/engagements/handler.php';
$_GET['tab'] = 'staffing';
$_REQUEST['tab'] = 'staffing';
require __DIR__ . '/view.php';
