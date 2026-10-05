<?php /** Unbilled work (screen `unbilled`). Data: rows, totals, asOf, client, clients, here, may */ ?>
<?= view('shared/header.php', ['id' => 'unbilled', 'title' => 'Unbilled work', 'crumbs' => [['Home', '/'], ['Unbilled work', null]], 'action' => hx_link(with_back('/billing/due', $here), 'Due to draft', 'btn btn-light btn-touch', 'id="unbilled-due-btn"')]) ?>
<div class="main-content" id="unbilled-content">
    <form method="get" action="/billing/unbilled" class="card mb-3" id="unbilled-filter-form"><div class="card-body"><div class="row g-2 align-items-end">
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="unbilled-filter-as-of">As of</label><input type="date" name="as_of" id="unbilled-filter-as-of" class="form-control btn-touch" value="<?= e((string) $asOf) ?>"></div>
        <div class="col-6 col-md-4"><label class="form-label fs-12 text-muted" for="unbilled-filter-client">Client</label><select name="client" id="unbilled-filter-client" class="form-select btn-touch"><option value="">All</option><?php foreach ($clients as $c): ?><option value="<?= (int) $c['client_id'] ?>" <?= $client === (int) $c['client_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-2 d-grid"><button type="submit" class="btn btn-primary btn-touch" id="unbilled-filter-btn">Show</button></div>
    </div></div></form>
    <?php if ($totals !== []): ?><div class="alert alert-info fs-12" id="unbilled-totals">Approved and not yet billed: <?php foreach ($totals as $cur => $t): ?><strong id="unbilled-total-<?= e($cur) ?>"><?= e(fmt_money($t, $cur)) ?></strong> <?php endforeach; ?></div><?php endif; ?>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="unbilled-empty">Nothing is waiting: every approved item is on an invoice.</div></div><?php endif; ?>
    <div class="row g-3" id="unbilled-cards">
    <?php foreach ($rows as $u): $eid = (int) $u['engagement_id']; $cur = $u['currency']; ?>
        <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="unbilled-card-<?= $eid ?>"><div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start gap-2"><div class="min-w-0"><div class="fw-bold text-truncate"><?= hx_link(with_back('/billing/unbilled/' . $eid, $here), e($u['engagement_number'] . ' · ' . $u['engagement_name']), 'text-dark', 'id="unbilled-card-' . $eid . '-link"') ?></div><div class="fs-12 text-muted text-truncate"><?= hx_link(with_back('/clients/' . (int) $u['client_id'], $here), e($u['client_name'])) ?></div></div>
                <div class="fw-bold text-end" id="unbilled-card-<?= $eid ?>-total"><?= e(fmt_money($u['total_amount'], $cur)) ?></div></div>
            <div class="fs-12 mt-2" id="unbilled-card-<?= $eid ?>-parts">
                <div class="d-flex justify-content-between"><span>Time <?= e(fmt_hours($u['time_hours'])) ?></span><span><?= e(fmt_money($u['time_amount'], $cur)) ?></span></div>
                <div class="d-flex justify-content-between"><span>Expenses</span><span><?= e(fmt_money($u['expense_amount'], $cur)) ?></span></div>
                <div class="d-flex justify-content-between"><span>Usage</span><span><?= e(fmt_money($u['usage_amount'], $cur)) ?></span></div>
                <div class="d-flex justify-content-between"><span>Milestones</span><span><?= e(fmt_money($u['milestone_amount'], $cur)) ?></span></div>
            </div>
            <?php if ((float) $u['time_no_rate_hours'] > 0): ?><div class="alert alert-warning fs-12 mt-2 mb-0 p-2" id="unbilled-card-<?= $eid ?>-warning"><?= e(fmt_hours($u['time_no_rate_hours'])) ?> have no rate — set one and re-approve; they are not counted.</div><?php endif; ?>
            <div class="d-flex justify-content-between align-items-center mt-2"><span class="fs-12 text-muted">Oldest <?= $u['oldest_item'] ? e(format_date($u['oldest_item'])) : '—' ?></span>
                <?= $may['draft'] ? hx_link(with_back('/invoices/new?engagement=' . $eid . ($asOf ? '&to=' . rawurlencode($asOf) : ''), $here), 'Draft invoice', 'btn btn-primary btn-touch', 'id="unbilled-card-' . $eid . '-draft-btn"') : '' ?></div>
        </div></div></div>
    <?php endforeach; ?>
    </div>
</div>
