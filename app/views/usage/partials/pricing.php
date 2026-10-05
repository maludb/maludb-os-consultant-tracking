<?php /** The pricing fields shared by a charge, a rule and a schedule. Data: cur (a row or null), prefix (ids: {prefix}-field-pricing …) */
$pf = $prefix; $pricing = $cur['pricing'] ?? 'cost'; ?>
<label class="form-label fs-12 text-muted" for="<?= e($pf) ?>-field-pricing">Pricing</label>
<select name="pricing" id="<?= e($pf) ?>-field-pricing" class="form-select btn-touch mb-3"><?php foreach (USAGE_PRICINGS as $k => $label): ?><option value="<?= e($k) ?>" <?= $pricing === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
<div id="<?= e($pf) ?>-markup-group" <?= $pricing === 'markup' ? '' : 'class="d-none"' ?>>
    <label class="form-label fs-12 text-muted" for="<?= e($pf) ?>-field-markup">Markup %</label>
    <input type="text" name="markup_pct" id="<?= e($pf) ?>-field-markup" class="form-control btn-touch mb-3" inputmode="decimal" value="<?= e($cur['markup_pct'] ?? '0.00') ?>">
</div>
<div id="<?= e($pf) ?>-resale-group" <?= $pricing === 'resale' ? '' : 'class="d-none"' ?>>
    <label class="form-label fs-12 text-muted" for="<?= e($pf) ?>-field-resale">Resale price (per million tokens when the unit is tokens, else per unit)</label>
    <input type="text" name="resale_unit_price" id="<?= e($pf) ?>-field-resale" class="form-control btn-touch mb-3" inputmode="decimal" value="<?= e($cur['resale_unit_price'] ?? '') ?>">
</div>
<script>
(function () {
    var sel = document.getElementById(<?= json_encode($pf . '-field-pricing') ?>), m = document.getElementById(<?= json_encode($pf . '-markup-group') ?>), r = document.getElementById(<?= json_encode($pf . '-resale-group') ?>);
    function show() { m.classList.toggle('d-none', sel.value !== 'markup'); r.classList.toggle('d-none', sel.value !== 'resale'); }
    sel.addEventListener('change', show); show();
})();
</script>
