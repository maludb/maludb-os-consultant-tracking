<?php /** Import a provider's CSV usage export (screen `usage-import`), three steps on one page. Data: step (1 file, 2 mapping and preview), providers, engagements, recent, fields, errors, plan, preview, mapping, token, provider, engagement, fileName, byMonth, saveMapping, headers?, counts?, here */
$headers = $headers ?? []; $counts = $counts ?? null; $provId = $provider['provider_id'] ?? null; $m = $mapping ?? ['unit' => 'tokens', 'kind' => 'model_usage'];
$stepClass = static fn (int $n, int $cur): string => $n === $cur ? 'bg-primary' : ($n < $cur ? 'bg-success' : 'bg-soft-secondary text-secondary'); ?>
<?= view('shared/header.php', ['id' => 'usage-import', 'title' => 'Import usage', 'crumbs' => [['Home', '/'], ['AI usage and hosting', '/usage/'], ['Import', null]], 'back' => back_link() ?? ['/usage/', 'AI usage']]) ?>
<div class="main-content" id="usage-import-content">
    <div class="d-flex flex-wrap gap-2 mb-3" id="import-steps">
        <span class="badge <?= $stepClass(1, $step) ?>" id="import-step-1">1 The file</span><span class="badge <?= $stepClass(2, $step) ?>" id="import-step-2">2 The mapping and a preview</span><span class="badge <?= $stepClass(3, $step) ?>" id="import-step-3">3 Import</span>
    </div>
    <?php foreach ($errors as $err): ?><div class="alert alert-danger" role="alert" id="usage-import-error"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post" action="/usage/import.php" enctype="multipart/form-data" id="usage-import-form">
        <?= csrf_field() ?><input type="hidden" name="stash" value="<?= e($token) ?>">
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fs-12 text-muted" for="import-field-provider">Provider (its saved mapping is offered)</label>
            <select name="provider" id="import-field-provider" class="form-select btn-touch mb-3" required><option value="">Choose…</option><?php foreach ($providers as $p): ?><option value="<?= (int) $p['provider_id'] ?>" <?= $provId === $p['provider_id'] ? 'selected' : '' ?>><?= e($p['name']) ?><?= $p['csv_mapping'] !== null ? ' — mapping saved' : '' ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="import-field-engagement">Engagement the charges go on</label>
            <select name="engagement" id="import-field-engagement" class="form-select btn-touch mb-3" required><option value="">Choose…</option><?php foreach ($engagements as $g): ?><option value="<?= (int) $g['engagement_id'] ?>" <?= (int) $engagement === (int) $g['engagement_id'] ? 'selected' : '' ?>><?= e($g['number'] . ' · ' . $g['name'] . ' (' . $g['currency'] . ')') ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="import-field-file"><?= $token !== '' ? 'The file: ' . e($fileName) . ' (kept for this step; choose another to replace it)' : 'The provider\'s usage export (CSV)' ?></label>
            <input type="file" name="file" id="import-field-file" class="form-control btn-touch" accept=".csv,text/csv,text/plain" <?= $token === '' ? 'required' : '' ?>>
        </div></div>
        <?php if ($step >= 2 && $mapping !== null): ?>
        <div class="card mb-3" id="import-mapping"><div class="card-header"><h5 class="card-title mb-0">The mapping</h5></div><div class="card-body">
            <div class="fs-12 text-muted mb-2">Which column of the file holds each thing. The first three are required.</div>
            <div class="row g-2">
            <?php foreach ($fields as $k => $label): ?>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="import-field-map-<?= e($k) ?>"><?= e($label) ?></label>
                    <select name="map_<?= e($k) ?>" id="import-field-map-<?= e($k) ?>" class="form-select btn-touch"><option value="">— none —</option><?php foreach ($headers as $h): ?><option value="<?= e($h) ?>" <?= strtolower((string) ($m[$k] ?? '')) === strtolower($h) ? 'selected' : '' ?>><?= e($h) ?></option><?php endforeach; ?></select></div>
            <?php endforeach; ?>
                <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="import-field-map-unit">Unit of the quantity</label><select name="map_unit" id="import-field-map-unit" class="form-select btn-touch"><?php foreach (USAGE_UNITS as $k => $l): ?><option value="<?= e($k) ?>" <?= ($m['unit'] ?? 'tokens') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="import-field-map-kind">Kind</label><select name="map_kind" id="import-field-map-kind" class="form-select btn-touch"><option value="model_usage" <?= ($m['kind'] ?? '') === 'model_usage' ? 'selected' : '' ?>>Model usage</option><option value="api" <?= ($m['kind'] ?? '') === 'api' ? 'selected' : '' ?>>API calls</option></select></div>
            </div>
            <input type="hidden" name="group_by_month" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mt-3" for="import-field-group"><input type="checkbox" class="form-check-input mt-0" name="group_by_month" value="yes" id="import-field-group" <?= $byMonth ? 'checked' : '' ?>>One charge per month instead of one per row</label>
            <input type="hidden" name="save_mapping" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mt-2" for="import-field-save"><input type="checkbox" class="form-check-input mt-0" name="save_mapping" value="yes" id="import-field-save" <?= $saveMapping ? 'checked' : '' ?>>Remember this mapping for the provider</label>
        </div></div>
        <?php endif; ?>
        <?php if ($step >= 2 && $preview !== null): ?>
        <div class="card mb-3" id="import-preview"><div class="card-header"><h5 class="card-title mb-0">The first <?= count($preview['rows']) ?> of <?= (int) $preview['total'] ?> rows</h5></div>
            <div class="table-responsive"><table class="table table-sm mb-0 fs-12"><thead><tr><th>Line</th><th>Date</th><th class="text-end">Quantity</th><th class="text-end">Cost</th><th>Model</th></tr></thead><tbody>
                <?php foreach ($preview['rows'] as $r): ?><tr class="<?= $r['error'] !== null ? 'table-danger' : '' ?>"><td><?= (int) $r['line'] ?></td><td><?= e((string) $r['date']) ?></td><td class="text-end"><?= e((string) $r['quantity']) ?></td><td class="text-end"><?= e((string) $r['cost']) ?></td><td><?= e($r['error'] ?? $r['model']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div>
        <?php if ($counts !== null): ?>
        <div class="alert alert-<?= $counts['new'] > 0 ? 'info' : 'warning' ?> fs-12" id="import-counts"><strong id="import-count-new"><?= (int) $counts['new'] ?></strong> new charge<?= $counts['new'] === 1 ? '' : 's' ?> · <strong id="import-count-duplicate"><?= (int) $counts['duplicate'] ?></strong> duplicate<?= $counts['duplicate'] === 1 ? '' : 's' ?> skipped · <strong id="import-count-refused"><?= (int) $counts['refused'] ?></strong> refused<?= $preview['truncated'] ? ' · the file was cut at ' . CSV_MAX_ROWS . ' rows' : '' ?>.
            <?php foreach (array_slice(array_filter($plan, static fn (array $p): bool => $p['state'] === 'refused'), 0, 3) as $p): ?><div class="text-danger"><?= e($p['reason']) ?></div><?php endforeach; ?></div>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($step >= 2): ?>
            <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="import-run-btn" <?= $counts === null || $counts['new'] === 0 ? 'disabled' : '' ?>>Import as draft charges</button>
            <button type="submit" name="preview" value="1" class="btn btn-light btn-touch w-100 mb-2" id="import-preview-btn">Preview again</button>
        <?php else: ?>
            <button type="submit" name="preview" value="1" class="btn btn-primary btn-touch w-100 mb-2" id="import-preview-btn">Next: the mapping and a preview</button>
        <?php endif; ?>
        <?= hx_link('/usage/', 'Cancel', 'btn btn-light btn-touch w-100', 'id="import-cancel-link"') ?>
    </form>
    <?php if ($recent !== []): ?>
    <div class="card mt-3" id="import-recent"><div class="card-header"><h5 class="card-title mb-0">Recent imports</h5></div><div class="list-group list-group-flush">
        <?php foreach ($recent as $i): ?><div class="list-group-item fs-12 d-flex flex-wrap justify-content-between align-items-center gap-2" id="import-recent-<?= (int) $i['import_id'] ?>">
            <span><?= hx_link(with_back('/usage/?import=' . (int) $i['import_id'], $here), e($i['file_name'])) ?> <span class="text-muted"><?= e($i['provider_name']) ?> · <?= (int) $i['inserted'] ?> made, <?= (int) $i['skipped'] ?> skipped<?= $i['drafts'] > 0 ? ', ' . (int) $i['drafts'] . ' still draft' : '' ?></span></span>
            <?php if ($i['drafts'] > 0): ?><form method="post" action="/usage/import-delete.php" hx-post="/usage/import-delete.php" hx-target="#flash" hx-confirm="Remove the <?= (int) $i['drafts'] ?> draft charges of this import?"><?= csrf_field() ?><input type="hidden" name="import" value="<?= (int) $i['import_id'] ?>"><input type="hidden" name="return_to" value="/usage/import"><button type="submit" class="btn btn-light btn-touch" id="import-recent-<?= (int) $i['import_id'] ?>-delete-btn">Remove its drafts</button></form><?php endif; ?></div><?php endforeach; ?>
    </div></div>
    <?php endif; ?>
</div>
