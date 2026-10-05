<?php
declare(strict_types=1);
/** /settings/rate-cards/{id} — one card: a row per consulting role with the rate editable in place; the engagements and clients using it (screen `rate-card-view`; rates.view). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/present.php';
require_right('rates.view');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('rate_card') ?? refuse(404, 'Rate card not found.');
$r = find_rate_card($pdo, $id) ?? refuse(404, 'Rate card not found.');
$may = ['manage' => has_right('rate_cards.manage'), 'delete' => is_ct_admin()];
log_screen_view($pdo, 'rate-card-view');
if (wants_json()) {
    respond_screen(['rate_card' => present_rate_card($r), 'may' => $may]);
}
$notices = ['saved' => ['success', 'Saved the rate card.'], 'rate' => ['success', 'Saved the rate.'], 'restored' => ['success', $r['name'] . ' is back.']];
render_screen($r['name'], view('settings/rate-cards/view.php', ['r' => $r, 'may' => $may, 'here' => here_url(), 'notice' => ct_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'rate-cards', 'screen' => 'rate-card-view', 'entity' => 'rate_card', 'recordId' => (string) $id]);
