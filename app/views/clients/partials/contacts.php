<?php /** The contacts of a client: a list with edit/remove, and an add form (inline; a full page on a phone is this same card). Data: c, may (manage), here, editContact (id being changed) */
$id = (int) $c['client_id']; $editing = null;
foreach ($c['contacts'] as $k) { if ($editContact !== null && $k['contact_id'] === $editContact) { $editing = $k; } } ?>
<div class="card mb-3" id="client-contacts"><div class="card-header"><h5 class="card-title mb-0">Contacts</h5></div>
    <div class="list-group list-group-flush" id="client-contacts-list">
        <?php if ($c['contacts'] === []): ?><div class="list-group-item text-muted fs-12" id="client-contacts-empty">No contact yet. Invoices go to the client's email until a contact receives them.</div><?php endif; ?>
        <?php foreach ($c['contacts'] as $k): $kid = $k['contact_id']; ?>
            <div class="list-group-item" id="contact-<?= $kid ?>">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="min-w-0"><span class="fw-semibold" id="contact-<?= $kid ?>-name"><?= e($k['name']) ?></span><?= $k['is_primary'] ? ' <span class="badge bg-soft-primary text-primary" id="contact-' . $kid . '-primary">Primary</span>' : '' ?><?= $k['receives_invoices'] ? ' <span class="badge bg-soft-info text-info" id="contact-' . $kid . '-invoices">Invoices</span>' : '' ?>
                        <div class="fs-12 text-muted"><?= e(implode(' · ', array_filter([$k['role'], $k['email'], $k['phone']]))) ?></div></div>
                    <?php if ($may['manage']): ?><div class="d-flex gap-1 flex-shrink-0"><?= hx_link('/clients/' . $id . '?contact=' . $kid . '#client-contacts', 'Change', 'btn btn-sm btn-light', 'id="contact-' . $kid . '-edit-btn"') ?>
                        <form method="post" action="/clients/contacts/remove.php" hx-post="/clients/contacts/remove.php" hx-target="#flash" class="d-inline" hx-confirm="Remove <?= e($k['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="contact" value="<?= $kid ?>"><input type="hidden" name="return_to" value="<?= e('/clients/' . $id) ?>"><button type="submit" class="btn btn-sm btn-light text-danger" id="contact-<?= $kid ?>-remove-btn" title="Remove">&times;</button></form></div><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($may['manage']): ?>
    <div class="card-body border-top">
        <div class="fs-12 text-muted mb-2" id="client-contact-form-title"><?= $editing === null ? 'Add a contact' : 'Change ' . e($editing['name']) ?></div>
        <form method="post" action="/clients/contacts/save.php" hx-post="/clients/contacts/save.php" hx-target="#flash" id="client-contact-form" class="row g-2">
            <?= csrf_field() ?><input type="hidden" name="client" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e('/clients/' . $id) ?>">
            <?php if ($editing !== null): ?><input type="hidden" name="contact" value="<?= (int) $editing['contact_id'] ?>"><?php endif; ?>
            <div class="col-12 col-md-6"><input type="text" name="name" class="form-control btn-touch" placeholder="Name" maxlength="120" required id="client-contact-form-field-name" value="<?= e($editing['name'] ?? '') ?>"></div>
            <div class="col-12 col-md-6"><input type="email" name="email" class="form-control btn-touch" placeholder="Email" maxlength="200" id="client-contact-form-field-email" value="<?= e($editing['email'] ?? '') ?>"></div>
            <div class="col-6"><input type="text" name="phone" class="form-control btn-touch" placeholder="Phone" maxlength="40" id="client-contact-form-field-phone" value="<?= e($editing['phone'] ?? '') ?>"></div>
            <div class="col-6"><input type="text" name="role" class="form-control btn-touch" placeholder="Role (Accounts payable)" maxlength="80" id="client-contact-form-field-role" value="<?= e($editing['role'] ?? '') ?>"></div>
            <div class="col-6"><input type="hidden" name="is_primary" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="client-contact-form-field-primary"><input type="checkbox" class="form-check-input mt-0" name="is_primary" value="yes" id="client-contact-form-field-primary" <?= !empty($editing['is_primary']) ? 'checked' : '' ?>>Primary</label></div>
            <div class="col-6"><input type="hidden" name="receives_invoices" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="client-contact-form-field-invoices"><input type="checkbox" class="form-check-input mt-0" name="receives_invoices" value="yes" id="client-contact-form-field-invoices" <?= !empty($editing['receives_invoices']) ? 'checked' : '' ?>>Receives invoices</label></div>
            <div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100" id="client-contact-form-save-btn"><?= $editing === null ? 'Add' : 'Save' ?></button></div>
            <?php if ($editing !== null): ?><div class="col-12"><?= hx_link('/clients/' . $id . '#client-contacts', 'Cancel', 'btn btn-link btn-sm w-100', 'id="client-contact-form-cancel"') ?></div><?php endif; ?>
        </form>
    </div>
    <?php endif; ?>
</div>
