<?php
declare(strict_types=1);
/**
 * /i/{token} — the client's secure invoice page (screen `invoice-secure`; NO session — the token is the authority; reached through the vhost's /i/ rewrite). The one refusal page for a dead, rotated, expired or unknown link. The page counts
 * a view (`ct_secure_link_invoice` stamps `viewed_at`) and logs `invoice.view` with `actor_client_id` = the client and `source = portal` — never the token — and the first view tells billing (`invoice_viewed`). Not an action: no agent reaches it.
 */
require_once dirname(__DIR__) . '/app/features/public/secure.php';
$pdo = db();
$token = door_token();
$s = $token === '' ? null : secure_invoice($pdo, $token);
if ($s === null) {
    door_refuse();
}
$d = $s['document'];
$pdo->beginTransaction();
door_log($pdo, 'invoice.view', $s['invoice_id'], ['number' => $d['invoice']['number'], 'status' => $d['invoice']['status'], 'first_view' => $s['first_view']]);
if ($s['first_view']) {
    notify_billing($pdo, 'invoice_viewed', 'invoice', $s['invoice_id'], ['title' => $d['invoice']['number'] . ' was opened by ' . $d['invoice']['client_name'], 'body' => 'The client opened the secure link: ' . rtrim((string) env('APP_URL', ''), '/') . '/invoices/' . $s['invoice_id'],
        'dedupe' => 'inv-viewed:' . $s['invoice_id']]);
}
$pdo->commit();
echo view('public/invoice.php', ['d' => $d, 'token' => $token, 'thanks' => ($_GET['thanks'] ?? '') === '1', 'claimError' => null]);
