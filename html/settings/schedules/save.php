<?php
declare(strict_types=1);
/**
 * Action `recurring_charge_save` (log `recurring_charge.save`: engagement_id, kind, quantity, unit, unit_cost, amount, currency, pricing, cadence, every_n, next_date, end_date, active; `other` for agents): usage.write + manage of the engagement.
 * A schedule is drafted from on its next date (a run makes one draft charge for the period it covers); an end date deactivates it after the last run.
 */
require_once dirname(__DIR__, 3) . '/app/features/usage/handler.php';
ct_handler_begin();
require_right('usage.write');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('schedule') ?? request_integer('schedule_id');
$cur = null;
if ($id !== null) {
    $cur = find_schedule($pdo, $id) ?? refuse(404, 'Schedule not found.');
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
$kind = $cur['kind'] ?? 'hosting';
if (req_has('kind') || $cur === null) {
    $kind = req_has('kind') ? (string) req_val('kind') : 'hosting';
    if (!isset(USAGE_KINDS[$kind])) {
        $errors['kind'] = 'The kind is model_usage, api, hosting, storage or other.';
    }
}
$provField = req_has('provider') ? 'provider' : 'provider_id';
$provider = ct_ref($pdo, $provField, $cur['provider_id'] ?? null, 'SELECT 1 FROM usage_providers WHERE id = :id AND archived_at IS NULL', 'provider', $errors, true);
$description = $cur['description'] ?? null;
if (req_has('description') || $cur === null) {
    $description = mb_substr(trim((string) req_val('description')), 0, 500);
    if ($description === '') {
        $errors['description'] = 'Say what it is (for example "Acme VM, monthly").';
    }
}
$quantity = ct_decimal('quantity', $cur['quantity'] ?? '1.0000', 0.0001, 99999999999999, 'The quantity', $errors, false, 4);
$unit = $cur['unit'] ?? 'months';
if (req_has('unit')) {
    $unit = (string) req_val('unit');
    if (!isset(USAGE_UNITS[$unit])) {
        $errors['unit'] = 'The unit is tokens, calls, gb, gb_month, vm_hours, months or units.';
    }
}
$unitCost = ct_decimal('unit_cost', $cur['unit_cost'] ?? null, 0, 99999999, 'The unit cost', $errors, false, 6);
if ($unitCost === null && !isset($errors['unit_cost'])) {
    $errors['unit_cost'] = 'The unit cost is required (what one ' . ($unit === 'months' ? 'month' : 'unit') . ' costs you).';
}
[$pricing, $markup, $resale] = ct_pricing_fields($cur, $errors);
$cadence = $cur['cadence'] ?? 'monthly';
if (req_has('cadence')) {
    $cadence = (string) req_val('cadence');
    if (!isset(CADENCES[$cadence])) {
        $errors['cadence'] = 'The cadence is weekly, monthly, quarterly or yearly.';
    }
}
$every = ct_int('every_n', $cur['every_n'] ?? 1, 1, 24, 'Every', $errors);
$next = ct_date('next_date', $cur['next_date'] ?? null, 'The next date', $errors);
if ($next === null && !isset($errors['next_date'])) {
    $errors['next_date'] = 'The next date is the first day of the period to charge (2026-10-01).';
}
$end = ct_date('end_date', $cur['end_date'] ?? null, 'The end date', $errors, true);
if ($next !== null && $end !== null && $end < $next) {
    $errors['end_date'] = 'The end date is on or after the next date.';
}
$active = req_has('active') ? ct_yes('active') : ($cur['active'] ?? true);
if ($errors !== []) {
    ct_refuse_fields($errors);
}
$f = ['engagement_id' => $engagement, 'kind' => $kind, 'provider_id' => $provider, 'description' => $description, 'quantity' => $quantity, 'unit' => $unit, 'unit_cost' => $unitCost, 'pricing' => $pricing, 'markup_pct' => $markup,
      'resale_unit_price' => $resale, 'cadence' => $cadence, 'every_n' => $every, 'next_date' => $next, 'end_date' => $end, 'active' => $active];
$loggable = static fn (array $r): array => ['engagement_id' => (int) $r['engagement_id'], 'kind' => $r['kind'], 'quantity' => $r['quantity'], 'unit' => $r['unit'], 'unit_cost' => $r['unit_cost'], 'pricing' => $r['pricing'], 'markup_pct' => $r['markup_pct'],
    'resale_unit_price' => $r['resale_unit_price'], 'cadence' => $r['cadence'], 'every_n' => (int) $r['every_n'], 'next_date' => $r['next_date'], 'end_date' => $r['end_date'], 'active' => (bool) $r['active']];
$newId = ct_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f, $loggable): int {
    $pdo->beginTransaction();
    $newId = save_schedule($pdo, $id, $f, $me);
    $after = find_schedule($pdo, $newId) ?? throw new DomainException('Not found.');
    $money = ['amount' => number_format((float) $after['quantity'] * (float) $after['unit_cost'], 2, '.', ''), 'currency' => trim((string) one_value($pdo, 'SELECT currency FROM engagements WHERE id = :e', ['e' => $after['engagement_id']])), 'schedule_id' => $newId];
    if ($cur === null) {
        log_activity($pdo, 'recurring_charge.save', 'recurring_charge', $newId, ['engagement_id' => $after['engagement_id'], 'after' => $loggable($after) + $money]);
    } else {
        $d = ct_diff($loggable($cur), $loggable($after));
        if ($d['after'] !== [] || $cur['description'] !== $after['description']) {
            log_activity($pdo, 'recurring_charge.save', 'recurring_charge', $newId, ['engagement_id' => $after['engagement_id'], 'before' => $d['before'], 'after' => $d['after'] + $money]);
        }
    }
    $pdo->commit();
    return $newId;
});
ct_done(($id === null ? 'Added the schedule ' : 'Saved the schedule ') . $f['description'], $newId, ct_land(return_path('/settings/schedules/'), 'saved'), 'usageChanged', ['schedule_id' => $newId]);
