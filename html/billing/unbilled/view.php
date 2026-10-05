<?php
declare(strict_types=1);
/** /billing/unbilled/{id} — the items behind one engagement's WIP (screen `unbilled-items`): by date with the person, the description, quantity, amount, and the hours with no rate marked. */
require_once dirname(__DIR__, 3) . '/app/features/invoices/handler.php';
require_any_right('invoices.draft|billing.write|reports.view');
require_human();
$pdo = db();
$eid = request_integer('id') ?? request_integer('engagement') ?? refuse(404, 'Engagement not found.');
$e = $pdo->prepare('SELECT engagement_id, number, name, client_id, client_name, currency FROM mcp_engagements WHERE engagement_id = :e');
$e->execute(['e' => $eid]);
$eng = $e->fetch() ?: refuse(404, 'Engagement not found.');
$asOf = request_date('as_of') ?: null;
$items = unbilled_items($pdo, $eid, $asOf);
log_screen_view($pdo, 'unbilled-items');
if (wants_json()) {
    respond_screen(['engagement' => ['engagement_id' => (int) $eng['engagement_id'], 'number' => $eng['number'], 'name' => $eng['name']], 'currency' => trim((string) $eng['currency']), 'items' => array_map('present_unbilled_item', $items)]);
}
render_screen('Unbilled · ' . $eng['number'], view('billing/unbilled-items.php', ['eng' => $eng, 'items' => $items, 'here' => here_url(), 'may' => ['draft' => has_right('invoices.draft') && can_manage_engagement($eid)]]), ['activeNav' => 'unbilled', 'screen' => 'unbilled-items', 'entity' => 'engagement', 'recordId' => (string) $eid]);
