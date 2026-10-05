<?php /** The home's Budget alerts region (slice 2). Data: alerts = [open, ending, active] */ $here = '/'; ?>
<div class="card h-100" id="home-budget-alerts"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start"><div class="fw-bold">Budget alerts</div><?= hx_link('/usage/budgets', 'Budgets', 'fs-12', 'id="home-budget-alerts-link"') ?></div>
    <div class="fs-12 text-muted mb-2"><?= (int) $alerts['active'] ?> active engagement<?= (int) $alerts['active'] === 1 ? '' : 's' ?></div>
    <?php if ($alerts['open'] === [] && $alerts['ending'] === []): ?><div class="fs-12 text-muted" id="home-budget-alerts-empty">No open alert; nothing ends within 30 days.</div><?php endif; ?>
    <?php foreach ($alerts['open'] as $a): ?><div class="fs-12 d-flex justify-content-between" id="home-alert-<?= (int) $a['alert_id'] ?>"><span><?= hx_link(with_back('/engagements/' . (int) $a['engagement_id'], $here), e($a['engagement_number'])) ?> <?= e($a['engagement_name'] ?? '') ?></span><span class="badge bg-soft-warning text-warning"><?= e($a['kind']) ?> <?= (int) $a['threshold_pct'] ?>%</span></div><?php endforeach; ?>
    <?php foreach ($alerts['ending'] as $e): ?><div class="fs-12 d-flex justify-content-between" id="home-ending-<?= (int) $e['engagement_id'] ?>"><span><?= hx_link(with_back('/engagements/' . (int) $e['engagement_id'], $here), e($e['number'])) ?> <?= e($e['name']) ?></span><span class="text-muted">ends <?= e(format_date($e['end_date'])) ?></span></div><?php endforeach; ?>
</div></div>
