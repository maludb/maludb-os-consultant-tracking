<?php
declare(strict_types=1);
/**
 * Action `allocation_rule_save` (log `allocation_rule.save`: name, engagement_id, kernel ids, share_pct, pricing, markup_pct, resale_unit_price, valid_from, valid_to, active; `other` for agents): usage.write + manage of the engagement (the old one too when it changes).
 * A rule names at least one of agent / department / application; shares over 100 % of a line are refused by the database when a month is allocated, naming the line.
 */
require_once dirname(__DIR__, 3) . '/app/features/usage/handler.php';
ct_handler_begin();
require_right('usage.write');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('rule') ?? request_integer('rule_id');
$cur = null;
if ($id !== null) {
    $cur = find_allocation_rule($pdo, $id) ?? refuse(404, 'Rule not found.');
    usage_engagement_gate($pdo, $cur['engagement_id']);
}
$errors = [];
$engField = req_has('engagement') ? 'engagement' : 'engagement_id';
$engagement = ct_ref($pdo, $engField, $cur['engagement_id'] ?? null, 'SELECT 1 FROM engagements WHERE id = :id', 'engagement', $errors, false);
if ($engagement === null && !isset($errors[$engField])) {
    $errors['engagement'] = 'Choose the engagement.';
}
if ($engagement !== null && ($cur === null || $engagement !== $cur['engagement_id'])) {
    usage_engagement_gate($pdo, $engagement);
}
$name = $cur['name'] ?? null;
if (req_has('name') || $cur === null) {
    $name = mb_substr(trim((string) req_val('name')), 0, 200);
    if ($name === '') {
        $errors['name'] = 'Name the rule (for example "Research agent → Acme").';
    }
}
$agent = ct_kernel_id('agent', 'kernel_agent_id', $cur['kernel_agent_id'] ?? null, $errors, 'The agent');
$dept = ct_kernel_id('department', 'kernel_department_id', $cur['kernel_department_id'] ?? null, $errors, 'The department');
$app = ct_kernel_id('application', 'kernel_application_id', $cur['kernel_application_id'] ?? null, $errors, 'The application');
if ($errors === [] && $agent === null && $dept === null && $app === null) {
    $errors['agent'] = 'A rule names a kernel agent, department or application (any of them).';
}
$share = ct_decimal('share_pct', $cur['share_pct'] ?? '100.00', 0.01, 100, 'The share', $errors, false, 2);
[$pricing, $markup, $resale] = ct_pricing_fields($cur, $errors);
$from = ct_date('valid_from', $cur['valid_from'] ?? null, 'The start', $errors);
if ($from === null && !isset($errors['valid_from'])) {
    $errors['valid_from'] = 'The first day the rule applies (2026-03-01).';
}
$to = ct_date('valid_to', $cur['valid_to'] ?? null, 'The end', $errors, true);
if ($from !== null && $to !== null && $to <= $from) {
    $errors['valid_to'] = 'The end is after the start (the rule stops applying on that day).';
}
$active = req_has('active') ? ct_yes('active') : ($cur['active'] ?? true);
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$f = ['name' => $name, 'kernel_agent_id' => $agent, 'kernel_department_id' => $dept, 'kernel_application_id' => $app, 'engagement_id' => $engagement, 'share_pct' => $share, 'pricing' => $pricing, 'markup_pct' => $markup,
      'resale_unit_price' => $resale, 'valid_from' => $from, 'valid_to' => $to, 'active' => $active];
$loggable = static fn (array $r): array => ['name' => $r['name'], 'engagement_id' => (int) $r['engagement_id'], 'kernel_agent_id' => $r['kernel_agent_id'], 'kernel_department_id' => $r['kernel_department_id'], 'kernel_application_id' => $r['kernel_application_id'],
    'share_pct' => $r['share_pct'], 'pricing' => $r['pricing'], 'markup_pct' => $r['markup_pct'], 'resale_unit_price' => $r['resale_unit_price'], 'valid_from' => $r['valid_from'], 'valid_to' => $r['valid_to'], 'active' => (bool) $r['active']];
$newId = ct_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f, $loggable): int {
    $pdo->beginTransaction();
    $newId = save_allocation_rule($pdo, $id, $f, $me);
    $after = find_allocation_rule($pdo, $newId) ?? throw new DomainException('Not found.');
    if ($cur === null) {
        log_activity($pdo, 'allocation_rule.save', 'allocation_rule', $newId, ['engagement_id' => $after['engagement_id'], 'after' => $loggable($after) + ['rule_id' => $newId]]);
    } else {
        $d = ct_diff($loggable($cur), $loggable($after));
        if ($d['after'] !== []) {
            log_activity($pdo, 'allocation_rule.save', 'allocation_rule', $newId, ['engagement_id' => $after['engagement_id'], 'before' => $d['before'], 'after' => $d['after'] + ['name' => $after['name'], 'rule_id' => $newId]]);
        }
    }
    $pdo->commit();
    return $newId;
});
ct_done(($id === null ? 'Added the rule ' : 'Saved the rule ') . $f['name'], $newId, ct_land(return_path('/settings/allocation-rules/'), 'saved'), 'usageChanged', ['rule_id' => $newId]);
