<?php
declare(strict_types=1);
/** Action `statement_send` (slice 6): until then, 501 in words — the manifest names it, nothing sends. */
require_once dirname(__DIR__, 2) . '/app/features/clients/handler.php';
ct_handler_begin();
require_right('billing.write');
emit_action_status(false, ['error' => 'Sending a statement is built by slice 6.']);
refuse(501, 'Sending a statement is built by slice 6.');
