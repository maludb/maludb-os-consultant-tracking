<?php /** The invoices (screen `invoice-list`). Data: rows, totals, counts, status, filters, clients, engagements, here, may, notice */
$f = $filters; $q = static fn (array $over = []): string => '/invoices/?' . http_build_query(array_filter($over + ['q' => $f['q'], 'client' => $f['client_id'], 'engagement' => $f['engagement_id'], 'status' => $status, 'overdue' => $f['overdue'] ? '1' : ''], static fn ($v) => $v !== null && $v !== '')); ?>
<?= view('shared/header.php', ['id' => 'invoice-list', 'title' => 'Invoices', 'crumbs' => [['Home', '/'], ['Invoices', null]],
    'action' => $may['draft'] ? hx_link(with_back('/invoices/new', $here), '<i class="feather-plus me-1"></i>Draft an invoice', 'btn btn-primary btn-touch', 'id="invoice-list-add-btn"') : '']) ?>
<div class="main-content" id="invoice-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex gap-1 mb-3 overflow-auto" id="invoice-list-tabs">
        <?php foreach (['' => 'All', 'open' => 'Open', 'draft' => 'Drafts', 'paid' => 'Paid', 'void' => 'Void'] as $k => $label): ?><?= hx_link($q(['status' => $k, 'overdue' => '']), e($label) . ' <span class="badge bg-soft-secondary text-secondary">' . (int) $counts[$k] . '</span>', 'btn btn-touch ' . (!$f['overdue'] && $status === $k ? 'btn-primary' : 'btn-light'), 'id="invoice-tab-' . ($k === '' ? 'all' : $k) . '"') ?><?php endforeach; ?>
        <?= hx_link($q(['status' => '', 'overdue' => '1']), 'Overdue <span class="badge bg-soft-danger text-danger">' . (int) $counts['overdue'] . '</span>', 'btn btn-touch ' . ($f['overdue'] ? 'btn-primary' : 'btn-light'), 'id="invoice-tab-overdue"') ?>
    </div>
    <form method="get" action="/invoices/" class="card mb-3" id="invoice-filter-form"><div class="card-body"><div class="row g-2 align-items-end">
        <input type="hidden" name="status" value="<?= e($status) ?>"><?php if ($f['overdue']): ?><input type="hidden" name="overdue" value="1"><?php endif; ?>
        <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="invoice-filter-q">Search</label><input type="search" name="q" id="invoice-filter-q" class="form-control btn-touch" value="<?= e($f['q']) ?>" placeholder="Number, client, engagement, PO"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" id="invoice-filter-client-label" for="invoice-filter-client-open">Client</label><?= picker_field(['id' => 'invoice-filter-client', 'labelledby' => 'invoice-filter-client-label', 'name' => 'client', 'source' => 'client', 'value' => $f['client_id'], 'placeholder' => 'All']) ?></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" id="invoice-filter-engagement-label" for="invoice-filter-engagement-open">Engagement</label><?= picker_field(['id' => 'invoice-filter-engagement', 'labelledby' => 'invoice-filter-engagement-label', 'name' => 'engagement', 'source' => 'engagement', 'value' => $f['engagement_id'], 'placeholder' => 'All']) ?></div>
        <div class="col-12 col-md-2 d-grid"><button type="submit" class="btn btn-primary btn-touch" id="invoice-filter-btn">Filter</button></div>
    </div></div></form>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="invoice-list-empty">No invoice here yet<?= $may['draft'] ? '. Draft one from the unbilled work.' : '.' ?></div></div><?php endif; ?>
    <?php if ($rows !== []): ?>
    <div class="card mb-3 d-none d-md-block" id="invoice-list-table"><div class="table-responsive"><table class="table table-hover mb-0 fs-12">
        <thead><tr><th>Number</th><th>Client</th><th>Engagement</th><th>Issued</th><th>Due</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Owed</th><th></th></tr></thead>
        <tbody><?php foreach ($rows as $i): ?><?= view('invoices/partials/invoice-row.php', ['i' => $i, 'here' => $here, 'mode' => 'row']) ?><?php endforeach; ?></tbody>
    </table></div></div>
    <div class="row g-3 d-md-none" id="invoice-list-cards"><?php foreach ($rows as $i): ?><div class="col-12"><?= view('invoices/partials/invoice-row.php', ['i' => $i, 'here' => $here, 'mode' => 'card']) ?></div><?php endforeach; ?></div>
    <div class="card mt-3" id="invoice-list-totals"><div class="card-body p-3">
        <?php foreach ($totals as $cur => $t): ?><div class="d-flex flex-wrap justify-content-between gap-2 fs-12" id="invoice-total-<?= e($cur) ?>"><span class="fw-semibold"><?= (int) $t['count'] ?> invoice<?= $t['count'] === 1 ? '' : 's' ?> in <?= e($cur) ?></span><span>Total <strong><?= e(fmt_money($t['total'], $cur)) ?></strong> · Paid <strong><?= e(fmt_money($t['paid'], $cur)) ?></strong> · Owed <strong><?= e(fmt_money($t['balance'], $cur)) ?></strong></span></div><?php endforeach; ?>
    </div></div>
    <?php endif; ?>
</div>
