<?php /** Add or change a client (screens `client-add`, `client-edit`). Data: cur, cards, settings, here */
$id = $cur['client_id'] ?? null; $screen = $cur === null ? 'client-add' : 'client-edit';
$back = $cur === null ? ['/clients/', 'Clients'] : ['/clients/' . (int) $id, $cur['name']]; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a client' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Clients', '/clients/'], [$cur === null ? 'Add' : $cur['name'], $cur === null ? null : '/clients/' . (int) $id]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/clients/save.php" hx-post="/clients/save.php" hx-target="#flash" id="client-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="client" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The client</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="client-form-field-name">Name</label>
            <input type="text" name="name" id="client-form-field-name" class="form-control btn-touch mb-3" maxlength="120" required value="<?= e($cur['name'] ?? '') ?>">
            <label class="form-label fs-12 text-muted" for="client-form-field-legal">Legal name (on the invoice)</label>
            <input type="text" name="legal_name" id="client-form-field-legal" class="form-control btn-touch mb-3" maxlength="200" value="<?= e($cur['legal_name'] ?? '') ?>">
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="client-form-field-code">Code (2–8 letters or digits)</label><input type="text" name="code" id="client-form-field-code" class="form-control btn-touch text-uppercase" maxlength="8" pattern="[A-Za-z0-9]{2,8}" value="<?= e($cur['code'] ?? '') ?>" placeholder="ACME"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="client-form-field-taxid">Tax id</label><input type="text" name="tax_id" id="client-form-field-taxid" class="form-control btn-touch" maxlength="60" value="<?= e($cur['tax_id'] ?? '') ?>"></div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="client-form-field-email">Email (where invoices go when no contact says otherwise)</label><input type="email" name="email" id="client-form-field-email" class="form-control btn-touch" maxlength="200" value="<?= e($cur['email'] ?? '') ?>"></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="client-form-field-phone">Phone</label><input type="text" name="phone" id="client-form-field-phone" class="form-control btn-touch" maxlength="40" value="<?= e($cur['phone'] ?? '') ?>"></div>
            </div>
            <label class="form-label fs-12 text-muted" for="client-form-field-address">Billing address</label>
            <textarea name="billing_address" id="client-form-field-address" class="form-control" rows="2" maxlength="500"><?= e($cur['billing_address'] ?? '') ?></textarea>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Billing</h5></div><div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="client-form-field-currency">Currency (blank: <?= e($settings['base_currency']) ?>)</label><input type="text" name="currency" id="client-form-field-currency" class="form-control btn-touch text-uppercase" maxlength="3" pattern="[A-Za-z]{3}" value="<?= e($cur['currency'] ?? '') ?>" placeholder="<?= e($settings['base_currency']) ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="client-form-field-terms">Terms, days (blank: <?= (int) $settings['default_terms_days'] ?>)</label><input type="number" name="terms_days" id="client-form-field-terms" class="form-control btn-touch" min="0" max="365" value="<?= $cur === null || $cur['terms_days'] === null ? '' : (int) $cur['terms_days'] ?>" placeholder="<?= (int) $settings['default_terms_days'] ?>"></div>
            </div>
            <label class="form-label fs-12 text-muted" for="client-form-field-card">Default rate card (an engagement may pick another)</label>
            <select name="rate_card" id="client-form-field-card" class="form-select btn-touch mb-3">
                <option value="">None</option>
                <?php foreach ($cards as $r): ?><option value="<?= (int) $r['rate_card_id'] ?>" <?= ($cur['default_rate_card_id'] ?? null) === (int) $r['rate_card_id'] ? 'selected' : '' ?>><?= e($r['name']) ?> (<?= e($r['currency']) ?>)</option><?php endforeach; ?>
            </select>
            <input type="hidden" name="po_required" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-3" for="client-form-field-po"><input type="checkbox" class="form-check-input mt-0" name="po_required" value="yes" id="client-form-field-po" <?= !empty($cur['po_required']) ? 'checked' : '' ?>>An invoice needs the engagement's PO number</label>
            <label class="form-label fs-12 text-muted" for="client-form-field-notes">Notes (ours; never shown to the client)</label>
            <textarea name="notes" id="client-form-field-notes" class="form-control" rows="3" maxlength="4000"><?= e($cur['notes'] ?? '') ?></textarea>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="client-form-save-btn">Save</button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="client-form-cancel-link"') ?>
    </form>
</div>
