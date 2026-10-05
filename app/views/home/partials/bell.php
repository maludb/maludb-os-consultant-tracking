<?php /** The home's Notifications region: the bell's last five (everyone's own). Data: rows (find_my_notifications), tz */ ?>
<div class="card mb-3" id="home-bell"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start"><div class="fw-bold">Notifications</div><?= hx_link('/notifications', 'All', 'fs-12', 'id="home-bell-link"') ?></div>
    <?php if ($rows === []): ?><div class="fs-12 text-muted" id="home-bell-empty">Nothing yet — what you are told about appears here and in the bell.</div><?php endif; ?>
    <?php foreach ($rows as $n): $unread = $n['read_at'] === null; ?>
        <div class="fs-12 d-flex justify-content-between gap-2<?= $unread ? ' fw-semibold' : '' ?>" id="home-bell-<?= (int) $n['notification_id'] ?>"><span class="min-w-0"><?= ($u = notification_record_url($n)) !== null ? hx_link(with_back($u, '/'), e($n['title']), 'text-dark') : e($n['title']) ?></span><span class="text-muted text-nowrap fw-normal"><?= e(format_ts($n['created_at'], $tz, 'M j')) ?></span></div>
    <?php endforeach; ?>
</div></div>
