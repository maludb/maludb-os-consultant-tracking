<?php /** The rate cards as cards (screen `rate-card-list`). Data: rows, withArchived, may, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'rate-card-list', 'title' => 'Rate cards', 'crumbs' => [['Home', '/'], ['Settings', '/settings/general'], ['Rate cards', null]],
    'action' => $may['manage'] ? hx_link('/settings/rate-cards/new', '<i class="feather-plus me-1"></i>Add card', 'btn btn-primary btn-touch', 'id="rate-card-list-add-btn"') : '']) ?>
<div class="main-content" id="rate-card-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="rate-card-list-empty">No rate card yet. A card is a price list: a bill rate per consulting role in one currency.</div></div><?php endif; ?>
    <div class="row g-3" id="rate-card-list-cards">
        <?php foreach ($rows as $r): $id = $r['rate_card_id']; ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="rate-card-<?= $id ?>"><div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="fw-bold min-w-0 text-truncate" id="rate-card-<?= $id ?>-name"><?= hx_link(with_back('/settings/rate-cards/' . $id, $here), e($r['name']), 'text-dark') ?></div>
                    <span class="badge bg-soft-primary text-primary"><?= e($r['currency']) ?></span><?= $r['archived_at'] !== null ? ' <span class="badge bg-soft-secondary text-secondary">Archived</span>' : '' ?>
                </div>
                <div class="fs-12 text-muted mt-1">From <?= e(format_date($r['effective_from'])) ?></div>
                <div class="fs-12 mt-1" id="rate-card-<?= $id ?>-facts"><span class="fw-semibold"><?= $r['rate_count'] ?> rate<?= $r['rate_count'] === 1 ? '' : 's' ?></span> <span class="text-muted">· <?= $r['engagement_count'] ?> engagement<?= $r['engagement_count'] === 1 ? '' : 's' ?> · <?= $r['client_count'] ?> client<?= $r['client_count'] === 1 ? '' : 's' ?></span></div>
                <div class="mt-2"><?= hx_link(with_back('/settings/rate-cards/' . $id, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="rate-card-' . $id . '-open-btn"') ?></div>
            </div></div></div>
        <?php endforeach; ?>
    </div>
    <div class="fs-12 mt-2"><?= hx_link('/settings/rate-cards/' . ($withArchived ? '' : '?include_archived=1'), $withArchived ? 'Hide archived cards' : 'Show archived cards', '', 'id="rate-card-list-archived-link"') ?></div>
</div>
