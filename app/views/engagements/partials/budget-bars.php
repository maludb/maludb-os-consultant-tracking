<?php /** The four budget bars of an engagement: hours, fee/soft budget, NTE (invoiced), AI (cost — the amount only under cost.view or usage.write). Data: e (with budget), may (ai), compact? */
$b = $e['budget'] ?? null; $compact = $compact ?? false; $cur = $e['currency'];
$tone = static fn (?string $pct): string => $pct === null ? 'secondary' : ((float) $pct >= 100 ? 'danger' : ((float) $pct >= 80 ? 'warning' : ((float) $pct >= 50 ? 'info' : 'success')));
$bar = static function (string $key, string $label, ?string $used, ?string $budget, ?string $pct, string $unit) use ($tone, $e, $compact): string {
    if ($budget === null || (float) $budget <= 0) { return $compact ? '' : '<div class="fs-12 text-muted mb-2" id="budget-' . $key . '-' . (int) $e['engagement_id'] . '">' . e($label) . ': not set</div>'; }
    $t = $tone($pct);
    return '<div class="mb-2" id="budget-' . $key . '-' . (int) $e['engagement_id'] . '"><div class="d-flex justify-content-between fs-12"><span>' . e($label) . '</span><span class="fw-semibold">' . e($used) . ' of ' . e($budget) . ' ' . e($unit) . ' · ' . e((string) $pct) . '%</span></div>'
        . '<div class="progress" style="height: 6px" role="progressbar" aria-valuenow="' . e((string) min(100, (float) $pct)) . '" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-' . $t . '" style="width: ' . min(100, (float) $pct) . '%"></div></div></div>';
};
if ($b === null): ?><div class="fs-12 text-muted" id="budget-bars-<?= (int) $e['engagement_id'] ?>-none">No budget figures.</div><?php else: ?>
<div id="budget-bars-<?= (int) $e['engagement_id'] ?>">
    <?= $bar('hours', 'Hours', fmt_hours($b['hours_used']), days_label($b['budget_hours'] ?? 0), $b['hours_pct'], '') ?>
    <?= $bar('amount', $e['kind'] === 'fixed_bid' ? 'Fee' : 'Budget', fmt_money($b['amount_used'], $cur, false), $b['budget_amount'] === null ? null : fmt_money($b['budget_amount'], $cur, false), $b['amount_pct'], $cur) ?>
    <?= $e['kind'] === 'fixed_bid' ? '' : $bar('nte', 'Not to exceed (invoiced)', fmt_money($b['invoiced_total'], $cur, false), $b['nte_amount'] === null ? null : fmt_money($b['nte_amount'], $cur, false), $b['nte_pct'], $cur) ?>
    <?php if ($may['ai']): ?><?= $bar('ai', 'AI and hosting (at cost)', fmt_money($b['ai_cost'], $cur, false), $b['ai_budget_amount'] === null ? null : fmt_money($b['ai_budget_amount'], $cur, false), $b['ai_pct'], $cur) ?>
    <?php elseif (!$compact): ?><div class="fs-12 text-muted mb-2" id="budget-ai-<?= (int) $e['engagement_id'] ?>">AI and hosting budget: <?= $b['ai_budget_amount'] === null ? 'not set' : 'set' ?></div><?php endif; ?>
    <?php if (!$compact && $b['pace_hours_per_week'] !== null && (float) $b['pace_hours_per_week'] > 0): ?><div class="fs-12 text-muted" id="budget-pace-<?= (int) $e['engagement_id'] ?>">Pace <?= e(fmt_hours($b['pace_hours_per_week'])) ?> a week<?= $b['weeks_left_at_pace'] !== null ? ' · ' . e(days_label($b['weeks_left_at_pace'])) . ' weeks of hours left at this pace' : '' ?></div><?php endif; ?>
</div>
<?php endif; ?>
