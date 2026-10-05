<?php
declare(strict_types=1);
/** /settings/allocation-rules/new?engagement= and /{id}/edit (screens `allocation-rule-add`, `allocation-rule-edit`): usage.write on an engagement the caller manages. A rule names a kernel agent, department or application (any subset), an engagement and a share. */
require_once dirname(__DIR__, 3) . '/app/features/usage/handler.php';
require_right('usage.write');
require_human();
$pdo = db();
$id = request_integer('rule') ?? request_integer('id');
$cur = null;
if ($id !== null) {
    $cur = find_allocation_rule($pdo, $id) ?? refuse(404, 'Rule not found.');
    usage_engagement_gate($pdo, $cur['engagement_id']);
}
$engagements = usage_engagements($pdo);
$names = kernel_names($pdo);
$screen = $cur === null ? 'allocation-rule-add' : 'allocation-rule-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['rule' => $cur === null ? null : present_allocation_rule($cur), 'engagements' => $engagements, 'names' => $names, 'pricings' => USAGE_PRICINGS]);
}
render_screen($cur === null ? 'Add an allocation rule' : 'Change ' . $cur['name'], view('settings/allocation-rules/form.php', ['cur' => $cur, 'engagements' => $engagements, 'names' => $names, 'prefill' => request_integer('engagement'), 'here' => here_url()]),
    ['activeNav' => 'allocation-rules', 'screen' => $screen, 'entity' => 'allocation_rule', 'recordId' => $id === null ? '' : (string) $id]);
