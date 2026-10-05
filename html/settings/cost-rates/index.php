<?php
declare(strict_types=1);
/** /settings/cost-rates/ — every person's cost rate by date range (screen `cost-rate-list`): the admin, Billing and Finance read it (`cost.table` = cost.view AND every engagement, the view's rule); a Manager is refused in words; the admin alone writes. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/present.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
require_right('cost.table');
require_human();
$pdo = db();
$rows = find_cost_rates($pdo);
$may = ['write' => is_ct_admin()];
$editId = request_integer('edit');
$editing = $editId === null ? null : (cost_rate_state($pdo, $editId) ?? refuse(404, 'Cost rate not found.'));
log_screen_view($pdo, 'cost-rate-list');
if (wants_json()) {
    respond_screen(['people' => array_map('present_cost_rates', $rows), 'currency' => find_settings($pdo)['base_currency'], 'may' => $may]);
}
$notices = ['saved' => ['success', 'Saved the cost rate.'], 'ended' => ['success', 'The range is ended.']];
render_screen('Cost rates', view('settings/cost-rates.php', ['rows' => $rows, 'may' => $may, 'editing' => $editing, 'members' => $may['write'] ? members_for_pick($pdo) : [], 'currency' => find_settings($pdo)['base_currency'], 'here' => here_url(),
    'notice' => ct_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'cost-rates', 'screen' => 'cost-rate-list', 'entity' => 'cost_rate']);
