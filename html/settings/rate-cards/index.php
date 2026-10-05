<?php
declare(strict_types=1);
/** /settings/rate-cards/ — the rate cards as cards (screen `rate-card-list`; rates.view to read, rate_cards.manage to add). Param include_archived. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/present.php';
require_right('rates.view');
require_human();
$pdo = db();
$withArchived = request_bool('include_archived');
$rows = find_rate_cards($pdo, $withArchived);
$may = ['manage' => has_right('rate_cards.manage'), 'delete' => is_ct_admin()];
log_screen_view($pdo, 'rate-card-list');
if (wants_json()) {
    respond_screen(['rate_cards' => array_map('present_rate_card', $rows), 'include_archived' => $withArchived, 'may' => $may]);
}
$notices = ['archived' => ['success', 'The rate card was archived.'], 'deleted' => ['success', 'The rate card was deleted.'], 'restored' => ['success', 'The rate card is back.']];
render_screen('Rate cards', view('settings/rate-cards/index.php', ['rows' => $rows, 'withArchived' => $withArchived, 'may' => $may, 'here' => here_url(), 'notice' => ct_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'rate-cards', 'screen' => 'rate-card-list', 'entity' => 'rate_card']);
