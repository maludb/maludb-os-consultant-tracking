<?php /** New or change an engagement (screens `engagement-add`, `engagement-edit`). Data: cur, prefillClient, clients, cards, settings, departments, members, me, here */
$id = $cur['engagement_id'] ?? null; $screen = $cur === null ? 'engagement-add' : 'engagement-edit'; $policy = $cur['expense_policy'] ?? [];
$back = $cur === null ? ['/engagements/', 'Engagements'] : ['/engagements/' . $id, $cur['number']];
$clientId = $cur['client_id'] ?? $prefillClient; $kind = $cur['kind'] ?? 'time_and_materials'; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New engagement' : 'Change ' . $cur['number'], 'crumbs' => [['Home', '/'], ['Engagements', '/engagements/'], [$cur === null ? 'New' : $cur['number'], $cur === null ? null : '/engagements/' . $id]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/engagements/save.php" hx-post="/engagements/save.php" hx-target="#flash" id="engagement-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="engagement" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The engagement</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="engagement-form-field-client">Client</label>
            <select name="client" id="engagement-form-field-client" class="form-select btn-touch mb-3" required><option value="">Choose</option><?php foreach ($clients as $c): ?><option value="<?= (int) $c['client_id'] ?>" data-currency="<?= e($c['currency'] ?? $settings['base_currency']) ?>" data-card="<?= (int) ($c['default_rate_card_id'] ?? 0) ?>" <?= $clientId === (int) $c['client_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="engagement-form-field-name">Name</label>
            <input type="text" name="name" id="engagement-form-field-name" class="form-control btn-touch mb-3" maxlength="160" required value="<?= e($cur['name'] ?? '') ?>">
            <label class="form-label fs-12 text-muted">Kind</label>
            <div class="d-flex gap-2 mb-3" id="engagement-form-kind">
                <?php foreach (ENGAGEMENT_KINDS as $k => $label): ?><label class="flex-fill border rounded px-3 d-flex align-items-center gap-2 btn-touch mb-0" for="engagement-form-field-kind-<?= e($k) ?>"><input type="radio" class="form-check-input mt-0" name="kind" value="<?= e($k) ?>" id="engagement-form-field-kind-<?= e($k) ?>" <?= $kind === $k ? 'checked' : '' ?> onchange="document.getElementById('engagement-form-nte').classList.toggle('d-none', this.value === 'fixed_bid'); document.getElementById('engagement-form-amount-label').textContent = this.value === 'fixed_bid' ? 'Fee' : 'Soft budget';"><?= e($label) ?></label><?php endforeach; ?>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="engagement-form-field-start">Starts</label><input type="date" name="start_date" id="engagement-form-field-start" class="form-control btn-touch" required value="<?= e($cur['start_date'] ?? date('Y-m-d')) ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="engagement-form-field-end">Ends (blank: open)</label><input type="date" name="end_date" id="engagement-form-field-end" class="form-control btn-touch" value="<?= e($cur['end_date'] ?? '') ?>"></div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-4"><label class="form-label fs-12 text-muted" for="engagement-form-field-currency">Currency (blank: the client's)</label><input type="text" name="currency" id="engagement-form-field-currency" class="form-control btn-touch text-uppercase" maxlength="3" pattern="[A-Za-z]{3}" value="<?= e($cur['currency'] ?? '') ?>"></div>
                <div class="col-8"><label class="form-label fs-12 text-muted" for="engagement-form-field-po">PO number</label><input type="text" name="po_number" id="engagement-form-field-po" class="form-control btn-touch" maxlength="60" value="<?= e($cur['po_number'] ?? '') ?>"></div>
            </div>
            <label class="form-label fs-12 text-muted" for="engagement-form-field-description">Description (the scope, in a few lines)</label>
            <textarea name="description" id="engagement-form-field-description" class="form-control" rows="3" maxlength="4000"><?= e($cur['description'] ?? '') ?></textarea>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Money and budgets</h5></div><div class="card-body">
            <?php if ($cards !== []): ?><label class="form-label fs-12 text-muted" for="engagement-form-field-card">Rate card (blank: the client's default)</label>
            <select name="rate_card" id="engagement-form-field-card" class="form-select btn-touch mb-3"><option value="">The client's default</option><?php foreach ($cards as $r): ?><option value="<?= (int) $r['rate_card_id'] ?>" <?= ($cur['rate_card_id'] ?? null) === (int) $r['rate_card_id'] ? 'selected' : '' ?>><?= e($r['name']) ?> (<?= e($r['currency']) ?>)</option><?php endforeach; ?></select><?php endif; ?>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="engagement-form-field-hours">Budget hours</label><input type="number" name="budget_hours" id="engagement-form-field-hours" class="form-control btn-touch" min="0" step="0.5" value="<?= e($cur === null || $cur['budget_hours'] === null ? '' : days_label($cur['budget_hours'])) ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="engagement-form-field-amount"><span id="engagement-form-amount-label"><?= $kind === 'fixed_bid' ? 'Fee' : 'Soft budget' ?></span></label><input type="text" name="budget_amount" id="engagement-form-field-amount" class="form-control btn-touch" inputmode="decimal" value="<?= e($cur === null || $cur['budget_amount'] === null ? '' : number_format((float) $cur['budget_amount'], 2, '.', '')) ?>"></div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6 <?= $kind === 'fixed_bid' ? 'd-none' : '' ?>" id="engagement-form-nte"><label class="form-label fs-12 text-muted" for="engagement-form-field-nte">Not to exceed (T&amp;M; refuses issuing above it)</label><input type="text" name="nte_amount" id="engagement-form-field-nte" class="form-control btn-touch" inputmode="decimal" value="<?= e($cur === null || $cur['nte_amount'] === null ? '' : number_format((float) $cur['nte_amount'], 2, '.', '')) ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="engagement-form-field-ai">AI and hosting budget (at cost)</label><input type="text" name="ai_budget_amount" id="engagement-form-field-ai" class="form-control btn-touch" inputmode="decimal" value="<?= e($cur === null || $cur['ai_budget_amount'] === null ? '' : number_format((float) $cur['ai_budget_amount'], 2, '.', '')) ?>"></div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="engagement-form-field-markup">Expense markup % (blank: <?= e(days_label($settings['default_markup_pct'])) ?>)</label><input type="text" name="markup_pct" id="engagement-form-field-markup" class="form-control btn-touch" inputmode="decimal" value="<?= e(isset($policy['markup_pct']) ? days_label($policy['markup_pct']) : '') ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="engagement-form-field-receipt">Receipt required above (blank: <?= e($settings['receipt_threshold']) ?>)</label><input type="text" name="requires_receipt_above" id="engagement-form-field-receipt" class="form-control btn-touch" inputmode="decimal" value="<?= e(isset($policy['requires_receipt_above']) ? number_format((float) $policy['requires_receipt_above'], 2, '.', '') : '') ?>"></div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="engagement-form-field-cadence">Billing cadence</label><select name="billing_cadence" id="engagement-form-field-cadence" class="form-select btn-touch"><?php foreach (BILLING_CADENCES as $k => $label): ?><option value="<?= e($k) ?>" <?= ($cur['billing_cadence'] ?? 'monthly') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="engagement-form-field-grouping">Time on the invoice</label><select name="time_grouping" id="engagement-form-field-grouping" class="form-select btn-touch"><option value="">The settings' (<?= e(TIME_GROUPINGS[$settings['default_time_grouping']] ?? $settings['default_time_grouping']) ?>)</option><?php foreach (TIME_GROUPINGS as $k => $label): ?><option value="<?= e($k) ?>" <?= ($cur['time_grouping'] ?? null) === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            </div>
            <label class="form-label fs-12 text-muted" for="engagement-form-field-share">Receipts on the client's secure link</label>
            <select name="share_receipts_with_client" id="engagement-form-field-share" class="form-select btn-touch"><option value="">The settings' (<?= $settings['share_receipts_default'] ? 'shared' : 'not shared' ?>)</option><option value="yes" <?= ($cur['share_receipts_with_client'] ?? null) === true ? 'selected' : '' ?>>Shared</option><option value="no" <?= ($cur['share_receipts_with_client'] ?? null) === false ? 'selected' : '' ?>>Not shared</option></select>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Who runs it</h5></div><div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="engagement-form-field-manager">Manager<?= $cur === null ? ' (staffed as principal)' : '' ?></label><select name="manager" id="engagement-form-field-manager" class="form-select btn-touch"><option value="">Nobody yet</option><?php foreach ($members as $m): if ($m['member_kind'] !== 'human') { continue; } ?><option value="<?= (int) $m['member_id'] ?>" <?= ($cur['manager_member_id'] ?? $me) === (int) $m['member_id'] ? 'selected' : '' ?>><?= e($m['display_name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="engagement-form-field-department">Practice</label><select name="department" id="engagement-form-field-department" class="form-select btn-touch"><option value="">None</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= ($cur['department_id'] ?? ((int) one_value(db(), 'SELECT ct_primary_department(:m)', ['m' => $me]) ?: null)) === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <label class="form-label fs-12 text-muted" for="engagement-form-field-projects">Projects reference (the key of its project there, by hand)</label>
            <input type="text" name="projects_ref" id="engagement-form-field-projects" class="form-control btn-touch mb-3" maxlength="80" value="<?= e($cur['projects_ref'] ?? '') ?>">
            <?php if ($cur === null): ?><input type="hidden" name="start_now" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="engagement-form-field-start-now"><input type="checkbox" class="form-check-input mt-0" name="start_now" value="yes" id="engagement-form-field-start-now" checked>Start now (active, time can be logged); else a draft</label><?php endif; ?>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="engagement-form-save-btn">Save</button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="engagement-form-cancel-link"') ?>
    </form>
</div>
