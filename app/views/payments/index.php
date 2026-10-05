<?php /** Payments recorded (screen `payment-list`). Data: rows, totals, filters, clients, here, notice */ $f = $filters; ?>
<?= view('shared/header.php', ['id' => 'payment-list', 'title' => 'Payments', 'crumbs' => [['Home', '/'], ['Payments', null]]]) ?>
<div class="main-content" id="payment-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/payments/" class="card mb-3" id="payment-filter-form"><div class="card-body"><div class="row g-2 align-items-end">
        <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="payment-filter-client">Client</label><select name="client" id="payment-filter-client" class="form-select btn-touch"><option value="">All</option><?php foreach ($clients as $c): ?><option value="<?= (int) $c['client_id'] ?>" <?= $f['client_id'] === (int) $c['client_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="payment-filter-from">From</label><input type="date" name="from" id="payment-filter-from" class="form-control btn-touch" value="<?= e((string) $f['from']) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="payment-filter-to">To</label><input type="date" name="to" id="payment-filter-to" class="form-control btn-touch" value="<?= e((string) $f['to']) ?>"></div>
        <div class="col-12 col-md-2 d-grid"><button type="submit" class="btn btn-primary btn-touch" id="payment-filter-btn">Filter</button></div>
    </div></div></form>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="payment-list-empty">No payment recorded yet.</div></div><?php endif; ?>
    <?php if ($rows !== []): ?><div class="card"><div class="table-responsive"><table class="table mb-0 fs-12" id="payment-list-table">
        <thead><tr><th>Paid</th><th>Invoice</th><th>Client</th><th>Method</th><th>Source</th><th class="text-end">Amount</th><th></th></tr></thead>
        <tbody><?php foreach ($rows as $p): $pid = (int) $p['payment_id']; ?><tr id="payment-<?= $pid ?>"<?= $p['status'] === 'void' ? ' class="text-muted"' : '' ?>>
            <td class="text-nowrap"><?= e(format_date($p['paid_on'])) ?></td><td><?= hx_link(with_back('/invoices/' . (int) $p['invoice_id'], $here), e((string) $p['invoice_number'])) ?></td><td><?= hx_link(with_back('/clients/' . (int) $p['client_id'], $here), e($p['client_name'])) ?></td>
            <td><?= e(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?><?= $p['reference'] ? ' · ' . e($p['reference']) : '' ?></td><td><?= e(PAYMENT_SOURCES[$p['source']] ?? $p['source']) ?></td>
            <?= fmt_money_cell($p['amount'], $p['currency'], 'payment-' . $pid . '-amount') ?>
            <td class="text-end"><?php if ($p['status'] === 'void'): ?><span class="badge bg-dark" id="payment-<?= $pid ?>-void-badge">Void</span><?php else: ?>
                <form method="post" action="/payments/void.php" hx-post="/payments/void.php" hx-target="#flash" class="d-flex gap-1 justify-content-end" id="payment-<?= $pid ?>-void-form"><?= csrf_field() ?><input type="hidden" name="payment" value="<?= $pid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><input type="text" name="reason" class="form-control form-control-sm" style="max-width: 160px" placeholder="Why void it" maxlength="1000" required id="payment-<?= $pid ?>-void-reason" aria-label="Reason"><button type="submit" class="btn btn-sm btn-light text-danger" id="payment-<?= $pid ?>-void-btn">Void</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody>
    </table></div></div>
    <div class="card mt-3" id="payment-list-totals"><div class="card-body p-3 fs-12"><?php foreach ($totals as $cur => $t): ?><div class="d-flex justify-content-between" id="payment-total-<?= e($cur) ?>"><span class="fw-semibold">Recorded in <?= e($cur) ?></span><strong><?= e(fmt_money($t, $cur)) ?></strong></div><?php endforeach; ?></div></div>
    <?php endif; ?>
</div>
