<?php
declare(strict_types=1);
/** Action `recurring_charge_run` (log `recurring_charge.run`: schedule_id, charge, period, amount, currency, next_date, active; and `usage_charge.draft` for the charge it made): usage.write + manage; a due schedule only (the verb says when it is next due). One draft charge for the period it covers; the next date moves on; an end date deactivates. */
require_once dirname(__DIR__, 3) . '/app/features/usage/handler.php';
ct_handler_begin();
require_right('usage.write');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('schedule') ?? request_integer('schedule_id') ?? request_integer('id') ?? refuse(422, 'Say which schedule.');
$cur = find_schedule($pdo, $id) ?? refuse(404, 'Schedule not found.');
usage_engagement_gate($pdo, $cur['engagement_id']);
$out = ct_guard($pdo, static function () use ($pdo, $me, $id, $cur): array {
    $pdo->beginTransaction();
    $u = run_schedule($pdo, $id, $me);
    $next = $pdo->prepare('SELECT next_date, active FROM recurring_charges WHERE id = :id');
    $next->execute(['id' => $id]);
    $n = $next->fetch();
    $money = usage_money($u);
    log_activity($pdo, 'recurring_charge.run', 'recurring_charge', $id, ['engagement_id' => $cur['engagement_id'], 'after' => ['schedule_id' => $id, 'charge_id' => (int) $u['id'], 'number' => $u['number'], 'period_start' => $u['period_start'], 'period_end' => $u['period_end'],
        'amount' => $u['billable_amount'], 'currency' => trim((string) $u['currency']), 'next_date' => $n['next_date'], 'active' => (bool) $n['active']]]);
    usage_log($pdo, 'usage_charge.draft', (int) $u['id'], $cur['engagement_id'], ['after' => ['number' => $u['number'], 'kind' => $u['kind'], 'quantity' => $u['quantity'], 'unit' => $u['unit'], 'cost_amount' => $u['cost_amount'], 'billable_amount' => $u['billable_amount'],
        'currency' => trim((string) $u['currency']), 'source' => 'schedule', 'amount' => $u['billable_amount'], 'schedule_id' => $id, 'recorded_by' => $me]]);
    notify_usage_drafted($pdo, ['usage_charge_id' => (int) $u['id'], 'engagement_id' => $cur['engagement_id'], 'number' => $u['number'], 'billable_amount' => $u['billable_amount'], 'currency' => $u['currency']], $me, 'by the schedule "' . $cur['description'] . '"');
    $pdo->commit();
    return [$u, $n];
});
[$u, $n] = $out;
ct_done('Ran the schedule: drafted ' . $u['number'] . ' (' . fmt_money($u['billable_amount'], trim((string) $u['currency'])) . '), next ' . $n['next_date'], (int) $u['id'], ct_land(return_path('/settings/schedules/'), 'ran'), 'usageChanged',
    ['schedule_id' => $id, 'usage_charge_id' => (int) $u['id'], 'number' => $u['number'], 'next_date' => $n['next_date'], 'active' => (bool) $n['active'], 'billable_amount' => $u['billable_amount']]);
