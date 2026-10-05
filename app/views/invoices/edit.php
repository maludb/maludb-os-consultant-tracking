<?php /** The draft's lines editor (screen `invoice-edit`). Data: i, taxRates, nte, grouping, here, notice */
$id = (int) $i['invoice_id']; $cur = $i['currency']; ?>
<?= view('shared/header.php', ['id' => 'invoice-edit', 'title' => 'Edit the draft · ' . $i['engagement_number'], 'crumbs' => [['Home', '/'], ['Invoices', '/invoices/'], ['Draft #' . $id, '/invoices/' . $id], ['Edit', null]], 'back' => back_link() ?? ['/invoices/' . $id, 'the draft'],
    'action' => hx_link('/invoices/' . $id, 'Done', 'btn btn-primary btn-touch', 'id="invoice-edit-done-btn"')]) ?>
<div class="main-content" id="invoice-edit-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($nte !== null): ?><div class="alert alert-<?= $nte['over'] ? 'warning' : 'info' ?> fs-12" id="invoice-edit-nte">Not-to-exceed <?= e(fmt_money($nte['cap'], $cur)) ?>: <?= e(fmt_money($nte['issued'], $cur)) ?> already invoiced; with this draft <?= e(fmt_money($nte['after'], $cur)) ?><?= $nte['over'] ? ' — above the cap. Issuing it needs Billing, with a reason.' : '.' ?></div><?php endif; ?>
    <?= view('invoices/partials/totals.php', ['i' => $i]) ?>
    <?= view('invoices/partials/lines.php', ['i' => $i, 'here' => $here, 'editable' => true, 'taxRates' => $taxRates]) ?>
    <div class="row g-3">
        <div class="col-lg-6"><div class="card mb-3" id="invoice-edit-add-unbilled"><div class="card-header"><h5 class="card-title mb-0">Pull the unbilled items</h5></div><div class="card-body">
            <p class="fs-12 text-muted mb-2">Approved, billable work not on any invoice, grouped <?= e(TIME_GROUPINGS[$grouping] ?? $grouping) ?>. To regroup, remove the time lines and pull again. Hours with no rate are never billed at zero: they are named and left out.</p>
            <form method="post" action="/invoices/add-unbilled.php" hx-post="/invoices/add-unbilled.php" hx-target="#flash" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="invoice" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <div class="col-7"><label class="form-label fs-12 text-muted" for="invoice-edit-through">Up to</label><input type="date" name="through" id="invoice-edit-through" class="form-control btn-touch" value="<?= e((string) ($i['period_end'] ?? date('Y-m-d'))) ?>"></div>
                <div class="col-5 d-grid"><button type="submit" class="btn btn-primary btn-touch" id="invoice-edit-add-unbilled-btn">Add them</button></div></form>
        </div></div></div>
        <div class="col-lg-6"><div class="card mb-3" id="invoice-edit-add-line"><div class="card-header"><h5 class="card-title mb-0">Add a line</h5></div><div class="card-body">
            <form method="post" action="/invoices/lines/save.php" hx-post="/invoices/lines/save.php" hx-target="#flash" class="row g-2" id="invoice-line-form"><?= csrf_field() ?><input type="hidden" name="invoice" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="invoice-line-field-kind">Kind</label><select name="kind" id="invoice-line-field-kind" class="form-select btn-touch"><?php foreach (['fixed' => 'Fixed — a deliverable at a price', 'adjustment' => 'Adjustment (plus or minus)', 'discount' => 'Discount (taken off)'] as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="invoice-line-field-tax">Tax</label><select name="tax_rate" id="invoice-line-field-tax" class="form-select btn-touch"><option value="">No tax</option><?php foreach ($taxRates as $t): ?><option value="<?= (int) $t['tax_rate_id'] ?>"><?= e($t['name']) ?> (<?= e(days_label($t['rate'])) ?>%)</option><?php endforeach; ?></select></div>
                <div class="col-12"><label class="form-label fs-12 text-muted" for="invoice-line-field-description">Description</label><input type="text" name="description" id="invoice-line-field-description" class="form-control btn-touch" maxlength="500" required></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="invoice-line-field-quantity">Quantity</label><input type="number" step="0.0001" min="0.0001" name="quantity" id="invoice-line-field-quantity" class="form-control btn-touch" value="1"></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="invoice-line-field-unit">Unit</label><input type="text" name="unit" id="invoice-line-field-unit" class="form-control btn-touch" maxlength="20" placeholder="each"></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="invoice-line-field-price">Price (<?= e($cur) ?>)</label><input type="text" inputmode="decimal" name="unit_price" id="invoice-line-field-price" class="form-control btn-touch" required></div>
                <div class="col-12 d-grid"><button type="submit" class="btn btn-light btn-touch" id="invoice-line-save-btn">Add the line</button></div>
            </form>
        </div></div></div>
    </div>
    <div class="card mb-3" id="invoice-edit-head"><div class="card-header"><h5 class="card-title mb-0">The head</h5></div><div class="card-body">
        <form method="post" action="/invoices/save.php" hx-post="/invoices/save.php" hx-target="#flash" class="row g-2" id="invoice-head-form"><?= csrf_field() ?><input type="hidden" name="invoice" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="invoice-head-field-issue">Issue date</label><input type="date" name="issue_date" id="invoice-head-field-issue" class="form-control btn-touch" value="<?= e($i['issue_date']) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="invoice-head-field-due">Due date</label><input type="date" name="due_date" id="invoice-head-field-due" class="form-control btn-touch" value="<?= e($i['due_date']) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="invoice-head-field-ps">Period from</label><input type="date" name="period_start" id="invoice-head-field-ps" class="form-control btn-touch" value="<?= e((string) $i['period_start']) ?>"></div>
            <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="invoice-head-field-pe">Period to</label><input type="date" name="period_end" id="invoice-head-field-pe" class="form-control btn-touch" value="<?= e((string) $i['period_end']) ?>"></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="invoice-head-field-po">PO number</label><input type="text" name="po_number" id="invoice-head-field-po" class="form-control btn-touch" value="<?= e((string) $i['po_number']) ?>" maxlength="200"></div>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="invoice-head-field-memo">Memo to the client</label><textarea name="memo" id="invoice-head-field-memo" class="form-control" rows="2" maxlength="4000"><?= e((string) $i['memo']) ?></textarea></div>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="invoice-head-field-terms">Terms</label><textarea name="terms_text" id="invoice-head-field-terms" class="form-control" rows="2" maxlength="4000"><?= e((string) $i['terms_text']) ?></textarea></div>
            <div class="col-12"><label class="form-label fs-12 text-muted" for="invoice-head-field-note">Internal note</label><textarea name="internal_note" id="invoice-head-field-note" class="form-control" rows="2" maxlength="4000"><?= e((string) $i['internal_note']) ?></textarea></div>
            <div class="col-12 d-grid"><button type="submit" class="btn btn-light btn-touch" id="invoice-head-save-btn">Save the head</button></div>
        </form>
    </div></div>
    <form method="post" action="/invoices/delete.php" hx-post="/invoices/delete.php" hx-target="#flash" hx-confirm="Delete this draft? Its items become unbilled again. No number is lost."><?= csrf_field() ?><input type="hidden" name="invoice" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch text-danger" id="invoice-edit-delete-btn">Delete the draft</button></form>
</div>
