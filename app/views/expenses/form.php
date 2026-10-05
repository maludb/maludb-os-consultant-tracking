<?php /** Record or change an expense (screens `expense-add`, `expense-edit`). Data: cur, member, me, who, date, engagements, categories, prefill, settings, mayMarkup, here */
$id = $cur['expense_id'] ?? null; $screen = $cur === null ? 'expense-add' : 'expense-edit'; $selected = $cur['engagement_id'] ?? $prefill; $thr = $settings['receipt_threshold'];
$back = $cur === null ? ['/expenses/' . ($who === null ? '' : '?member=' . $member), 'my expenses'] : ['/expenses/' . $id, 'the expense']; $paid = $cur['paid_with'] ?? 'personal';
$cats = array_map(static fn (array $c): array => ['id' => $c['category_id'], 'receipt' => $c['receipt_required_above'], 'markup' => $c['default_markup_pct'], 'billable' => $c['billable_default']], $categories);
$engs = array_map(static fn (array $e): array => ['id' => $e['engagement_id'], 'receipt' => $e['policy_receipt'], 'markup' => $e['policy_markup'], 'currency' => $e['currency']], $engagements); ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Record an expense' : 'Change ' . $cur['number'], 'crumbs' => [['Home', '/'], ['My expenses', '/expenses/'], [$cur === null ? 'Record' : 'Change', null]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <?php if ($who !== null): ?><div class="alert alert-info fs-12" id="expense-form-for">Recording for <strong><?= e($who) ?></strong>; the expense is theirs, and the log says you recorded it.</div><?php endif; ?>
    <?php if ($engagements === []): ?><div class="alert alert-info" id="expense-form-unstaffed"><?= $who === null ? 'You are' : e($who) . ' is' ?> staffed on no active engagement on <?= e(format_date($date)) ?>. Ask the engagement's manager to staff <?= $who === null ? 'you' : 'them' ?>.</div><?php endif; ?>
    <?php if ($cur !== null && $cur['status'] === 'rejected'): ?><div class="alert alert-warning" id="expense-form-rejection"><strong>Sent back:</strong> <?= e((string) $cur['rejection_reason']) ?></div><?php endif; ?>
    <form method="post" action="/expenses/save.php" hx-post="/expenses/save.php" hx-target="#flash" hx-encoding="multipart/form-data" enctype="multipart/form-data" id="expense-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="expense" value="<?= (int) $id ?>"><?php endif; ?><?php if ($who !== null): ?><input type="hidden" name="member" value="<?= (int) $member ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fw-semibold" for="expense-form-field-file"><i class="feather-camera me-1"></i>The receipt<?= $cur !== null && $cur['has_receipt'] ? ' (one is attached; add another)' : '' ?></label>
            <input type="file" name="file" id="expense-form-field-file" class="form-control btn-touch py-3 mb-2" accept="image/*,application/pdf" capture="environment">
            <div id="expense-form-thumb" class="mb-3"></div>
            <?php if ($cur !== null && $cur['receipts'] !== []): ?><div class="mb-3 fs-12" id="expense-form-receipts"><?php foreach ($cur['receipts'] as $r): ?><a href="/attachments/<?= (int) $r['attachment_id'] ?>" target="_blank" rel="noopener" class="me-2"><i class="feather-paperclip me-1"></i><?= e($r['filename']) ?></a><?php endforeach; ?></div><?php endif; ?>
            <label class="form-label fs-12 text-muted" for="expense-form-field-engagement">Engagement</label>
            <select name="engagement" id="expense-form-field-engagement" class="form-select btn-touch mb-3" required><?php if ($engagements === []): ?><option value="">None today</option><?php endif; ?><?php foreach ($engagements as $g): ?><option value="<?= (int) $g['engagement_id'] ?>" <?= $selected === (int) $g['engagement_id'] ? 'selected' : '' ?>><?= e($g['number'] . ' · ' . $g['name'] . ' (' . $g['client_name'] . ', ' . $g['currency'] . ')') ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="expense-form-field-date">Date</label>
            <input type="date" name="date" id="expense-form-field-date" class="form-control btn-touch mb-3" required value="<?= e($date) ?>">
            <label class="form-label fs-12 text-muted" for="expense-form-field-category">Category</label>
            <select name="category" id="expense-form-field-category" class="form-select btn-touch mb-3" required><?php foreach ($categories as $c): ?><option value="<?= (int) $c['category_id'] ?>" <?= ($cur['category_id'] ?? null) === (int) $c['category_id'] ? 'selected' : '' ?>><?= e($c['label']) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="expense-form-field-amount">Amount, tax included</label>
            <input type="text" name="amount" id="expense-form-field-amount" class="form-control btn-touch mb-1" inputmode="decimal" required placeholder="0.00" value="<?= e($cur['amount'] ?? '') ?>">
            <div class="fs-12 mb-3" id="expense-form-threshold" data-default="<?= e($thr) ?>">A receipt is required at or above <strong id="expense-form-threshold-value"><?= e($thr) ?></strong>.</div>
            <label class="form-label fs-12 text-muted" for="expense-form-field-tax">Tax inside the amount</label>
            <input type="text" name="tax_amount" id="expense-form-field-tax" class="form-control btn-touch mb-3" inputmode="decimal" placeholder="0.00" value="<?= e($cur['tax_amount'] ?? '') ?>">
            <label class="form-label fs-12 text-muted" for="expense-form-field-vendor">Vendor</label>
            <input type="text" name="vendor_name" id="expense-form-field-vendor" class="form-control btn-touch mb-3" maxlength="200" value="<?= e($cur['vendor_name'] ?? '') ?>">
            <label class="form-label fs-12 text-muted" for="expense-form-field-description">What it was for</label>
            <input type="text" name="description" id="expense-form-field-description" class="form-control btn-touch mb-3" maxlength="500" required value="<?= e($cur['description'] ?? '') ?>">
            <label class="form-label fs-12 text-muted" for="expense-form-field-paid">Paid with</label>
            <select name="paid_with" id="expense-form-field-paid" class="form-select btn-touch mb-3"><?php foreach (PAID_WITH as $k => $label): ?><option value="<?= e($k) ?>" <?= $paid === $k ? 'selected' : '' ?>><?= e($label) ?><?= $k === 'personal' ? ' — reimbursed' : '' ?></option><?php endforeach; ?></select>
            <input type="hidden" name="billable" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-3" for="expense-form-field-billable"><input type="checkbox" class="form-check-input mt-0" name="billable" value="yes" id="expense-form-field-billable" <?= $cur === null || $cur['billable'] ? 'checked' : '' ?>>Billable to the client (follows the category's default)</label>
            <label class="form-label fs-12 text-muted" for="expense-form-field-markup">Markup %<?= $mayMarkup ? '' : ' — the policy\'s; only a manager changes it' ?></label>
            <input type="text" name="markup_pct" id="expense-form-field-markup" class="form-control btn-touch mb-1" inputmode="decimal" value="<?= e($cur['markup_pct'] ?? '') ?>" <?= $mayMarkup ? '' : 'readonly' ?>>
            <div class="fs-12 mt-2" id="expense-money-live">Billable amount <strong id="expense-money-live-value">—</strong></div>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="expense-form-save-btn" <?= $engagements === [] ? 'disabled' : '' ?>>Save</button>
        <button type="submit" name="submit" value="1" class="btn btn-light btn-touch w-100 mb-2" id="expense-form-submit-btn" <?= $engagements === [] ? 'disabled' : '' ?>>Save and submit</button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="expense-form-cancel-link"') ?>
    </form>
    <?php if ($cur !== null): ?><form method="post" action="/expenses/delete.php" hx-post="/expenses/delete.php" hx-target="#flash" class="mt-2" hx-confirm="Remove <?= e($cur['number']) ?>?"><?= csrf_field() ?><input type="hidden" name="expense" value="<?= (int) $id ?>"><button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="expense-form-delete-btn">Remove the expense</button></form><?php endif; ?>
