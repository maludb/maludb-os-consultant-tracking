<?php /** Credit notes (screen `credit-note-list`). Data: rows, status, client, clients, here, may, notice */
$q = static fn (array $o = []): string => '/credit-notes/?' . http_build_query(array_filter($o + ['status' => $status, 'client' => $client], static fn ($v) => $v !== null && $v !== '')); ?>
<?= view('shared/header.php', ['id' => 'credit-note-list', 'title' => 'Credit notes', 'crumbs' => [['Home', '/'], ['Credit notes', null]],
    'action' => $may['draft'] ? hx_link(with_back('/credit-notes/new', $here), '<i class="feather-plus me-1"></i>Draft a credit note', 'btn btn-primary btn-touch', 'id="credit-note-list-add-btn"') : '']) ?>
<div class="main-content" id="credit-note-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex gap-1 mb-3 overflow-auto" id="credit-note-list-tabs"><?= hx_link($q(['status' => '']), 'All', 'btn btn-touch ' . ($status === '' ? 'btn-primary' : 'btn-light'), 'id="credit-tab-all"') ?><?php foreach (CREDIT_STATUSES as $k => $l): ?><?= hx_link($q(['status' => $k]), e($l), 'btn btn-touch ' . ($status === $k ? 'btn-primary' : 'btn-light'), 'id="credit-tab-' . $k . '"') ?><?php endforeach; ?></div>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="credit-note-list-empty">No credit note yet. A credit corrects an invoice after it is issued.</div></div><?php endif; ?>
    <div class="row g-3" id="credit-note-list-cards">
    <?php foreach ($rows as $c): $cid = (int) $c['credit_note_id']; $cur = $c['currency']; $tone = CREDIT_TONES[$c['status']]; ?>
        <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="credit-card-<?= $cid ?>"><div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start gap-2"><div class="min-w-0"><div class="fw-bold text-truncate"><?= hx_link(with_back('/credit-notes/' . $cid, $here), e($c['number'] ?? 'Draft #' . $cid), 'text-dark', 'id="credit-card-' . $cid . '-number"') ?></div>
                <div class="fs-12 text-muted text-truncate"><?= hx_link(with_back('/clients/' . (int) $c['client_id'], $here), e($c['client_name'])) ?><?= $c['invoice_number'] ? ' · for ' . hx_link(with_back('/invoices/' . (int) $c['invoice_id'], $here), e($c['invoice_number'])) : '' ?></div></div>
                <div class="text-end"><div class="fw-bold" id="credit-card-<?= $cid ?>-total"><?= e(fmt_money($c['total'], $cur)) ?></div><span class="badge bg-soft-<?= $tone ?> text-<?= $tone ?>" id="credit-card-<?= $cid ?>-status"><?= e(CREDIT_STATUSES[$c['status']]) ?></span></div></div>
            <?php if ($c['status'] === 'issued'): ?><div class="fs-12 mt-1" id="credit-card-<?= $cid ?>-left">Applied <?= e(fmt_money($c['amount_applied'], $cur)) ?> · left <?= e(fmt_money($c['balance'], $cur)) ?></div><?php endif; ?>
            <?php if ($c['reason']): ?><div class="fs-12 text-muted text-truncate mt-1"><?= e($c['reason']) ?></div><?php endif; ?>
        </div></div></div>
    <?php endforeach; ?>
    </div>
</div>
