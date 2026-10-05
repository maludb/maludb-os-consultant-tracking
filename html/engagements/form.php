<?php
declare(strict_types=1);
/** /engagements/new (param client prefills) and /engagements/{id}/edit (screens `engagement-add`, `engagement-edit`; engagements.manage; an edit needs to manage it). */
require_once dirname(__DIR__, 2) . '/app/features/engagements/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_right('engagements.manage');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('engagement');
$cur = null;
if ($id !== null) {
    $cur = engagement_from_request($pdo, $id);
    require_manages($cur);
}
$screen = $cur === null ? 'engagement-add' : 'engagement-edit';
$settings = find_settings($pdo);
$clients = $pdo->query('SELECT id AS client_id, name, currency, default_rate_card_id, terms_days FROM clients WHERE archived_at IS NULL ORDER BY lower(name)')->fetchAll();   // every live client by name (the view guards the details, not the name)
$cards = has_right('rates.view') ? $pdo->query('SELECT rate_card_id, name, currency FROM mcp_rate_cards WHERE archived_at IS NULL ORDER BY lower(name)')->fetchAll() : [];
$prefillClient = request_integer('client');
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['engagement' => $cur === null ? null : present_engagement($cur), 'client' => $prefillClient, 'clients' => $clients, 'rate_cards' => $cards, 'kinds' => ENGAGEMENT_KINDS, 'cadences' => BILLING_CADENCES, 'groupings' => TIME_GROUPINGS,
        'defaults' => ['currency' => $settings['base_currency'], 'markup_pct' => $settings['default_markup_pct'], 'receipt_threshold' => $settings['receipt_threshold'], 'time_grouping' => $settings['default_time_grouping'], 'share_receipts' => $settings['share_receipts_default']]]);
}
render_screen($cur === null ? 'New engagement' : 'Change ' . $cur['number'], view('engagements/form.php', ['cur' => $cur, 'prefillClient' => $prefillClient, 'clients' => $clients, 'cards' => $cards, 'settings' => $settings,
    'departments' => find_live_departments($pdo), 'members' => members_for_pick($pdo), 'me' => (int) current_member_id(), 'here' => here_url()]),
    ['activeNav' => 'engagements', 'screen' => $screen, 'entity' => 'engagement', 'recordId' => $id === null ? '' : (string) $id]);
