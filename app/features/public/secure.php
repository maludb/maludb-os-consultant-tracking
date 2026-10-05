<?php
declare(strict_types=1);

/** The prelude of the client's door (slice 6): no session needed; the token is the authority; the page says nothing about WHY a link fails; nothing of it is cached or leaked in a referrer. */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/invoices/queries.php';
require_once dirname(__DIR__) . '/invoices/write.php';
require_once __DIR__ . '/queries.php';
require_once dirname(__DIR__) . '/settings/queries.php';

$GLOBALS['__public_door'] = 'portal';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

/** The token the vhost handed over (a 48-hex string), or '' . */
function door_token(): string
{
    $t = (string) ($_GET['token'] ?? $_REQUEST['token'] ?? '');
    return preg_match('/^[a-f0-9]{48}$/', $t) ? $t : '';
}

/** The one page for a dead, rotated, expired or unknown link — and nothing else about why. */
function door_refuse(): never
{
    http_response_code(404);
    echo view('public/expired.php');
    exit;
}

/** The invoice id the token opens, or the one refusal. [id, first view?] */
function door_invoice(PDO $pdo): array
{
    $token = door_token();
    $r = $token === '' ? null : secure_invoice_id($pdo, $token);
    return $r ?? door_refuse();
}

/** The route of this request with the token taken out — `GET /i/{token}/print` — so the activity log never holds a link. */
function door_route(): string
{
    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
    return trim(($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . preg_replace('#/i/[a-f0-9]{48}#', '/i/{token}', $path));
}

/** log_activity as the client: source portal, actor_client_id the invoice's client, the invoice's keys; the token never. */
function door_log(PDO $pdo, string $action, int $invoiceId, array $after = [], ?string $entityType = 'invoice', ?int $entityId = null): void
{
    $inv = $pdo->prepare('SELECT client_id, engagement_id FROM invoices WHERE id = :i');
    $inv->execute(['i' => $invoiceId]);
    $r = $inv->fetch() ?: ['client_id' => null, 'engagement_id' => null];
    log_activity($pdo, $action, $entityType, $entityId ?? $invoiceId, ['actor_member_id' => null, 'actor_client_id' => $r['client_id'] === null ? null : (int) $r['client_id'], 'source' => 'portal', 'route' => door_route(), 'invoice_id' => $invoiceId,
        'engagement_id' => $r['engagement_id'] === null ? null : (int) $r['engagement_id'], 'client_id' => $r['client_id'] === null ? null : (int) $r['client_id'], 'after' => $after]);
}
