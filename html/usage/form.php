<?php
declare(strict_types=1);
/** /usage/new?engagement= and /usage/{id}/edit (screens `usage-add`, `usage-edit`): usage.write on an engagement the caller manages; a draft only. The billable amount shows live; the database writes the truth. */
require_once dirname(__DIR__, 2) . '/app/features/usage/handler.php';
require_right('usage.write');
require_human();
$pdo = db();
$id = usage_id_from_request();
$cur = null;
if ($id !== null) {
    $cur = find_usage_charge($pdo, $id) ?? refuse(404, 'Usage charge not found.');
    usage_engagement_gate($pdo, $cur['engagement_id']);
    if ($cur['status'] !== 'draft') {
        refuse(422, 'Usage charge ' . $cur['number'] . ' is ' . $cur['status'] . '; ' . ($cur['status'] === 'approved' ? 'void it and make another to change it' : 'a void charge does not change') . '.');
    }
}
$engagements = usage_engagements($pdo);
$providers = usage_providers_for_pick($pdo);
$screen = $cur === null ? 'usage-add' : 'usage-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['charge' => $cur === null ? null : present_usage_charge($cur), 'engagements' => $engagements, 'providers' => array_map(static fn (array $p): array => ['provider_id' => $p['provider_id'], 'name' => $p['name']], $providers),
        'kinds' => USAGE_KINDS, 'units' => USAGE_UNITS, 'pricings' => USAGE_PRICINGS]);
}
render_screen($cur === null ? 'Record a usage charge' : 'Change ' . $cur['number'], view('usage/form.php', ['cur' => $cur, 'engagements' => $engagements, 'providers' => $providers, 'prefill' => request_integer('engagement'), 'here' => here_url()]),
    ['activeNav' => 'usage', 'screen' => $screen, 'entity' => 'usage_charge', 'recordId' => $id === null ? '' : (string) $id]);
