<?php
declare(strict_types=1);
/** GET /i/{token}/attachment/{id} — a receipt the engagement shares with the client, served through `client_visible` (an expense billed on THIS invoice, shared). No session; the token is the authority; anything else is the one refusal page. */
require_once dirname(__DIR__, 2) . '/app/features/public/secure.php';
$pdo = db();
$token = door_token();
$id = (int) ($_GET['id'] ?? 0);
$a = $token === '' || $id < 1 ? null : secure_attachment($pdo, $token, $id);
if ($a === null || !is_file($a['path'])) {
    door_refuse();
}
header('Content-Type: ' . $a['mime']);
header('Content-Length: ' . (string) filesize($a['path']));
header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $a['filename']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($a['path']);
