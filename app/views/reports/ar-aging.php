<?php /** Who owes us (screen `ar-aging`). Data: rows, totals, asOf, here */ $buckets = ['current_due' => 'Current', 'd1_30' => '1–30', 'd31_60' => '31–60', 'd61_90' => '61–90', 'd90_plus' => '90+']; ?>
<?= view('shared/header.php', ['id' => 'ar-aging', 'title' => 'Who owes us', 'crumbs' => [['Home', '/'], ['Who owes us', null]], 'back' => back_link(),
    'action' => hx_link('/reports/ar-aging?format=csv' . ($asOf ? '&as_of=' . rawurlencode($asOf) : ''), '<i class="feather-download me-1"></i>CSV', 'btn btn-light btn-touch', 'id="ar-aging-csv-btn" hx-boost="false" download')]) ?>
<div class="main-content" id="ar-aging-content">
    <form method="get" action="/reports/ar-aging" class="card mb-3" id="ar-aging-filter-form"><div class="card-body"><div class="row g-2 align-items-end"><div class="col-8 col-md-3"><label class="form-label fs-12 text-muted" for="ar-aging-as-of">As of</label><input type="date" name="as_of" id="ar-aging-as-of" class="form-control btn-touch" value="<?= e((string) $asOf) ?>"></div><div class="col-4 col-md-2 d-grid"><button type="submit" class="btn btn-primary btn-touch" id="ar-aging-btn">Show</button></div></div>
        <div class="fs-12 text-muted mt-2">The open invoices as they stand today, bucketed by how many days past due they are on the date asked.</div></div></form>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="ar-aging-empty">Nobody owes anything: no open invoice.</div></div><?php else: ?>
    <div class="card"><div class="table-responsive"><table class="table mb-0 fs-12" id="ar-aging-table">
        <thead><tr><th>Client</th><?php foreach ($buckets as $l): ?><th class="text-end"><?= e($l) ?></th><?php endforeach; ?><th class="text-end">Total</th><th class="text-end">Invoices</th></tr></thead>
        <tbody><?php foreach ($rows as $a): $cid = (int) $a['client_id']; $cur = $a['currency']; ?><tr id="aging-row-<?= $cid ?>-<?= e($cur) ?>"><td><?= hx_link(with_back('/invoices/?client=' . $cid . '&status=open', $here), e($a['client_name'])) ?> <span class="text-muted"><?= e($cur) ?></span></td>
            <?php foreach (array_keys($buckets) as $k): ?><?= fmt_money_cell($a[$k], $cur, 'aging-' . $cid . '-' . $k) ?><?php endforeach; ?><?= fmt_money_cell($a['total'], $cur, 'aging-' . $cid . '-total') ?><td class="text-end"><?= (int) $a['open_invoices'] ?></td></tr><?php endforeach; ?></tbody>
        <tfoot><?php foreach ($totals as $cur => $t): ?><tr class="fw-bold" id="aging-total-<?= e($cur) ?>"><td>All clients <span class="text-muted"><?= e($cur) ?></span></td><?php foreach (array_keys($buckets) as $k): ?><?= fmt_money_cell($t[$k], $cur) ?><?php endforeach; ?><?= fmt_money_cell($t['total'], $cur, 'aging-total-' . $cur . '-total') ?><td class="text-end"><?= (int) $t['open_invoices'] ?></td></tr><?php endforeach; ?></tfoot>
    </table></div></div>
    <?php endif; ?>
</div>
