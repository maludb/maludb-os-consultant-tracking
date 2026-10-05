<?php /** An invoice's lines as a TABLE (the money of each line is the database's). Data: i (find_invoice), here, editable? (the draft's editor), taxRates? */
$cur = $i['currency']; $editable = $editable ?? false; $taxRates = $taxRates ?? []; ?>
<div class="card mb-3" id="invoice-lines"><div class="card-header"><h5 class="card-title mb-0">Lines</h5></div>
<?php if ($i['lines'] === []): ?><div class="card-body text-muted" id="invoice-lines-empty">No line yet<?= $editable ? ': add the unbilled items, or a line of your own' : '' ?>.</div><?php else: ?>
<div class="table-responsive"><table class="table mb-0 fs-12" id="invoice-lines-table">
    <thead><tr><th>What</th><th class="text-end">Quantity</th><th class="text-end">Price</th><th class="text-end">Tax</th><th class="text-end">Total</th><?= $editable ? '<th></th>' : '' ?></tr></thead>
    <tbody>
    <?php foreach ($i['lines'] as $l): $lid = (int) $l['line_id']; ?>
        <tr id="line-<?= $lid ?>">
            <td><span class="badge bg-soft-secondary text-secondary me-1" title="<?= e(LINE_KINDS[$l['kind']]) ?>" id="line-<?= $lid ?>-kind"><i class="<?= e(LINE_KIND_ICONS[$l['kind']]) ?> me-1"></i><?= e(LINE_KINDS[$l['kind']]) ?></span><span id="line-<?= $lid ?>-description"><?= e($l['description']) ?></span>
                <?= view('invoices/partials/line-items.php', ['l' => $l, 'i' => $i, 'editable' => $editable, 'here' => $here]) ?></td>
            <td class="text-end text-nowrap"><?= e(rtrim(rtrim(number_format((float) $l['quantity'], 4, '.', ','), '0'), '.')) ?><?= $l['unit'] ? ' ' . e($l['unit']) : '' ?></td>
            <?= fmt_money_cell($l['unit_price'], $cur) ?>
            <td class="text-end text-nowrap"><?= (float) $l['tax_rate'] > 0 ? e(days_label($l['tax_rate'])) . '% · ' . e(fmt_money($l['line_tax'], $cur)) : '—' ?></td>
            <?= fmt_money_cell($l['line_total'], $cur, 'line-' . $lid . '-total') ?>
            <?php if ($editable): ?><td class="text-end text-nowrap"><a href="#line-<?= $lid ?>-edit" class="btn btn-sm btn-light" id="line-<?= $lid ?>-edit-link">Change</a>
                <form method="post" action="/invoices/lines/remove.php" hx-post="/invoices/lines/remove.php" hx-target="#flash" hx-confirm="Remove this line<?= (int) $l['item_count'] > 0 ? '? Its ' . (int) $l['item_count'] . ' item(s) become unbilled again' : '' ?>?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="line" value="<?= $lid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-sm btn-light text-danger" id="line-<?= $lid ?>-remove-btn">Remove</button></form></td><?php endif; ?>
        </tr>
        <?php if ($editable): ?><tr class="bg-light" id="line-<?= $lid ?>-edit"><td colspan="6"><form method="post" action="/invoices/lines/save.php" hx-post="/invoices/lines/save.php" hx-target="#flash" class="row g-2 align-items-end" id="line-<?= $lid ?>-form">
            <?= csrf_field() ?><input type="hidden" name="invoice" value="<?= (int) $i['invoice_id'] ?>"><input type="hidden" name="line" value="<?= $lid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><input type="hidden" name="kind" value="<?= e($l['kind']) ?>">
            <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="line-<?= $lid ?>-f-description">Description</label><input type="text" name="description" id="line-<?= $lid ?>-f-description" class="form-control btn-touch" maxlength="500" value="<?= e($l['description']) ?>" required></div>
            <?php $backed = (int) $l['item_count'] > 0; ?>
            <div class="col-4 col-md-1"><label class="form-label fs-12 text-muted" for="line-<?= $lid ?>-f-quantity">Qty</label><input type="number" step="0.0001" min="0.0001" name="quantity" id="line-<?= $lid ?>-f-quantity" class="form-control btn-touch" value="<?= e(rtrim(rtrim((string) $l['quantity'], '0'), '.')) ?>" <?= $backed ? 'readonly' : '' ?>></div>
            <div class="col-4 col-md-2"><label class="form-label fs-12 text-muted" for="line-<?= $lid ?>-f-price">Price</label><input type="text" inputmode="decimal" name="unit_price" id="line-<?= $lid ?>-f-price" class="form-control btn-touch" value="<?= e(abs((float) $l['unit_price']) == (float) $l['unit_price'] ? $l['unit_price'] : $l['unit_price']) ?>" <?= $backed ? 'readonly' : '' ?>></div>
            <div class="col-4 col-md-2"><label class="form-label fs-12 text-muted" for="line-<?= $lid ?>-f-tax">Tax</label><select name="tax_rate" id="line-<?= $lid ?>-f-tax" class="form-select btn-touch"><option value="">No tax</option><?php foreach ($taxRates as $t): ?><option value="<?= (int) $t['tax_rate_id'] ?>" <?= $l['tax_rate_id'] === (int) $t['tax_rate_id'] ? 'selected' : '' ?>><?= e($t['name']) ?> (<?= e(days_label($t['rate'])) ?>%)</option><?php endforeach; ?></select></div>
            <div class="col-6 col-md-1"><label class="form-label fs-12 text-muted" for="line-<?= $lid ?>-f-order">Place</label><input type="number" min="0" name="sort_order" id="line-<?= $lid ?>-f-order" class="form-control btn-touch" value="<?= (int) $l['sort_order'] ?>"></div>
            <div class="col-6 col-md-2 d-grid"><button type="submit" class="btn btn-light btn-touch" id="line-<?= $lid ?>-save-btn">Save the line</button></div>
        </form></td></tr><?php endif; ?>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
</div>
