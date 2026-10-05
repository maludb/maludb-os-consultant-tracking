<?php
declare(strict_types=1);
/** /attachments/{id} — a file, through the gate: the mcp_attachments view decides who sees it. Never served by Apache from storage/. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/documents/write.php';
require_login();
$pdo = db();
$id = request_integer('id') ?? request_integer('attachment') ?? refuse(404, 'File not found.');
$a = attachment_path($pdo, $id) ?? refuse(404, 'File not found.');
if (!is_file($a['path'])) {
    refuse(404, 'The file is missing from the store.');
}
header('Content-Type: ' . $a['mime']);
header('Content-Length: ' . (string) filesize($a['path']));
header('Content-Disposition: ' . (str_starts_with($a['mime'], 'image/') || $a['mime'] === 'application/pdf' ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $a['filename']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($a['path']);
