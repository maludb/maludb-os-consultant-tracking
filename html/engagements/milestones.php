<?php
declare(strict_types=1);
/** /engagements/{id}/milestones — the engagement's milestones tab at its own address (screen `engagement-milestones`): the view with tab=milestones. */
require_once dirname(__DIR__, 2) . '/app/features/engagements/handler.php';
$_GET['tab'] = 'milestones';
$_REQUEST['tab'] = 'milestones';
require __DIR__ . '/view.php';
