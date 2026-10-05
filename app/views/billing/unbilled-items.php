<?php /** The items behind one engagement's WIP (screen `unbilled-items`). Data: eng, items, here, may */ $cur = trim((string) $eng['currency']); $eid = (int) $eng['engagement_id']; ?>
<?= view('shared/header.php', ['id' => 'unbilled-items', 'title' => 'Unbilled · ' . $eng['number'], 'crumbs' => [['Home', '/'], ['Unbilled work', '/billing/unbilled'], [$eng['number'], null]], 'back' => back_link() ?? ['/billing/unbilled', 'unbilled work'],
    'action' => $may['draft'] ? hx_link(with_back('/invoices/new?engagement=' . $eid, $here), 'Draft invoice', 'btn btn-primary btn-touch', 'id="unbilled-items-draft-btn"') : '']) ?>
<div class="main-content" id="unbilled-items-content">
    <div class="fs-12 mb-2"><?= hx_link(with_back('/engagements/' . $eid, $here), e($eng['number'] . ' · ' . $eng['name'])) ?> · <?= hx_link(with_back('/clients/' . (int) $eng['client_id'], $here), e($eng['client_name'])) ?></div>
    <?php if ($items === []): ?><div class="card"><div class="card-body text-muted" id="unbilled-items-empty">Nothing is unbilled on this engagement.</div></div><?php else: ?>
    <div class="card"><div class="table-responsive"><table class="table mb-0 fs-12" id="unbilled-items-table">
        <thead><tr><th>Date</th><th>Kind</th><th>Person</th><th>What</th><th class="text-end">Quantity</th><th class="text-end">Amount</th></tr></thead>
        <tbody><?php foreach ($items as $x): ?><tr id="unbilled-item-<?= e($x['kind']) ?>-<?= (int) $x['item_id'] ?>"<?= $x['no_rate'] ? ' class="table-warning"' : '' ?>>
            <td class="text-nowrap"><?= e(format_date($x['item_date'])) ?></td><td><?= e(LINE_KINDS[$x['kind']] ?? $x['kind']) ?></td><td><?= e((string) $x['member_name']) ?></td><td><?= e((string) $x['description']) ?><?= $x['no_rate'] ? ' <span class="badge bg-soft-warning text-warning">no rate</span>' : '' ?></td>
            <td class="text-end"><?= e(rtrim(rtrim(number_format((float) $x['quantity'], 2, '.', ','), '0'), '.')) ?></td><?= $x['no_rate'] ? '<td class="text-end text-muted">—</td>' : fmt_money_cell($x['amount'], $cur) ?></tr><?php endforeach; ?></tbody>
    </table></div></div>
    <?php endif; ?>
</div>
