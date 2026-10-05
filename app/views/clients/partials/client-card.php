<?php /** One client as a card. Data: c, here, may (billing) */ $id = (int) $c['client_id']; ?>
<div class="card h-100" id="client-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="fw-bold min-w-0 text-truncate" id="client-card-<?= $id ?>-name"><?= hx_link(with_back('/clients/' . $id, $here), e($c['name']), 'text-dark') ?></div>
        <?php if ($c['code']): ?><span class="badge bg-soft-primary text-primary" id="client-card-<?= $id ?>-code"><?= e($c['code']) ?></span><?php endif; ?>
        <?php if ($c['archived_at'] !== null): ?><span class="badge bg-soft-secondary text-secondary" id="client-card-<?= $id ?>-archived">Archived</span><?php endif; ?>
    </div>
    <div class="fs-12 text-muted mt-1" id="client-card-<?= $id ?>-terms"><?= e($c['currency'] ?? 'Base currency') ?> · <?= $c['terms_days'] === null ? 'Default terms' : 'Net ' . (int) $c['terms_days'] ?><?= $c['po_required'] ? ' · <span class="badge bg-soft-warning text-warning">PO</span>' : '' ?></div>
    <div class="fs-12 mt-1" id="client-card-<?= $id ?>-facts"><span class="fw-semibold"><?= (int) $c['active_engagements'] ?> active</span> <span class="text-muted">· <?= (int) $c['engagement_count'] ?> engagement<?= (int) $c['engagement_count'] === 1 ? '' : 's' ?></span>
        <?php if ($may['billing'] && (float) $c['open_balance'] > 0): ?> <span class="text-danger" id="client-card-<?= $id ?>-balance">· <?= e(fmt_money($c['open_balance'], $c['currency'] ?? ct_base_currency())) ?> open</span><?php endif; ?></div>
    <div class="mt-2"><?= hx_link(with_back('/clients/' . $id, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="client-card-' . $id . '-open-btn"') ?></div>
</div></div>
