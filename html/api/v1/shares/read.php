<?php
declare(strict_types=1);

/**
 * POST /api/v1/shares/read.php — the records MCP server's door to slice 8's share functions (docs/build-specs/mcp-servers.md, "How the shares reach slice 8's functions"). INTERNAL PORT ONLY (the public vhost blocks /api).
 * The server has already verified the bearer (the kernel's token, or a person's own); it signs the request with ACTIONS_RELAY_KEY — HMAC-SHA256 over "share:<unix time>:<sha256 of the body>", headers X-Share-Time and
 * X-Share-Signature, ±120 s — a key no agent holds. Body: {"tool": invoices_issued|credit_notes_issued|expenses_by_category|unbilled_summary|reimbursements_approved|engagement_list, "caller": "kernel"|"person",
 * "member_id": <the verified member, for a person>, "arguments": {...}}. The answer is the document itself (the very array app/features/exports/documents.php builds for the exports page — one truth, two doors);
 * a refusal is 403 {"error": <the sentence>}, a bad argument 422, a bad signature 401. The kernel's call logs `share.read` inside the function; a person's read is not logged here (the exports page logs a download).
 */
require_once dirname(__DIR__, 4) . '/app/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/features/exports/queries.php';

function share_relay_reply(array $payload, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    share_relay_reply(['error' => 'POST only.'], 405);
}
$body = (string) file_get_contents('php://input');
$time = (string) ($_SERVER['HTTP_X_SHARE_TIME'] ?? '');
$sig = (string) ($_SERVER['HTTP_X_SHARE_SIGNATURE'] ?? '');
$key = (string) env('ACTIONS_RELAY_KEY', '');
if (strlen($key) < 32 || !ctype_digit($time) || abs(time() - (int) $time) > 120 || !hash_equals(hash_hmac('sha256', 'share:' . $time . ':' . hash('sha256', $body), $key), $sig)) {
    share_relay_reply(['error' => 'unauthorized'], 401);
}
$in = json_decode($body, true);
if (!is_array($in) || !is_string($in['tool'] ?? null) || !in_array($in['caller'] ?? null, ['kernel', 'person'], true)) {
    share_relay_reply(['error' => 'The request names a tool and a caller.'], 422);
}
$caller = (string) $in['caller'];
$a = is_array($in['arguments'] ?? null) ? $in['arguments'] : [];
$pdo = db();
if ($caller === 'person') {
    $member = $in['member_id'] ?? null;
    if (!is_int($member) || $member < 1 || !db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM members WHERE id = :m AND status = 'active' AND capability IS NOT NULL)", ['m' => $member])) {
        share_relay_reply(['error' => 'That member is not admitted.'], 403);
    }
    $pdo->prepare('SELECT set_config(?, ?, false)')->execute(['app.member_id', (string) $member]);
}
$text = static fn (string $k): ?string => isset($a[$k]) && $a[$k] !== '' ? (string) $a[$k] : null;
$num = static fn (string $k): ?int => isset($a[$k]) && $a[$k] !== '' && ctype_digit((string) $a[$k]) ? (int) $a[$k] : null;
try {
    $doc = match ($in['tool']) {
        'invoices_issued' => share_invoices_issued($pdo, (string) $text('from'), (string) $text('to'), $num('client_id'), $caller),
        'credit_notes_issued' => share_credit_notes_issued($pdo, (string) $text('from'), (string) $text('to'), $num('client_id'), $caller),
        'expenses_by_category' => share_expenses_by_category($pdo, (string) $text('from'), (string) $text('to'), $caller),
        'unbilled_summary' => share_unbilled_summary($pdo, $text('as_of'), $num('engagement_id'), $num('client_id'), $caller),
        'reimbursements_approved' => share_reimbursements_approved($pdo, $text('as_of'), $num('member_id'), $caller),
        'engagement_list' => share_engagement_list($pdo, $text('status'), $num('client_id') ?? $num('client'), $caller),
        default => throw new DomainException('That is not one of the six shared tools.'),
    };
} catch (ShareRefused $e) {
    share_relay_reply(['error' => $e->getMessage()], 403);
} catch (DomainException $e) {
    share_relay_reply(['error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('shares/read: ' . $e->getMessage());
    share_relay_reply(['error' => 'The document could not be built.'], 500);
}
share_relay_reply($doc, 200);
