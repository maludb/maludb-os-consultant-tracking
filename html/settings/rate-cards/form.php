<?php
declare(strict_types=1);
/** /settings/rate-cards/new (param copy_of) and /settings/rate-cards/{id}/edit (screens `rate-card-add`, `rate-card-edit`; rate_cards.manage). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/rates/present.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/queries.php';
require_right('rate_cards.manage');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('rate_card');
$cur = $id === null ? null : (find_rate_card($pdo, $id) ?? refuse(404, 'Rate card not found.'));
$copyOf = $cur === null && request_integer('copy_of') !== null ? (find_rate_card($pdo, (int) request_integer('copy_of')) ?? refuse(404, 'Rate card not found.')) : null;
$screen = $cur === null ? 'rate-card-add' : 'rate-card-edit';
$settings = find_settings($pdo);
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['rate_card' => $cur === null ? null : present_rate_card($cur), 'copy_of' => $copyOf === null ? null : present_rate_card($copyOf), 'in_use' => $cur === null ? 0 : $cur['engagement_count'], 'defaults' => ['currency' => $settings['base_currency']]]);
}
render_screen($cur === null ? ($copyOf === null ? 'Add a rate card' : 'Copy ' . $copyOf['name']) : 'Change ' . $cur['name'], view('settings/rate-cards/form.php', ['cur' => $cur, 'copyOf' => $copyOf, 'settings' => $settings, 'here' => here_url()]),
    ['activeNav' => 'rate-cards', 'screen' => $screen, 'entity' => 'rate_card', 'recordId' => $id === null ? '' : (string) $id]);
