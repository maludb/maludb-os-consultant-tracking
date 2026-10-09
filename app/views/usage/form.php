<?php /** Record or change a usage charge (screens `usage-add`, `usage-edit`). Data: cur, engagements, providers, prefill, here */
$id = $cur['usage_charge_id'] ?? null; $screen = $cur === null ? 'usage-add' : 'usage-edit'; $selected = $cur['engagement_id'] ?? $prefill; $kernel = $cur !== null && $cur['source'] === 'kernel_ledger';
$back = $cur === null ? ['/usage/', 'AI usage'] : ['/usage/' . $id, 'the charge'];
$ps = $cur['period_start'] ?? date('Y-m-01'); $pe = $cur['period_end'] ?? date('Y-m-t'); $ro = $kernel ? 'readonly' : ''; $dis = $kernel ? 'disabled' : ''; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Record a usage charge' : 'Change ' . $cur['number'], 'crumbs' => [['Home', '/'], ['AI usage and hosting', '/usage/'], [$cur === null ? 'Record' : 'Change', null]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <?php if ($engagements === []): ?><div class="alert alert-info" id="usage-form-none">You manage no live engagement to record a charge on.</div><?php endif; ?>
    <?php if ($kernel): ?><div class="alert alert-info fs-12" id="usage-form-kernel">This charge came from the OS statement of <?= e((string) $cur['source_ref']) ?>: its cost, quantity and period are the statement's. Only the pricing, the billable flag and the description change here.</div><?php endif; ?>
    <form method="post" action="/usage/save.php" hx-post="/usage/save.php" hx-target="#flash" hx-encoding="multipart/form-data" enctype="multipart/form-data" id="usage-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="charge" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fs-12 text-muted" id="usage-form-field-engagement-label" for="usage-form-field-engagement-open">Engagement</label>
            <?= picker_field(['id' => 'usage-form-field-engagement', 'labelledby' => 'usage-form-field-engagement-label', 'name' => 'engagement', 'source' => 'engagement_managed', 'value' => $selected, 'required' => true]) ?>
            <label class="form-label fs-12 text-muted" for="usage-form-field-kind">Kind</label>
            <select name="kind" id="usage-form-field-kind" class="form-select btn-touch mb-3" <?= $dis ?>><?php foreach (USAGE_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= ($cur['kind'] ?? 'hosting') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="usage-form-field-provider">Provider</label>
            <select name="provider" id="usage-form-field-provider" class="form-select btn-touch mb-3" <?= $dis ?>><option value="">None</option><?php foreach ($providers as $p): ?><option value="<?= (int) $p['provider_id'] ?>" <?= ($cur['provider_id'] ?? null) === (int) $p['provider_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="usage-form-field-description">What it is</label>
            <input type="text" name="description" id="usage-form-field-description" class="form-control btn-touch mb-3" maxlength="500" required value="<?= e($cur['description'] ?? '') ?>">
            <div class="row g-2 mb-3"><div class="col-6"><label class="form-label fs-12 text-muted" for="usage-form-field-period-start">Period from</label><input type="date" name="period_start" id="usage-form-field-period-start" class="form-control btn-touch" required value="<?= e($ps) ?>" <?= $ro ?>></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="usage-form-field-period-end">to</label><input type="date" name="period_end" id="usage-form-field-period-end" class="form-control btn-touch" required value="<?= e($pe) ?>" <?= $ro ?>></div></div>
            <div class="row g-2 mb-1"><div class="col-7"><label class="form-label fs-12 text-muted" for="usage-form-field-quantity">Quantity</label><input type="text" name="quantity" id="usage-form-field-quantity" class="form-control btn-touch" inputmode="decimal" required value="<?= e($cur['quantity'] ?? '1') ?>" <?= $ro ?>></div>
                <div class="col-5"><label class="form-label fs-12 text-muted" for="usage-form-field-unit">Unit</label><select name="unit" id="usage-form-field-unit" class="form-select btn-touch" <?= $dis ?>><?php foreach (USAGE_UNITS as $k => $l): ?><option value="<?= e($k) ?>" <?= ($cur['unit'] ?? 'months') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div></div>
            <div class="fs-12 text-muted mb-3">For tokens, the quantity is the number of tokens (2,000,000), not millions.</div>
            <label class="form-label fs-12 text-muted" for="usage-form-field-unit-cost">Unit cost — what one unit costs you (a token costs 0.000003 when a million cost 3.00)</label>
            <input type="text" name="unit_cost" id="usage-form-field-unit-cost" class="form-control btn-touch mb-3" inputmode="decimal" required value="<?= e($cur['unit_cost'] ?? '') ?>" <?= $ro ?>>
            <?= view('usage/partials/pricing.php', ['cur' => $cur, 'prefix' => 'usage-form']) ?>
            <input type="hidden" name="billable" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-3" for="usage-form-field-billable"><input type="checkbox" class="form-check-input mt-0" name="billable" value="yes" id="usage-form-field-billable" <?= $cur === null || $cur['billable'] ? 'checked' : '' ?>>Billable to the client</label>
            <div class="border rounded p-3 mb-3 fs-12" id="pricing-preview">Cost <strong id="pricing-preview-cost">—</strong> · Billable <strong id="pricing-preview-billable">—</strong><span class="text-muted"> (the database writes the figures; this is the same sum)</span></div>
            <label class="form-label fs-12 text-muted" for="usage-form-field-file">The provider's invoice (optional)</label>
            <input type="file" name="file" id="usage-form-field-file" class="form-control btn-touch mb-1" accept="application/pdf,image/*,text/csv">
            <?php if ($cur !== null && $cur['documents'] !== []): ?><div class="fs-12 mt-2"><?php foreach ($cur['documents'] as $d): ?><a href="/attachments/<?= (int) $d['attachment_id'] ?>" target="_blank" rel="noopener" class="me-2"><i class="feather-paperclip me-1"></i><?= e($d['filename']) ?></a><?php endforeach; ?></div><?php endif; ?>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="usage-form-save-btn" <?= $engagements === [] ? 'disabled' : '' ?>>Save the draft</button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="usage-form-cancel-link"') ?>
    </form>
    <?php if ($cur !== null && !$kernel): ?><form method="post" action="/usage/delete.php" hx-post="/usage/delete.php" hx-target="#flash" class="mt-2" hx-confirm="Remove <?= e($cur['number']) ?>?"><?= csrf_field() ?><input type="hidden" name="charge" value="<?= (int) $id ?>"><button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="usage-form-delete-btn">Remove the draft</button></form><?php endif; ?>
</div>
<script>
(function () {
    var $ = function (s) { return document.getElementById(s); };
    var q = $('usage-form-field-quantity'), uc = $('usage-form-field-unit-cost'), unit = $('usage-form-field-unit'), pr = $('usage-form-field-pricing'), mk = $('usage-form-field-markup'), rs = $('usage-form-field-resale'), eng = $('usage-form-field-engagement');
    var cur = <?= json_encode(array_column($engagements, 'currency', 'engagement_id'), JSON_THROW_ON_ERROR) ?>;
    var kernelCost = <?= $kernel ? json_encode((float) $cur['cost_amount']) : 'null' ?>;
    function num(v) { var n = parseFloat(String(v).replace(/[,\s]/g, '')); return isNaN(n) ? null : n; }
    function r2(n) { return Math.round((n + Number.EPSILON) * 100) / 100; }
    function refresh() {
        var qq = num(q.value), u = num(uc.value), cost = kernelCost !== null ? kernelCost : (qq === null || u === null ? null : r2(qq * u));
        var bill = null;
        if (cost !== null) {
            if (pr.value === 'cost') { bill = r2(cost); }
            else if (pr.value === 'markup') { bill = r2(cost * (1 + (num(mk.value) || 0) / 100)); }
            else if (qq !== null && num(rs.value) !== null) { bill = r2(qq * num(rs.value) / (unit.value === 'tokens' ? 1000000 : 1)); }
        }
        var c = cur[eng.value] ? ' ' + cur[eng.value] : '';
        $('pricing-preview-cost').textContent = cost === null ? '—' : cost.toFixed(2) + c;
        $('pricing-preview-billable').textContent = bill === null ? '—' : bill.toFixed(2) + c;
    }
    [q, uc, unit, pr, mk, rs, eng].forEach(function (el) { el.addEventListener('input', refresh); el.addEventListener('change', refresh); });
    refresh();
})();
</script>