</div>
<script>
(function () {
    var cats = <?= json_encode($cats, JSON_THROW_ON_ERROR) ?>, engs = <?= json_encode($engs, JSON_THROW_ON_ERROR) ?>, dflt = <?= json_encode(['receipt' => $settings['receipt_threshold'], 'markup' => $settings['default_markup_pct']]) ?>, mayMarkup = <?= $mayMarkup ? 'true' : 'false' ?>, editing = <?= $cur === null ? 'false' : 'true' ?>;
    var $ = function (s) { return document.getElementById(s); };
    var cat = $('expense-form-field-category'), eng = $('expense-form-field-engagement'), amt = $('expense-form-field-amount'), mk = $('expense-form-field-markup'), billable = $('expense-form-field-billable');
    function pick(list, id) { for (var i = 0; i < list.length; i++) { if (String(list[i].id) === String(id)) { return list[i]; } } return null; }
    function policy() {
        var c = pick(cats, cat.value), e = pick(engs, eng.value);
        var receipt = c && c.receipt !== null ? c.receipt : (e && e.receipt !== null ? e.receipt : dflt.receipt);
        var markup = c && c.markup !== null ? c.markup : (e && e.markup !== null ? e.markup : dflt.markup);
        return { receipt: receipt, markup: markup, c: c, e: e };
    }
    function money(v) { var n = parseFloat(String(v).replace(/[,\s]/g, '')); return isNaN(n) ? null : n; }
    function refresh(fromCategory) {
        var p = policy();
        $('expense-form-threshold-value').textContent = Number(p.receipt).toFixed(2);
        if (!editing && mk.value === '') { mk.value = Number(p.markup).toFixed(2); }
        if (fromCategory && p.c && billable) { billable.checked = !!p.c.billable; }
        var a = money(amt.value), m = money(mk.value) || 0;
        $('expense-money-live-value').textContent = a === null ? '—' : (a * (1 + m / 100)).toFixed(2) + (p.e ? ' ' + p.e.currency : '');
    }
    cat.addEventListener('change', function () { if (!editing) { mk.value = ''; } refresh(!editing); });
    eng.addEventListener('change', function () { if (!editing) { mk.value = ''; } refresh(false); });
    amt.addEventListener('input', function () { refresh(false); });
    mk.addEventListener('input', function () { refresh(false); });
    $('expense-form-field-file').addEventListener('change', function () {
        var t = $('expense-form-thumb'), f = this.files && this.files[0];
        t.innerHTML = '';
        if (!f) { return; }
        if (f.type.indexOf('image/') === 0) { var i = document.createElement('img'); i.src = URL.createObjectURL(f); i.className = 'img-fluid rounded border'; i.style.maxHeight = '200px'; i.id = 'expense-form-thumb-image'; t.appendChild(i); }
        else { t.textContent = f.name; }
    });
    refresh(false);
})();
</script>
