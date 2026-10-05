<?php /** The home's My week region (slice 3). Data: week, timer, tz */ $tone = ['open' => 'secondary', 'submitted' => 'info', 'approved' => 'success', 'rejected' => 'warning'][$week['status']] ?? 'secondary'; ?>
<div class="card h-100" id="home-my-week"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start"><div class="fw-bold">My week</div><?= hx_link('/time/?week=' . $week['week_start'], 'Open', 'fs-12', 'id="home-my-week-link"') ?></div>
    <div class="fs-20 fw-bold" id="home-my-week-hours"><?= e(fmt_hours($week['total_hours'])) ?> <span class="fs-12 fw-normal text-muted">· <?= e(days_label($week['billable_hours'])) ?> billable</span></div>
    <div class="fs-12"><span class="badge bg-soft-<?= $tone ?> text-<?= $tone ?>" id="home-my-week-status"><?= e(WEEK_STATUSES[$week['status']]) ?></span> <span class="text-muted"><?= e($week['label']) ?></span></div>
    <?php if ($week['status'] === 'rejected'): ?><div class="fs-12 text-warning mt-1" id="home-my-week-rejected">Sent back: <?= e((string) $week['rejection_reason']) ?></div><?php endif; ?>
    <?php if (($week['last_week']['status'] ?? 'open') === 'open' && ($week['last_week'] !== null && (float) $week['last_week']['total_hours'] > 0)): ?><div class="fs-12 text-danger mt-1" id="home-my-week-last">Last week is not submitted. <?= hx_link('/time/?week=' . $week['last_week']['week_start'], 'Submit it') ?></div><?php endif; ?>
    <?php if ($timer !== null): ?><div class="fs-12 mt-2" id="home-my-week-timer"><i class="feather-clock text-primary me-1"></i>Timer on <?= e($timer['engagement_number']) ?> since <?= e(format_ts($timer['started_at'], $tz, 'g:i A')) ?></div><?php endif; ?>
</div></div>
