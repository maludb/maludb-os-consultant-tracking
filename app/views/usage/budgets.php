<?php /** Every active engagement's budgets as bars, and the open alerts (screen `budget-alerts`). Data: rows, alerts, may (check, acknowledge, ai), here, tz, notice */ ?>
<?= view('shared/header.php', ['id' => 'budget-alerts', 'title' => 'Budgets', 'crumbs' => [['Home', '/'], ['Budgets', null]],
    'action' => $may['check'] ? '<form method="post" action="/usage/budgets/check.php" hx-post="/usage/budgets/check.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<button type="submit" class="btn btn-light btn-touch" id="budget-alerts-check-btn"><i class="feather-refresh-cw me-1"></i>Check now</button></form>' : '']) ?>
<div class="main-content" id="budget-alerts-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card mb-3" id="budget-alerts-open"><div class="card-header"><h5 class="card-title mb-0">Open alerts</h5></div>
                <?php if ($alerts === []): ?><div class="card-body text-muted" id="budget-alerts-open-empty">No open alert.</div><?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($alerts as $a): ?><div class="list-group-item fs-12" id="alert-<?= $a['alert_id'] ?>">
                    <div class="d-flex justify-content-between align-items-center gap-2"><span><?= hx_link(with_back('/engagements/' . $a['engagement_id'], $here), e($a['engagement_number'])) ?> <?= e($a['engagement_name']) ?> <span class="badge bg-soft-warning text-warning"><?= e($a['kind']) ?> at <?= (int) $a['threshold_pct'] ?>%</span><br><span class="text-muted"><?= $a['kind'] === 'ai' && !$may['ai'] ? '' : e(days_label($a['value_at'])) . ' of ' . e(days_label($a['budget_at'])) . ' · ' ?><?= e(format_ts($a['written_at'], $tz, 'M j')) ?></span></span>
                    <?php if ($may['acknowledge']): ?><form method="post" action="/usage/budgets/acknowledge.php" hx-post="/usage/budgets/acknowledge.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="alert" value="<?= $a['alert_id'] ?>"><button type="submit" class="btn btn-sm btn-light" id="alert-<?= $a['alert_id'] ?>-ack-btn">Acknowledge</button></form><?php endif; ?></div>
                </div><?php endforeach; ?></div><?php endif; ?>
            </div>
        </div>
        <div class="col-lg-7">
            <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="budget-alerts-empty">No active engagement.</div></div><?php endif; ?>
            <div class="row g-3" id="budget-alerts-cards">
                <?php foreach ($rows as $e): ?><div class="col-12 col-md-6"><div class="card h-100" id="budget-engagement-<?= $e['engagement_id'] ?>"><div class="card-body p-3">
                    <div class="fw-bold text-truncate"><?= hx_link(with_back('/engagements/' . $e['engagement_id'], $here), e($e['number'] . ' · ' . $e['name']), 'text-dark') ?></div>
                    <div class="fs-12 text-muted mb-2"><?= e($e['client_name']) ?></div>
                    <?= view('engagements/partials/budget-bars.php', ['e' => $e, 'may' => $may]) ?>
                </div></div></div><?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
