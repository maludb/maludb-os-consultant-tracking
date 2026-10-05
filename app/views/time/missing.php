<?php /** Who has not submitted a week (screen `missing-time`). Data: rows, ws, may (nudge), here, notice */ $prev = (new DateTimeImmutable($ws))->modify('-7 days')->format('Y-m-d'); $next = (new DateTimeImmutable($ws))->modify('+7 days')->format('Y-m-d'); ?>
<?= view('shared/header.php', ['id' => 'missing-time', 'title' => 'Missing time', 'crumbs' => [['Home', '/'], ['Missing time', null]]]) ?>
<div class="main-content" id="missing-time-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3"><div class="card-body py-2 d-flex justify-content-between align-items-center gap-2">
        <?= hx_link('/time/missing?week=' . $prev, '<i class="feather-chevron-left"></i>', 'btn btn-light btn-touch', 'id="missing-prev" aria-label="Previous week"') ?>
        <span class="fw-bold" id="missing-week-label"><?= e(week_label($ws)) ?></span>
        <?= hx_link('/time/missing?week=' . $next, '<i class="feather-chevron-right"></i>', 'btn btn-light btn-touch', 'id="missing-next" aria-label="Next week"') ?>
    </div></div>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="missing-empty">Everyone expected to has submitted the <?= e(strtolower(week_label($ws))) ?>.</div></div><?php endif; ?>
    <div class="row g-3" id="missing-list">
        <?php foreach ($rows as $r): ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="missing-<?= (int) $r['member_id'] ?>"><div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start"><div class="fw-bold"><?= e($r['member_name']) ?></div><span class="badge bg-soft-<?= $r['status'] === 'none' ? 'danger text-danger' : ($r['status'] === 'rejected' ? 'warning text-warning' : 'secondary text-secondary') ?>"><?= $r['status'] === 'none' ? 'No week' : e(WEEK_STATUSES[$r['status']] ?? $r['status']) ?></span></div>
                <div class="fs-12 text-muted mt-1" id="missing-<?= (int) $r['member_id'] ?>-facts"><?= e(fmt_hours($r['hours_logged'])) ?> logged of <?= e(days_label($r['capacity_hours'])) ?> capacity · <span class="<?= $r['empty_weekdays'] > 0 ? 'text-danger' : '' ?>"><?= (int) $r['empty_weekdays'] ?> empty weekday<?= (int) $r['empty_weekdays'] === 1 ? '' : 's' ?></span><?= $r['nudged'] ? ' · <span class="badge bg-soft-info text-info">nudged</span>' : '' ?></div>
                <?php if ($may['nudge']): ?><form method="post" action="/time/weeks/nudge.php" hx-post="/time/weeks/nudge.php" hx-target="#flash" class="mt-2" hx-confirm="Nudge <?= e($r['member_name']) ?> about the <?= e(strtolower(week_label($ws))) ?>?"><?= csrf_field() ?><input type="hidden" name="member" value="<?= (int) $r['member_id'] ?>"><input type="hidden" name="week" value="<?= e($ws) ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="missing-<?= (int) $r['member_id'] ?>-nudge-btn" <?= $r['nudged'] ? 'disabled' : '' ?>><?= $r['nudged'] ? 'Nudged' : 'Nudge' ?></button></form><?php endif; ?>
            </div></div></div>
        <?php endforeach; ?>
    </div>
</div>
