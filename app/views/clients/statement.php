<?php /** The client's statement (screen `client-statement`). Data: c, rows, closing, from, to, here, may, notice */ $id = (int) $c['client_id']; ?>
<?= view('shared/header.php', ['id' => 'client-statement', 'title' => 'Statement', 'crumbs' => [['Home', '/'], ['Clients', '/clients/'], [$c['name'], '/clients/' . $id], ['Statement', null]], 'back' => back_link() ?? ['/clients/' . $id, $c['name']]]) ?>
<div class="main-content" id="client-statement-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/clients/<?= $id ?>/statement" class="card mb-3" id="client-statement-filter-form"><div class="card-body"><div class="row g-2 align-items-end">
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="client-statement-from">From</label><input type="date" name="from" id="client-statement-from" class="form-control btn-touch" value="<?= e((string) $from) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="client-statement-to">To</label><input type="date" name="to" id="client-statement-to" class="form-control btn-touch" value="<?= e((string) $to) ?>"></div>
        <div class="col-12 col-md-2 d-grid"><button type="submit" class="btn btn-primary btn-touch" id="client-statement-btn">Show</button></div>
    </div></div></form>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="client-statement-empty">Nothing on the statement for that period.</div></div><?php else: ?>
    <div class="card mb-3"><div class="table-responsive"><table class="table mb-0 fs-12" id="client-statement-table">
        <thead><tr><th>Date</th><th>What</th><th class="text-end">Charge</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
        <tbody><?php foreach ($rows as $n => $r): $cur = $r['currency']; ?><tr id="statement-row-<?= $n ?>" data-kind="<?= e($r['kind']) ?>"><td class="text-nowrap"><?= e(format_date($r['entry_date'])) ?></td><td><?= e($r['description']) ?></td>
            <?= (float) $r['charge'] > 0 ? fmt_money_cell($r['charge'], $cur) : '<td class="text-end text-muted">—</td>' ?><?= (float) $r['credit'] > 0 ? fmt_money_cell('-' . $r['credit'], $cur) : '<td class="text-end text-muted">—</td>' ?><?= fmt_money_cell($r['running_balance'], $cur, 'statement-row-' . $n . '-balance') ?></tr><?php endforeach; ?></tbody>
    </table></div></div>
    <div class="card mb-3" id="client-statement-closing"><div class="card-body p-3 fs-12"><?php foreach ($closing as $cur => $b): ?><div class="d-flex justify-content-between" id="statement-closing-<?= e($cur) ?>"><span class="fw-semibold">Owed in <?= e($cur) ?></span><strong><?= e(fmt_money($b, (string) $cur)) ?></strong></div><?php endforeach; ?></div></div>
    <?php if ($may['send']): ?>
    <div class="card" id="client-statement-send"><div class="card-header"><h5 class="card-title mb-0">Send it</h5></div><div class="card-body">
        <form method="post" action="/clients/statement-send.php" hx-post="/clients/statement-send.php" hx-target="#flash" hx-confirm="Send this statement to the client?" class="row g-2"><?= csrf_field() ?><input type="hidden" name="client" value="<?= $id ?>"><input type="hidden" name="from" value="<?= e((string) $from) ?>"><input type="hidden" name="to" value="<?= e((string) $to) ?>">
            <div class="col-12 col-md-5"><input type="email" name="to_email" class="form-control btn-touch" placeholder="Another address (else the contacts who receive invoices)" id="client-statement-send-to" aria-label="Address"></div>
            <div class="col-12 col-md-5"><input type="text" name="message" class="form-control btn-touch" maxlength="2000" placeholder="A line for the client (optional)" id="client-statement-send-message" aria-label="Message"></div>
            <div class="col-12 col-md-2 d-grid"><button type="submit" class="btn btn-primary btn-touch" id="client-statement-send-btn">Send</button></div></form>
    </div></div>
    <?php endif; ?>
    <?php endif; ?>
</div>
