<?php /** The five vocabulary tables, one view (screens role-list, activity-type-list, category-list, provider-list, tax-rate-list). Data: kind, rows, editing, withArchived, title, singular, base, here, notice */
$p = str_replace('_', '-', $kind); $param = VOCABULARY[$kind][5]; $fmt = static fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
$label = static fn (array $r): string => (string) ($r['title'] ?? $r['label'] ?? $r['name']); ?>
<?= view('shared/header.php', ['id' => $p . '-list', 'title' => $title, 'crumbs' => [['Home', '/'], ['Settings', '/settings/general'], [$title, null]]]) ?>
<div class="main-content" id="<?= e($p) ?>-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card"><div class="table-responsive"><table class="table table-hover mb-0" id="<?= e($p) ?>-table">
                <thead><tr><th><?= $kind === 'tax_rate' ? 'Name' : 'Label' ?></th><th class="d-none d-md-table-cell"><?= $kind === 'tax_rate' ? 'Rate' : 'Key' ?></th><th class="d-none d-md-table-cell"><?= match ($kind) { 'activity' => 'Billable', 'category' => 'Receipt above / markup', 'provider' => 'Mapping', 'tax_rate' => 'Default', default => 'Order' } ?></th><th class="text-end">In use</th><th class="text-end"></th></tr></thead>
                <tbody>
                    <?php if ($rows === []): ?><tr><td colspan="5" class="text-muted" id="<?= e($p) ?>-empty">None yet.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): $id = $r['id']; $archived = $r['archived_at'] !== null; ?>
                        <tr id="vocab-row-<?= $id ?>"<?= $archived ? ' class="text-muted"' : '' ?>>
                            <td class="fw-semibold" id="vocab-row-<?= $id ?>-label"><?= e($label($r)) ?><?= $archived ? ' <span class="badge bg-soft-secondary text-secondary">Archived</span>' : '' ?><?= $kind === 'tax_rate' && $r['is_default'] ? ' <span class="badge bg-soft-primary text-primary" id="vocab-row-' . $id . '-default">Default</span>' : '' ?></td>
                            <td class="d-none d-md-table-cell fs-12"><?= $kind === 'tax_rate' ? e($fmt($r['rate'])) . '%' : '<code>' . e($r['key'] ?? $r['provider_key']) . '</code>' ?></td>
                            <td class="d-none d-md-table-cell fs-12"><?= match ($kind) {
                                'activity' => $r['billable_default'] ? 'Yes' : 'No',
                                'category' => ($r['receipt_required_above'] === null ? 'settings' : e($fmt($r['receipt_required_above']))) . ' / ' . ($r['default_markup_pct'] === null ? 'engagement' : e($fmt($r['default_markup_pct'])) . '%') . ($r['billable_default'] ? '' : ' · not billable'),
                                'provider' => $r['csv_mapping'] === null ? '—' : '<code class="fs-11">' . e(mb_substr(json_encode($r['csv_mapping']), 0, 60)) . '</code>',
                                'tax_rate' => $r['is_default'] ? 'Yes' : '',
                                default => (int) $r['sort_order'] } ?></td>
                            <td class="text-end fs-12" id="vocab-row-<?= $id ?>-use"><?= (int) $r['in_use'] ?></td>
                            <td class="text-end text-nowrap">
                                <?php if (!$archived): ?><?= hx_link($base . '?edit=' . $id . '#' . $p . '-form', 'Change', 'btn btn-sm btn-light', 'id="vocab-row-' . $id . '-edit-btn"') ?>
                                    <?php if (!($kind === 'tax_rate' && $r['is_default'])): ?><form method="post" action="<?= e($base) ?>archive.php" hx-post="<?= e($base) ?>archive.php" hx-target="#flash" class="d-inline" hx-confirm="Archive <?= e($label($r)) ?>? Existing records keep it; new ones cannot pick it."><?= csrf_field() ?><input type="hidden" name="<?= e($param) ?>" value="<?= $id ?>"><button type="submit" class="btn btn-sm btn-light" id="vocab-row-<?= $id ?>-archive-btn">Archive</button></form><?php endif; ?>
                                    <?php if ((int) $r['in_use'] === 0 && !($kind === 'tax_rate' && $r['is_default'])): ?><form method="post" action="<?= e($base) ?>archive.php" hx-post="<?= e($base) ?>archive.php" hx-target="#flash" class="d-inline" hx-confirm="Delete <?= e($label($r)) ?>? Nothing uses it."><?= csrf_field() ?><input type="hidden" name="<?= e($param) ?>" value="<?= $id ?>"><input type="hidden" name="delete" value="yes"><button type="submit" class="btn btn-sm btn-light text-danger" id="vocab-row-<?= $id ?>-delete-btn">Delete</button></form><?php endif; ?>
                                <?php else: ?><form method="post" action="<?= e($base) ?>archive.php" hx-post="<?= e($base) ?>archive.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="<?= e($param) ?>" value="<?= $id ?>"><input type="hidden" name="archived" value="no"><button type="submit" class="btn btn-sm btn-light" id="vocab-row-<?= $id ?>-restore-btn">Restore</button></form><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div></div>
            <div class="fs-12 mt-2"><?= hx_link($base . ($withArchived ? '' : '?include_archived=1'), $withArchived ? 'Hide archived' : 'Show archived', '', 'id="' . $p . '-archived-link"') ?></div>
        </div>
        <div class="col-lg-5">
            <div class="card" id="<?= e($p) ?>-form-card"><div class="card-header"><h5 class="card-title mb-0"><?= $editing === null ? 'Add a ' . e($singular) : 'Change ' . e($label($editing)) ?></h5></div><div class="card-body">
                <form method="post" action="<?= e($base) ?>save.php" hx-post="<?= e($base) ?>save.php" hx-target="#flash" id="<?= e($p) ?>-form">
                    <?= csrf_field() ?><?php if ($editing !== null): ?><input type="hidden" name="<?= e($param) ?>" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                    <?php if ($kind === 'tax_rate'): ?>
                        <label class="form-label fs-12 text-muted" for="<?= e($p) ?>-form-field-name">Name</label>
                        <input type="text" name="name" id="<?= e($p) ?>-form-field-name" class="form-control btn-touch mb-3" maxlength="60" required value="<?= e($editing['name'] ?? '') ?>">
                        <label class="form-label fs-12 text-muted" for="<?= e($p) ?>-form-field-rate">Rate (percent)</label>
                        <input type="text" name="rate" id="<?= e($p) ?>-form-field-rate" class="form-control btn-touch mb-3" inputmode="decimal" required value="<?= e($editing === null ? '' : $fmt($editing['rate'])) ?>" placeholder="8.25">
                        <input type="hidden" name="is_default" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-3" for="<?= e($p) ?>-form-field-default"><input type="checkbox" class="form-check-input mt-0" name="is_default" value="yes" id="<?= e($p) ?>-form-field-default" <?= !empty($editing['is_default']) ? 'checked' : '' ?>>The default on a new line</label>
                    <?php else: ?>
                        <label class="form-label fs-12 text-muted" for="<?= e($p) ?>-form-field-label"><?= $kind === 'role' ? 'Title' : ($kind === 'provider' ? 'Name' : 'Label') ?></label>
                        <input type="text" name="<?= $kind === 'role' ? 'title' : ($kind === 'provider' ? 'name' : 'label') ?>" id="<?= e($p) ?>-form-field-label" class="form-control btn-touch mb-3" maxlength="80" required value="<?= e($editing === null ? '' : $label($editing)) ?>">
                        <label class="form-label fs-12 text-muted" for="<?= e($p) ?>-form-field-key">Key (lowercase; blank: made from the <?= $kind === 'role' ? 'title' : 'name' ?>)<?= $kind === 'provider' ? ' — the kernel statement\'s provider key' : '' ?></label>
                        <input type="text" name="<?= $kind === 'provider' ? 'provider_key' : 'key' ?>" id="<?= e($p) ?>-form-field-key" class="form-control btn-touch mb-3" maxlength="40" pattern="[a-z][a-z0-9_]*" value="<?= e($editing['key'] ?? $editing['provider_key'] ?? '') ?>">
                        <?php if ($kind === 'role'): ?>
                            <label class="form-label fs-12 text-muted" for="<?= e($p) ?>-form-field-description">Description</label>
                            <textarea name="description" id="<?= e($p) ?>-form-field-description" class="form-control mb-3" rows="2" maxlength="500"><?= e($editing['description'] ?? '') ?></textarea>
                        <?php elseif ($kind === 'activity'): ?>
                            <input type="hidden" name="billable_default" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-3" for="<?= e($p) ?>-form-field-billable"><input type="checkbox" class="form-check-input mt-0" name="billable_default" value="yes" id="<?= e($p) ?>-form-field-billable" <?= $editing === null || $editing['billable_default'] ? 'checked' : '' ?>>Billable by default</label>
                        <?php elseif ($kind === 'category'): ?>
                            <div class="row g-2 mb-3">
                                <div class="col-6"><label class="form-label fs-12 text-muted" for="<?= e($p) ?>-form-field-receipt">Receipt required above (blank: the settings')</label><input type="text" name="receipt_required_above" id="<?= e($p) ?>-form-field-receipt" class="form-control btn-touch" inputmode="decimal" value="<?= e($editing === null ? '' : $fmt($editing['receipt_required_above'])) ?>"></div>
                                <div class="col-6"><label class="form-label fs-12 text-muted" for="<?= e($p) ?>-form-field-markup">Markup % (blank: the engagement's)</label><input type="text" name="default_markup_pct" id="<?= e($p) ?>-form-field-markup" class="form-control btn-touch" inputmode="decimal" value="<?= e($editing === null ? '' : $fmt($editing['default_markup_pct'])) ?>"></div>
                            </div>
                            <input type="hidden" name="billable_default" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-3" for="<?= e($p) ?>-form-field-billable"><input type="checkbox" class="form-check-input mt-0" name="billable_default" value="yes" id="<?= e($p) ?>-form-field-billable" <?= $editing === null || $editing['billable_default'] ? 'checked' : '' ?>>Billable by default</label>
                        <?php elseif ($kind === 'provider'): ?>
                            <label class="form-label fs-12 text-muted" for="<?= e($p) ?>-form-field-mapping">Saved CSV mapping (JSON; slice 5 uses it on import)</label>
                            <textarea name="csv_mapping" id="<?= e($p) ?>-form-field-mapping" class="form-control font-monospace fs-12 mb-3" rows="3" placeholder='{"date": "Date", "cost": "Amount (USD)"}'><?= e($editing === null || $editing['csv_mapping'] === null ? '' : json_encode($editing['csv_mapping'], JSON_PRETTY_PRINT)) ?></textarea>
                        <?php endif; ?>
                        <label class="form-label fs-12 text-muted" for="<?= e($p) ?>-form-field-order">Order</label>
                        <input type="number" name="sort_order" id="<?= e($p) ?>-form-field-order" class="form-control btn-touch mb-3" min="0" max="1000" value="<?= (int) ($editing['sort_order'] ?? ($rows === [] ? 1 : max(array_column($rows, 'sort_order')) + 1)) ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary btn-touch w-100" id="<?= e($p) ?>-form-save-btn"><?= $editing === null ? 'Add' : 'Save' ?></button>
                    <?php if ($editing !== null): ?><?= hx_link($base, 'Cancel', 'btn btn-light btn-touch w-100 mt-2', 'id="' . $p . '-form-cancel"') ?><?php endif; ?>
                </form>
            </div></div>
        </div>
    </div>
</div>
