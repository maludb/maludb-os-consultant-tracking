<?php /** One client (screen `client-view`). Data: c, engagements, attachments, notes, timeline, settings, may, here, editContact, notice */ $id = (int) $c['client_id']; $tz = member_timezone(); $cur = $c['currency'] ?? $settings['base_currency']; ?>
<?= view('shared/header.php', ['id' => 'client-view', 'title' => $c['name'], 'crumbs' => [['Home', '/'], ['Clients', '/clients/'], [$c['name'], null]], 'back' => back_link() ?? ['/clients/', 'Clients'],
    'action' => $may['manage'] && $c['archived_at'] === null ? hx_link(with_back('/clients/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-primary btn-touch', 'id="client-view-edit-btn"') : '']) ?>
<div class="main-content" id="client-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card mb-3" id="client-view-facts"><div class="card-body">
                <div class="mb-2">
                    <?php if ($c['code']): ?><span class="badge bg-soft-primary text-primary" id="client-view-code"><?= e($c['code']) ?></span><?php endif; ?>
                    <?php if ($c['archived_at'] !== null): ?><span class="badge bg-soft-secondary text-secondary" id="client-view-archived">Archived</span><?php endif; ?>
                    <?php if ($c['po_required']): ?><span class="badge bg-soft-warning text-warning" id="client-view-po">PO required</span><?php endif; ?>
                </div>
                <?php if ($may['billing']): ?><div class="mb-2" id="client-view-balance"><?php if ((float) $c['open_balance'] > 0): ?><span class="fs-20 fw-bold"><?= e(fmt_money($c['open_balance'], $cur)) ?></span> <span class="fs-12 text-muted">open</span><?php else: ?><span class="text-muted fs-12">Nothing open</span><?php endif; ?></div><?php endif; ?>
                <dl class="row mb-0 fs-12">
                    <?php if ($c['legal_name']): ?><dt class="col-5 text-muted">Legal name</dt><dd class="col-7" id="client-view-legal"><?= e($c['legal_name']) ?></dd><?php endif; ?>
                    <?php if ($c['email'] !== null): ?><dt class="col-5 text-muted">Email</dt><dd class="col-7" id="client-view-email"><?= e($c['email']) ?></dd><?php endif; ?>
                    <?php if ($c['phone'] !== null && $c['phone'] !== ''): ?><dt class="col-5 text-muted">Phone</dt><dd class="col-7" id="client-view-phone"><?= e($c['phone']) ?></dd><?php endif; ?>
                    <?php if ($c['billing_address']): ?><dt class="col-5 text-muted">Billing</dt><dd class="col-7" id="client-view-address"><?= nl2br(e($c['billing_address'])) ?></dd><?php endif; ?>
                    <?php if ($c['tax_id']): ?><dt class="col-5 text-muted">Tax id</dt><dd class="col-7"><?= e($c['tax_id']) ?></dd><?php endif; ?>
                    <dt class="col-5 text-muted">Currency</dt><dd class="col-7" id="client-view-currency"><?= e($c['currency'] ?? $settings['base_currency'] . ' (the base currency)') ?></dd>
                    <dt class="col-5 text-muted">Terms</dt><dd class="col-7" id="client-view-terms">Net <?= $c['terms_days'] === null ? (int) $settings['default_terms_days'] . ' (the default)' : (int) $c['terms_days'] ?></dd>
                    <dt class="col-5 text-muted">Rate card</dt><dd class="col-7" id="client-view-card"><?= $c['default_rate_card_id'] === null ? 'None' : (has_right('rates.view') ? hx_link(with_back('/settings/rate-cards/' . (int) $c['default_rate_card_id'], $here), e($c['rate_card_name'])) : e($c['rate_card_name'])) ?></dd>
                    <?php if ($c['notes']): ?><dt class="col-5 text-muted">Notes</dt><dd class="col-7" id="client-view-notes"><?= nl2br(e($c['notes'])) ?></dd><?php endif; ?>
                </dl>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <?php if ($may['engagements'] && $c['archived_at'] === null): ?><?= hx_link(with_back('/engagements/new?client=' . $id, $here), 'New engagement', 'btn btn-light btn-touch', 'id="client-view-engagement-btn"') ?><?php endif; ?>
                    <?php if ($may['statement']): ?><?= hx_link(with_back('/clients/' . $id . '/statement', $here), 'Statement', 'btn btn-light btn-touch', 'id="client-view-statement-btn"') ?><?php endif; ?>
                </div>
                <?php if ($may['manage']): ?>
                    <?php if ($c['archived_at'] === null): ?>
                    <form method="post" action="/clients/archive.php" hx-post="/clients/archive.php" hx-target="#flash" class="mt-3" hx-confirm="Archive <?= e($c['name']) ?>? Its engagements and invoices stay; it leaves the lists."><?= csrf_field() ?><input type="hidden" name="client" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="client-view-archive-btn">Archive</button></form>
                    <?php else: ?>
                    <form method="post" action="/clients/restore.php" hx-post="/clients/restore.php" hx-target="#flash" class="mt-3"><?= csrf_field() ?><input type="hidden" name="client" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="client-view-restore-btn">Restore</button></form>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($may['delete'] && $c['engagement_count'] === 0): ?>
                    <form method="post" action="/clients/delete.php" hx-post="/clients/delete.php" hx-target="#flash" class="mt-2" hx-confirm="Delete <?= e($c['name']) ?>? Only a client with no engagement at all can be deleted."><?= csrf_field() ?><input type="hidden" name="client" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="client-view-delete-btn">Delete</button></form>
                <?php endif; ?>
            </div></div>
            <?= view('clients/partials/contacts.php', ['c' => $c, 'may' => $may, 'here' => $here, 'editContact' => $editContact]) ?>
        </div>
        <div class="col-lg-7">
            <div class="card mb-3" id="client-view-engagements"><div class="card-header"><h5 class="card-title mb-0">Engagements</h5><?= hx_link(with_back('/engagements/?client=' . $id, $here), 'All', 'fs-12', 'id="client-view-engagements-all"') ?></div>
                <?php if ($engagements === []): ?><div class="card-body text-muted" id="client-view-engagements-empty">None yet.</div><?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($engagements as $e): ?><div class="list-group-item d-flex justify-content-between align-items-center" id="client-engagement-<?= (int) $e['engagement_id'] ?>"><span><?= hx_link(with_back('/engagements/' . (int) $e['engagement_id'], $here), e($e['number'] . ' · ' . $e['name'])) ?> <span class="text-muted fs-12"><?= e($e['kind'] === 'fixed_bid' ? 'Fixed bid' : 'T&M') ?><?= $e['manager_name'] ? ' · ' . e($e['manager_name']) : '' ?></span></span><span class="badge bg-soft-<?= $e['status'] === 'active' ? 'success text-success' : ($e['status'] === 'closed' ? 'secondary text-secondary' : 'warning text-warning') ?>"><?= e($e['status']) ?></span></div><?php endforeach; ?></div><?php endif; ?>
            </div>
            <?php if ($may['billing']): ?>
            <div class="card mb-3" id="client-view-invoices"><div class="card-header"><h5 class="card-title mb-0">Open invoices</h5><?= hx_link(with_back('/invoices/?client=' . $id, $here), 'All', 'fs-12', 'id="client-view-invoices-all"') ?></div>
                <?php if ($openInvoices === []): ?><div class="card-body text-muted" id="client-view-invoices-empty">Nothing open.</div><?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($openInvoices as $oi): ?><div class="list-group-item d-flex justify-content-between align-items-center gap-2 fs-12" id="client-invoice-<?= (int) $oi['invoice_id'] ?>"><span><?= hx_link(with_back('/invoices/' . (int) $oi['invoice_id'], $here), e((string) $oi['number'])) ?> <span class="text-muted">· <?= e($oi['engagement_number']) ?> · due <?= e(format_date($oi['due_date'])) ?></span> <?= view('invoices/partials/chips.php', ['i' => $oi, 'id' => 'client-invoice-' . (int) $oi['invoice_id']]) ?></span><strong><?= e(fmt_money($oi['balance_due'], $oi['currency'])) ?></strong></div><?php endforeach; ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
            <?= view('shared/attachments.php', ['type' => 'client', 'id' => $id, 'attachments' => $attachments, 'may' => ['attach' => $may['attach'], 'delete' => $may['attach']], 'here' => $here, 'prefix' => 'client']) ?>
            <?= view('shared/notes.php', ['type' => 'client', 'id' => $id, 'notes' => $notes, 'may' => ['note' => true, 'admin' => $may['delete']], 'here' => $here, 'tz' => $tz, 'prefix' => 'client', 'me' => (int) current_member_id()]) ?>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'client']) ?>
        </div>
    </div>
</div>
