<?php
declare(strict_types=1);
/** /settings/capacity/ — every person's hours a week by date range (screen `capacity-list`; the view admits reports.view, or one's own): the admin writes. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/present.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
require_right('settings.manage');
require_human();
$pdo = db();
$rows = find_capacity($pdo);
$settings = find_settings($pdo);
$editId = request_integer('edit');
$editing = $editId === null ? null : (capacity_state($pdo, $editId) ?? refuse(404, 'Capacity not found.'));
log_screen_view($pdo, 'capacity-list');
if (wants_json()) {
    respond_screen(['people' => array_map('present_capacity', $rows), 'default_hours_per_week' => $settings['capacity_hours_per_week'], 'may' => ['write' => true]]);
}
render_screen('Capacity', view('settings/capacity.php', ['rows' => $rows, 'editing' => $editing, 'members' => members_for_pick($pdo), 'default' => $settings['capacity_hours_per_week'], 'here' => here_url(),
    'notice' => ct_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved the capacity.']])]), ['activeNav' => 'capacity', 'screen' => 'capacity-list', 'entity' => 'capacity']);
