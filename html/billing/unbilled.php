<?php
declare(strict_types=1);
/** Placeholder: screen `unbilled` is built by slice 6 (invoicing). The registry builder reads a controller that calls render_nav_stub() as unbuilt. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('unbilled', 'slice 6 (invoicing)', 'invoices.draft');
