<?php
declare(strict_types=1);
/** /settings/allocation-rules/?engagement= — the rules that place the OS statement on engagements (screen `allocation-rule-list`): name, what they match (the kernel's agent / department / application, by the names the statement carried when seen), engagement, share, pricing, dates, active, what each placed on the last allocated month. usage.write. */
require_once dirname(__DIR__, 3) . '/app/features/usage/handler.php';
require_right('usage.write');
require_human();
$pdo = db();
$engagement = request_integer('engagement');
$rules = find_allocation_rules($pdo, ['engagement_id' => $engagement]);
$names = kernel_names($pdo);
log_screen_view($pdo, 'allocation-rule-list');
if (wants_json()) {
    respond_screen(['rules' => array_map('present_allocation_rule', $rules), 'names' => $names]);
}
$notices = ['saved' => ['success', 'The rule was saved. Allocate a closed month again to apply it.'], 'deleted' => ['success', 'The rule was removed.']];
render_screen('Allocation rules', view('settings/allocation-rules/index.php', ['rules' => $rules, 'names' => $names, 'engagement' => $engagement, 'here' => here_url(), 'notice' => ct_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'allocation-rules', 'screen' => 'allocation-rule-list', 'entity' => 'allocation_rule']);
