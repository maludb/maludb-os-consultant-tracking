<?php /** One invoice as a table row or a card. Data: i, here, mode ('row'|'card') */
$id = (int) $i['invoice_id']; $cur = $i['currency']; $label = $i['number'] ?? 'Draft #' . $id; $url = with_back('/invoices/' . $id, $here);
if (($mode ?? 'row') === 'row'): ?>
<tr id="invoice-row-<?= $id ?>">
    <td><?= hx_link($url, e($label), 'fw-semibold text-dark', 'id="invoice-row-' . $id . '-number"') ?></td>
    <td><?= hx_link(with_back('/clients/' . (int) $i['client_id'], $here), e($i['client_name'])) ?></td>
    <td><?= hx_link(with_back('/engagements/' . (int) $i['engagement_id'], $here), e($i['engagement_number'])) ?></td>
    <td class="text-nowrap"><?= e(format_date($i['issue_date'])) ?></td><td class="text-nowrap"><?= e(format_date($i['due_date'])) ?></td>
    <?= fmt_money_cell($i['total'], $cur, 'invoice-row-' . $id . '-total') ?><?= fmt_money_cell($i['amount_paid'], $cur) ?><?= fmt_money_cell($i['balance_due'], $cur, 'invoice-row-' . $id . '-balance') ?>
    <td><?= view('invoices/partials/chips.php', ['i' => $i, 'id' => 'invoice-row-' . $id]) ?></td>
</tr>
<?php else: ?>
<div class="card h-100" id="invoice-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0"><div class="fw-bold text-truncate"><?= hx_link($url, e($label), 'text-dark', 'id="invoice-card-' . $id . '-number"') ?></div>
            <div class="fs-12 text-truncate"><?= hx_link(with_back('/clients/' . (int) $i['client_id'], $here), e($i['client_name'])) ?> · <?= hx_link(with_back('/engagements/' . (int) $i['engagement_id'], $here), e($i['engagement_number'])) ?></div>
            <div class="fs-12 text-muted">Issued <?= e(format_date($i['issue_date'])) ?> · due <?= e(format_date($i['due_date'])) ?></div></div>
        <div class="text-end flex-shrink-0"><div class="fw-bold" id="invoice-card-<?= $id ?>-total"><?= e(fmt_money($i['total'], $cur)) ?></div>
            <?php if ($i['status'] !== 'draft' && $i['status'] !== 'void'): ?><div class="fs-12 text-muted" id="invoice-card-<?= $id ?>-balance">owed <?= e(fmt_money($i['balance_due'], $cur)) ?></div><?php endif; ?></div>
    </div>
    <div class="d-flex flex-wrap gap-1 mt-2"><?= view('invoices/partials/chips.php', ['i' => $i, 'id' => 'invoice-card-' . $id]) ?></div>
</div></div>
<?php endif; ?>
