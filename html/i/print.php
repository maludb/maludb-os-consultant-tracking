<?php
declare(strict_types=1);
/** GET /i/{token}/print — the same invoice, print-ready, no chrome (the browser's "Save as PDF" is the PDF). No session; the token is the authority; one refusal page. Logs `invoice.view` (via print) as the client. */
require_once dirname(__DIR__, 2) . '/app/features/public/secure.php';
$pdo = db();
$token = door_token();
$s = $token === '' ? null : secure_invoice($pdo, $token);
if ($s === null) {
    door_refuse();
}
door_log($pdo, 'invoice.view', $s['invoice_id'], ['number' => $s['document']['invoice']['number'], 'via' => 'print']);
echo view('public/print.php', ['d' => $s['document'], 'token' => $token]);
