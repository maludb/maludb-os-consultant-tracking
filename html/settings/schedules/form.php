<?php
declare(strict_types=1);
/** /settings/schedules/new?engagement= and /{id}/edit (screens `schedule-add`, `schedule-edit`): usage.write on an engagement the caller manages. */
require_once dirname(__DIR__, 3) . '/app/features/usage/handler.php';
require_right('usage.write');
require_human();
$pdo = db();
$id = request_integer('schedule') ?? request_integer('id');
$cur = null;
if ($id !== null) {
    $cur = find_schedule($pdo, $id) ?? refuse(404, 'Schedule not found.');
    usage_engagement_gate($pdo, $cur['engagement_id']);
}
$engagements = usage_engagements($pdo);
$providers = usage_providers_for_pick($pdo);
$screen = $cur === null ? 'schedule-add' : 'schedule-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['schedule' => $cur === null ? null : present_schedule($cur), 'engagements' => $engagements, 'providers' => array_map(static fn (array $p): array => ['provider_id' => $p['provider_id'], 'name' => $p['name']], $providers),
        'kinds' => USAGE_KINDS, 'units' => USAGE_UNITS, 'pricings' => USAGE_PRICINGS, 'cadences' => CADENCES]);
}
render_screen($cur === null ? 'Add a hosting schedule' : 'Change ' . $cur['description'], view('settings/schedules/form.php', ['cur' => $cur, 'engagements' => $engagements, 'providers' => $providers, 'prefill' => request_integer('engagement'), 'here' => here_url()]),
    ['activeNav' => 'schedules', 'screen' => $screen, 'entity' => 'recurring_charge', 'recordId' => $id === null ? '' : (string) $id]);
